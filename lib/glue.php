<?php
class PDOWrapper {
    private $pdo;
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->pdo->query("SET SESSION sql_mode='TRADITIONAL,NO_AUTO_VALUE_ON_ZERO,ONLY_FULL_GROUP_BY'");
    }
    public function select_one($query, ...$params) {
        $ps = $this->pdo->prepare($query);
        $ps->execute($params);
        $row = $ps->fetch(PDO::FETCH_NUM);
        $ps->closeCursor();
        return $row[0];
    }

    public function select_all($query, ...$params) {
        $ps = $this->pdo->prepare($query);
        $ps->execute($params);
        $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    public function select_row($query, ...$params) {
        $ps = $this->pdo->prepare($query);
        $ps->execute($params);
        $row = $ps->fetch(PDO::FETCH_ASSOC);
        $ps->closeCursor();
        return $row;
    }

    public function query($query, ...$params) {
        return $this->select_all($query, ...$params);
    }

    public function last_insert_id() {
        return $this->pdo->lastInsertId();
    }
}

function html_escape($str) {
    return htmlspecialchars($str, ENT_COMPAT | ENT_HTML401, 'UTF-8');
}

function random_string($pattern) {
    $len = strlen($pattern);
    $h = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($i = 33; $i <= 47; $i++) { $h .= chr($i); }
    for ($i = 58; $i <= 64; $i++) { $h .= chr($i); }
    for ($i = 91; $i <= 96; $i++) { $h .= chr($i); }
    for ($i = 123; $i <= 126; $i++) { $h .= chr($i); }

    $hlen = strlen($h);
    $str = '';
    for ($i = 0; $i < $len; $i++) {
        $str .= $h[mt_rand(0, $hlen)];
    }
    return $str;
}

function render_json(\Slim\Http\Response $r, $object) {
  $r->getBody()->write(json_encode($object));
  return $r;
}

class StopWatch {
    private $log_path;
    private $output_cycle;
    private $last_record_time;

    private function __construct($output_cycle) {
        $this->output_cycle = $output_cycle;

        $this->last_record_time = self::now();
        $this->log_path = '/tmp/exectime_' . date('YmdH') . '.log';
    }

    public static function start($output_cycle = 0) {
        return new self($output_cycle);
    }

    public function record($label = '-') {
        $now = self::now();
        $elapsed = $now - $this->last_record_time;
        $this->last_record_time = $now;

        if ($this->output_cycle > 0 && ((int)$now) % $this->output_cycle !== 0) {
            return;
        }
        file_put_contents($this->log_path, "{$label} : {$elapsed}\n", FILE_APPEND);
    }

    private static function now() {
        list($usec, $sec) = explode(' ', microtime());
        return (float)($sec + $usec);
    }
}

class LockUtil {
    const CHECK_INTERVAL = 10;
    const TRIAL_TIMES = 10000;

    public function lock($name) {
        $count = 0;
        $key = "{$name}_lock";
        while (apcu_add($key, 1, 1) !== true) {
            if (++$count > self::TRIAL_TIMES) {
                throw new \Exception('dead lock');
            }
            usleep(self::CHECK_INTERVAL);
        }
    }

    public function unlock($name) {
        apcu_delete("{$name}_lock");
    }
}

class CacheRepository {
    private static $data = [];

    public static function get($namespace, $id = null) {
        $key = self::key($namespace, $id);
        if (array_key_exists($key, self::$data)) {
            return self::$data[$key];
        }
        if (apcu_exists($key)) {
            return self::$data[$key] = apcu_fetch($key);
        }
        return null;
    }

    public static function save($value, $namespace, $id = null) {
        $key = self::key($namespace, $id);
        apcu_store($key, $value);
        self::$data[$key] = $value;
    }

    public static function delete($namespace, $id = null) {
        $key = self::key($namespace, $id);
        unset(self::$data[$key]);
        apcu_delete($key);
    }

    private static function key($namespace, $id = null) {
        if ($id === null) {
            return $namespace;
        }
        return "{$namespace}_{$id}";
    }
}
