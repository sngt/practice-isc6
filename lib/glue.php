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
    private static $instance;

    private $log_path;

    private $last_record_time;

    private function __construct($log_file_path = null) {
        $this->log_path = $log_file_path ? $log_file_path : ('/tmp/exectime_' . date('YmdH') . '.log');
        $this->last_record_time = self::now();
    }

    public static function instance() {
        if (empty(self::$instance)) {
                self::$instance = new self;
        }
        return self::$instance;
    }

    public function start() {
        $this->last_record_time = self::now();
    }

    public function record($label = '-') {
        $now = self::now();
        $elapsed = $now - $this->last_record_time;
        $this->last_record_time = $now;

        file_put_contents($this->log_path, "{$label} : {$elapsed}\n", FILE_APPEND);
    }

    private static function now() {
        list($usec, $sec) = explode(' ', microtime());
        return (float)($sec + $usec);
    }
}
