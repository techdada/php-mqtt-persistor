<?php
require __DIR__ . '/../lib/phpMQTT.php';
$c = new techdada\phpMQTT('127.0.0.1', (int)$argv[1], 'reconnect-test', null, null);
$c->setCredentials('test', 'password');
$c->topics = ['test/+' => ['qos' => 0, 'function' => function ($t, $v) {
    echo "$t=$v\n"; flush();
}]];
// Shorten keepalive for the fault-injection test only.
$p = new ReflectionProperty($c, 'keepalive');
$p->setAccessible(true); $p->setValue($c, 1);
$end = microtime(true) + 80;
while (microtime(true) < $end) $c->proc();
$c->close();
