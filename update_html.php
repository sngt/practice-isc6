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

    $curl = curl_init('http://127.0.0.1:5000/htmlify');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['keyword' => $match['keyword']]));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    curl_close($curl);
}
