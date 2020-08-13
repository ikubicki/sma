<?php

$config = [
    'url' => 'https://192.168.0.3',
    'pass' => 'AdminPassWord!23',
];

require __DIR__ . '/vendor/autoload.php';

$smaClient = new Irekk\SMA\Client($config);
$metrics = $smaClient->getMetrics();
var_dump($metrics);