<?php
namespace Isuda\Web;

use Slim\Http\Request;
use Slim\Http\Response;
use PDO;
use PDOWrapper;
use StopWatch;

function config($key) {
    static $conf;
    if ($conf === null) {
        $conf = [
            'dsn'           => $_ENV['ISUDA_DSN']         ?? 'dbi:mysql:db=isuda',
            'db_user'       => $_ENV['ISUDA_DB_USER']     ?? 'isucon',
            'db_password'   => $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            // 'isutar_origin' => $_ENV['ISUTAR_ORIGIN']     ?? 'http://localhost:5001',
            'isupam_origin' => $_ENV['ISUPAM_ORIGIN']     ?? 'http://localhost:5050',
        ];
    }

    if (empty($conf[$key])) {
        exit("config value of $key undefined");
    }
    return $conf[$key];
}

$container = new class extends \Slim\Container {
    public $dbh;
    public function __construct() {
        parent::__construct();

        $this->dbh = new PDOWrapper(new PDO(
            $_ENV['ISUDA_DSN'],
            $_ENV['ISUDA_DB_USER'] ?? 'isucon',
            $_ENV['ISUDA_DB_PASSWORD'] ?? 'isucon',
            [ PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4" ]
        ));
    }

    public function debug_log($message) {
        file_put_contents('/home/isucon/.local/php/var/log/debug.log', "{$message}\n", FILE_APPEND);
    }

    public function lock($key) {
        static $INTERVAL = 10;
        static $TRIAL_TIMES = 10000;

        $count = 0;
        while (apcu_add("{$key}_lock", 1, 1) !== true) {
            if (++$count > $TRIAL_TIMES) {
                throw new \Exception('dead lock');
            }
            usleep($INTERVAL);
        }
    }

    public function unlock($key) {
        apcu_delete("{$key}_lock");
    }

    public function get_entry_index() {
        return apcu_fetch('entry_index') ?: [];
    }

    public function get_entry($keyword) {
        return apcu_fetch("entry_{$keyword}") ?: null;
    }

    public function get_all_keyword_regexps() {
        // $keywords = $this->dbh->select_all(
        //     'SELECT * FROM entry ORDER BY CHARACTER_LENGTH(keyword) DESC'
        // );
        if ($cached = apcu_fetch('all_keyword_regexp_list')) {
            return $cached;
        }

        $regexps = [];
        $list = $this->dbh->select_all(
            'SELECT keyword FROM entry ORDER BY keyword_length DESC'
        );
        for ($i = 0; !empty($kwtmp = array_slice($list, 500 * $i, 500)); $i++) {
            $quated = array_map(function ($data) {
                return quotemeta($data['keyword']);
            }, $kwtmp);
            $re = implode('|', $quated);
            $regexps[] = "/($re)/";
        }

        apcu_store('all_keyword_regexp_list', $regexps);
        return $regexps;
    }

    public function set_entry($keyword, $description) {
        $this->lock('entry');
        try {
            $index = $this->get_entry_index();
            if (isset($index[$keyword])) {
                unset($index[$keyword]);
            }
            $index[$keyword] = 1;
            apcu_store('entry_index', $index);

            $entry = $this->get_entry($keyword);
            if (empty($entry)) {
                $entry = [
                    'keyword' => $keyword,
                    'html' => $this->htmlify($description),
                    'stars' => [],
                ];
            }
            apcu_store("entry_{$keyword}", $entry);
        } finally {
            $this->unlock('entry');
        }
    }

    public function delete_entry($keyword) {
        $this->lock('entry');
        try {
            $index = $this->get_entry_index();
            if (empty($index($keyword))) {
                return false;
            }

            unset($index[$keyword]);
            apcu_store('entry_index', $index);
            return true;
        } finally {
            $this->unlock('entry');
        }
    }

    public function htmlify($content) {
        if (empty($content)) {
            return '';
        }

        $kw2sha = [];

        // NOTE: avoid pcre limitation "regular expression is too large at offset"
        // for ($i = 0; !empty($kwtmp = array_slice($keywords, 500 * $i, 500)); $i++) {
        //     $re = implode('|', array_map(function ($keyword) { return quotemeta($keyword['keyword']); }, $kwtmp));
        //     preg_replace_callback("/($re)/", function ($m) use (&$kw2sha) {
        //         $kw = $m[1];
        //         return $kw2sha[$kw] = "isuda_" . sha1($kw);
        //     }, $content);
        // }
        foreach ($this->get_all_keyword_regexps() as $regexp) {
            preg_replace_callback($regexp, function ($m) use (&$kw2sha) {
                $kw = $m[1];
                return $kw2sha[$kw] = "isuda_" . sha1($kw);
            }, $content);
        }
        $content = strtr($content, $kw2sha);
        $content = html_escape($content);
        foreach ($kw2sha as $kw => $hash) {
            $url = '/keyword/' . rawurlencode($kw);
            $link = sprintf('<a href="%s">%s</a>', $url, html_escape($kw));

            $content = preg_replace("/{$hash}/", $link, $content);
        }
        return nl2br($content, true);
    }

    public function add_star($keyword, $user_name) {
        $cache_key = "entry_{$keyword}";
        $this->lock($cache_key);
        try {
            $entry = $this->get_entry($keyword);
            if (empty($entry)) {
                return false;
            }
            $entry['stars'][] = ['keyword' => $keyword, 'user_name' => $user_name];
            apcu_store($cache_key, $entry);
            return true;
        } finally {
            $this->unlock($cache_key);
        }
    }

    public function get_user($id) {
        return apcu_fetch("user_id_{$id}") ?: null;
    }

    public function get_user_by_name($name) {
        return apcu_fetch("user_name_{$name}") ?: null;
    }

    public function initialize() {
        if (apcu_add('initializing', 1, 3)) {
            ini_set('memory_limit', '1G');
            apcu_clear_cache();
            $this->initialize_users();
            $this->initialize_entries();
        }
    }

    private function initialize_users() {
        $users = $this->dbh->select_all('SELECT id, name, salt, password FROM user ORDER BY id');
        foreach ($users as $user) {
            apcu_store("user_id_{$user['id']}", $user);
            apcu_store("user_name_{$user['name']}", $user);
        }
    }

    private function initialize_entries() {
        static $INITIAL_MAX_ID = 7101;

        $this->lock('entry');
        try {
            $index = [];
            $entries = $this->dbh->select_all('SELECT id, keyword, html FROM entry ORDER BY id');
            foreach ($entries as $entry) {
                if ($entry['id'] <= $INITIAL_MAX_ID) {
                    $index[$entry['keyword']] = 1;
                }
                unset($entry['id']);

                $entry['stars'] = [];
                apcu_store("entry_{$entry['keyword']}", $entry);
            }
            apcu_store('entry_index', $index);
        } finally {
            $this->unlock('entry');
        }
    }
};
$container['view'] = function ($container) {
    $view = new \Slim\Views\Twig($_ENV['PHP_TEMPLATE_PATH'], []);
    $view->addExtension(new \Slim\Views\TwigExtension(
        $container['router'],
        $container['request']->getUri()
    ));
    return $view;
};
$container['stash'] = new \Pimple\Container;
$app = new \Slim\App($container);

$mw = [];
// compatible filter 'set_name'
$mw['set_name'] = function ($req, $c, $next) {
    $user_id = $_SESSION['user_id'] ?? null;
    if (isset($user_id)) {
        $this->get('stash')['user_id'] = $user_id;
        // $this->get('stash')['user_name'] = $this->dbh->select_one(
        //     'SELECT name FROM user WHERE id = ?'
        //     , $user_id);
        $this->get('stash')['user_name'] = $this->get_user($user_id)['name'];
        if (!isset($this->get('stash')['user_name'])) {
            return $c->withStatus(403);
        }
    }
    return $next($req, $c);
};

$mw['authenticate'] = function ($req, $c, $next) {
    if (!isset($this->get('stash')['user_id'])) {
        return $c->withStatus(403);
    }
    return $next($req, $c);
};

$app->get('/initialize', function (Request $req, Response $c) {
    // $this->dbh->query(
    //     'DELETE FROM entry WHERE id > 7101'
    // );
    $this->initialize();
    // $origin = config('isutar_origin');
    // $url = "$origin/initialize";
    // file_get_contents($url);
    return render_json($c, [
        'result' => 'ok',
    ]);
});

$app->get('/', function (Request $req, Response $c) {
    static $PER_PAGE = 10;
    $page = $req->getQueryParams()['page'] ?? 1;

    // $offset = $PER_PAGE * ($page-1);
    // $entries = $this->dbh->select_all(
    //     'SELECT * FROM entry '.
    //     'ORDER BY updated_at DESC '.
    //     "LIMIT $PER_PAGE ".
    //     "OFFSET $offset"
    // );

    $all_keywords = array_keys($this->get_entry_index());
    $total_entries = count($all_keywords);
    $offset = $total_entries - ($page * $PER_PAGE);

    $entries = array_map(function ($keyword) {
        return $this->get_entry($keyword);
    }, array_slice($all_keywords, $offset, $PER_PAGE));

    // foreach ($entries as &$entry) {
    //     // $entry['html']  = $this->htmlify($entry['description']);
    //     // $entry['stars'] = $this->load_stars($keyword);
    // }
    // unset($entry);

    // $total_entries = $this->dbh->select_one(
    //     'SELECT COUNT(*) FROM entry'
    // );
    $last_page = ceil($total_entries / $PER_PAGE);
    $pages = range(max(1, $page-5), min($last_page, $page+5));
    $this->view->render($c, 'index.twig', [ 'entries' => $entries, 'page' => $page, 'last_page' => $last_page, 'pages' => $pages, 'stash' => $this->get('stash') ]);
})->add($mw['set_name'])->setName('/');

$app->get('/robots.txt', function (Request $req, Response $c) {
    return $c->withStatus(404);
});

$app->post('/keyword', function (Request $req, Response $c) {
    $keyword = $req->getParsedBody()['keyword'];
    if (!isset($keyword)) {
        return $c->withStatus(400)->write("'keyword' required");
    }
    $user_id = $this->get('stash')['user_id'];
    $description = $req->getParsedBody()['description'];

    if (is_spam_contents($description) || is_spam_contents($keyword)) {
        return $c->withStatus(400)->write('SPAM!');
    }
    $this->set_entry($keyword, $description);

    $entry_save_delegation = [
        'user_id' => $user_id,
        'description' => $description,
    ];
    apcu_store("entry_delegation_{$keyword}", $entry_save_delegation);

    $delegation_command = '/usr/bin/curl --silent -X POST http://127.0.0.1/save-keyword-internal'
        . ' --data-urlencode \'keyword=' . str_replace("'", '\'', $keyword) .'\' > /dev/null &';
    exec($delegation_command);

    return $c->withRedirect('/');
})->add($mw['authenticate'])->add($mw['set_name']);

$app->post('/save-keyword-internal', function (Request $req, Response $c) {
    $keyword = $req->getParams()['keyword'];
    if (empty($keyword)) {
        return $c->withStatus(400)->write("'keyword' required");
    }

    $data = apcu_fetch("entry_delegation_{$keyword}");
    if (empty($data)) {
        return $c->withStatus(400)->write("delegation data not found: {$keyword}");
    }

    $user_id = $data['user_id'];
    $description = $data['description'];

    $entry = $this->dbh->select_row('SELECT id, description FROM entry WHERE keyword = ?', $keyword);
    if ($entry) {
        // if ($entry['description'] === $description) {
        //     return render_json($c, '');
        // }
        // $this->dbh->query(
        //     'UPDATE entry SET description = ?, html = ?, updated_at = NOW() WHERE id = ?'
        // , $description, $this->htmlify($description), $entry['id']);
        return render_json($c, '');
    }

    $cached = apcu_fetch("entry_{$keyword}");
    $html = $cached ? $cached['html'] : $this->htmlify($description);
    $this->dbh->query(
        'INSERT INTO entry (author_id, keyword, keyword_length, description, html, created_at, updated_at)'
        .' VALUES (?, ?, CHAR_LENGTH(keyword), ?, ?, NOW(), NOW())'
    , $user_id, $keyword, $description, $html);
    apcu_delete('all_keyword_regexp_list');

    // $this->dbh->query(
    //     'INSERT INTO entry (author_id, keyword, description, created_at, updated_at)'
    //     .' VALUES (?, ?, ?, NOW(), NOW())'
    //     .' ON DUPLICATE KEY UPDATE'
    //     .' author_id = ?, keyword = ?, description = ?, updated_at = NOW()'
    // , $user_id, $keyword, $description, $user_id, $keyword, $description);

    return render_json($c, '');
});

$app->get('/register', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'register', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/register');

// $app->post('/register', function (Request $req, Response $c) {
//     $name = $req->getParsedBody()['name'];
//     $pw   = $req->getParsedBody()['password'];
//     if ($name === '' || $pw === '') {
//         return $c->withStatus(400);
//     }
//     $user_id = register($this->dbh, $name, $pw);
//
//     $_SESSION['user_id'] = $user_id;
//     return $c->withRedirect('/');
// });
//
// function register($dbh, $user, $pass) {
//     $salt = random_string('....................');
//     $dbh->query(
//         'INSERT INTO user (name, salt, password, created_at)'
//         .' VALUES (?, ?, ?, NOW())'
//     , $user, $salt, sha1($salt . $pass));
//
//     return $dbh->last_insert_id();
// }

$app->get('/login', function (Request $req, Response $c) {
    return $this->view->render($c, 'authenticate.twig', [
        'action' => 'login', 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name'])->setName('/login');

$app->post('/login', function (Request $req, Response $c) {
    $name = $req->getParsedBody()['name'];
    // $row = $this->dbh->select_row(
    //     'SELECT * FROM user'
    //     . ' WHERE name = ?'
    // , $name);
    $row = $this->get_user_by_name($name);
    if (!$row || $row['password'] !== sha1($row['salt'].$req->getParsedBody()['password'])) {
        return $c->withStatus(403);
    }

    $_SESSION['user_id'] = $row['id'];
    return $c->withRedirect('/');
});

$app->get('/logout', function (Request $req, Response $c) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-60, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    return $c->withRedirect('/');
});

$app->get('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getAttribute('keyword');
    if ($keyword === null) return $c->withStatus(400);

    // $entry = $this->dbh->select_row(
    //     'SELECT * FROM entry'
    //     .' WHERE keyword = ?'
    // , $keyword);
    $entry = $this->get_entry($keyword);
    if (empty($entry)) return $c->withStatus(404);
    // $entry['html'] = $this->htmlify($entry['description']);
    // $entry['stars'] = $this->load_stars($entry['keyword']);

    return $this->view->render($c, 'keyword.twig', [
        'entry' => $entry, 'stash' => $this->get('stash')
    ]);
})->add($mw['set_name']);

$app->post('/keyword/{keyword}', function (Request $req, Response $c) {
    $keyword = $req->getAttribute('keyword');
    if ($keyword === null) return $c->withStatus(400);
    $delete = $req->getParsedBody()['delete'];
    if ($delete === null) return $c->withStatus(400);

    // $entry = $this->dbh->select_row(
    //     'SELECT * FROM entry'
    //     .' WHERE keyword = ?'
    // , $keyword);
    // if (empty($entry)) return $c->withStatus(404);
    //
    // $this->dbh->query('DELETE FROM entry WHERE keyword = ?', $keyword);
    if ($this->delete_entry($keyword) !== true) {
        return $c->withStatus(404);
    }
    return $c->withRedirect('/');
})->add($mw['authenticate'])->add($mw['set_name']);

function is_spam_contents($content) {
    $ua = new \GuzzleHttp\Client;
    $res = $ua->request('POST', config('isupam_origin'), [
        'form_params' => ['content' => $content]
    ])->getBody();
    $data = json_decode($res, true);
    return !$data['valid'];
}

// From isutar
// $app->get('/stars', function (Request $req, Response $c) {
//     $keyword = $req->getParams()['keyword'];
//     return render_json($c, [
//         'stars' => $this->load_stars($keyword),
//     ]);
// });
$app->post('/stars', function (Request $req, Response $c) {
    $params = $req->getParams();
    if ($this->add_star($params['keyword'], $params['user'])) {
        return render_json($c, ['result' => 'ok']);
    } else {
        return $c->withStatus(404);
    }
});

$app->post('/htmlify', function (Request $req, Response $c) {
    $keyword = $req->getParams()['keyword'];
    if (empty($keyword)) {
        return $c->withStatus(400);
    }
    $entry = $this->dbh->select_row('SELECT id, description FROM entry WHERE keyword = ?', $keyword);
    if (empty($entry)) {
        return $c->withStatus(400);
    }
    $this->dbh->query(
        'UPDATE entry SET html = ?, updated_at = NOW() WHERE id = ?'
    , $this->htmlify($entry['description']), $entry['id']);
    return render_json($c, 'ok');
});

$app->run();
