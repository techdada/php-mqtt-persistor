<?php
require __DIR__ . '/../lib/phpMQTT.php';
require __DIR__ . '/../lib/Properties.php';
$properties = ['mqtt_qos' => $argv[2]];
$qos = Properties::getQos();
$c = new techdada\phpMQTT('127.0.0.1', (int)$argv[1], 'qos-test', null, null);
$c->topics = ['test/+' => ['qos' => $qos, 'function' => function ($t, $v) {
    if ($v === 'fail') throw new PDOException('injected database failure');
    echo "$t=$v\n"; flush();
}]];
$end = microtime(true) + 30;
while (microtime(true) < $end) $c->proc();
