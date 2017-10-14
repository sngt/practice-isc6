#!/usr/bin/env php
<?php

$result = json_decode(trim(fgets(STDIN)), true);
if (empty($result) || is_array($result['messages']) !== true) {
    throw new Exception('不正な入力');
}

foreach ($result['messages'] as $message) {
    if (preg_match('/.+からのリンクがありません \(GET \/keyword\/(?P<keyword>.+)\)/', $message, $match) !== 1) {
        continue;
    }
    if (empty($match['keyword'])) {
        continue;
    }

    echo "{$match['keyword']}\n";
}
