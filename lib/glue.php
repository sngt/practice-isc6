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

class Tree {
    public $root;
    // public $node_map = [];

    function __construct() {
        $this->root = new Node($this);
    }

    public function add($word) {
        $this->root->append($word);
    }

    public function delete($word) {
        $this->root->remove($word);
    }

    public function replace_all($text, $replace_func) {
        $replaced = '';

        $left = $text;
        while ($left) {
            while ($matched = $this->root->try_match($left)) {
                $replaced .= $replace_func($matched);
                $left = mb_substr($left, mb_strlen($matched));
            }

            list($discarded, $left) = $this->root->discard_not_eligible($left);
            $replaced .= $discarded;
        }
        return $replaced;
    }
}

class Node {
    public $tree;
    public $sequence;
    public $children = [];
    public $is_present = false;
    public $failure_link = '';

    public function __construct(Tree $tree, $sequence = '') {
        $this->tree = $tree;
        $this->sequence = $sequence;
        // $tree->node_map[$sequence] = $this;
    }

    public function append($word) {
        if (empty($word)) {
            $this->is_present = true;
            return;
        }

        list($next_char, $left_word) = self::split_first_char($word);
        if (empty($this->children[$next_char])) {
            $this->children[$next_char] = new Node($this->tree, $this->sequence . $next_char);
        }

        $this->children[$next_char]->append($left_word);
    }

    public function remove($word) {
        if (empty($word)) {
            $this->is_present = false;
            return;
        }

        list($next_char, $left_word) = self::split_first_char($word);
        if (empty($this->children[$next_char])) {
            return;
        }

        if ($this->children[$next_char]->children) {
            $this->children[$next_char]->remove($left_word);
        } else {
            // unset($this->tree->node_map[$this->children[$next_char]->sequence]);
            unset($this->children[$next_char]);
        }
    }

    public function try_match($text) {
        list($char, $left) = self::split_first_char($text);

        if (empty($this->children[$char])) {
            return '';
        }

        $child = &$this->children[$char];

        if ($child->children) {
            $matched = $child->try_match($left);
            if ($matched) {
                return $char . $matched;
            }
        }

        if ($child->is_present) {
            return $char;
        }
        return '';
    }

    public function discard_not_eligible($text) {
        if (empty($text)) {
            return ['', $text];
        }
        list($not_eligible, $target) = self::split_first_char($text);
        while ($target) {
            list($char, $left) = self::split_first_char($target);
            if (isset($this->children[$char])) {
                return [$not_eligible, $target];
            }
            $not_eligible .= $char;
            $target = $left;
        }
        return [$not_eligible, $target];
    }

    public static function split_first_char($word) {
        $length = mb_strlen($word);
        if ($length <= 1) {
            return [$word, ''];
        }
        return [mb_substr($word, 0, 1), mb_substr($word, 1, $length)];
    }
}

function debug_log($message) {
    file_put_contents('/home/isucon/.local/php/var/log/debug.log', "{$message}\n", FILE_APPEND);
}
