<?php
$path = __DIR__ . '/logs/test.log';
if (!is_dir(dirname($path))) {
    mkdir(dirname($path), 0775, true);
}
$result = @file_put_contents($path, date('c') . " - test\n", FILE_APPEND);
var_dump($path, $result);
