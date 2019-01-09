#!/usr/bin/php
<?php
declare(ticks = 1);

// libraries:
require_once __DIR__ . '/lib/phpMQTT.php';
require_once __DIR__ . '/lib/SensorFlow.php';
require_once __DIR__ . '/lib/Properties.php';

// main processor:
require_once __DIR__ . '/func_persist.php';
use techdada\phpMQTT;
use techdada\SensorFlow;


function sigint($sig) {
    echo "Signal $sig\n";
    global $running,$mqttc,$dbwriter;
    $running = false;
   // $mqttc->close();
   // $dbwriter->_close();
    $mqttc->close();
    SensorFlow::close();
   exit;
}

pcntl_signal(SIGTERM, 'sigint');
pcntl_signal(SIGINT, 'sigint');



// init:
function getArg($name) {
    global $args,$argv,$argc;
    if (!isset($args)) {
        $args=$argv;
        array_shift($args);
        foreach ($args as $arg) {
            $e=explode("=",$arg);
            if(count($e)==2)  $args[$e[0]]=$e[1];
            else              $args[$e[0]]=0;
        }
    }
    if (isset($args[$name])) return $args[$name];
    else return null;
}

// use config from other project..
$debug = false;
$running = true;
if (getArg('--debug') !== null) {
    $debug = true;
    echo "DEBUG active\n";
}

if (php_sapi_name() != 'cli') {
    die('CLI app only');
}

if (!Properties::init($_SERVER['HOME'].'/.config/phpMQTTbridge/config.properties')) {
    if (!Properties::init('config.properties')) {
        echo 'Could not load properties. Exiting.';
        exit(1);
    }
}

$topics = [];
$topicnr=0;
while ($topic = Properties::get('persisted_topic'.$topicnr)) {
    $topics[$topic] = [ 
        'qos' => 0,
        'function' => 'persist'
    ];
    $topicnr++;
}
$ca_path = Properties::get('ca_path','/etc/ssl/certs/ca-bundle.crt');
$tls = Properties::get('broker_tls','tlsv1.2');
if (!$tls) $ca_path = NULL; // reset, if not needed.

$mqttc = new phpMQTT(
        Properties::get('broker'), 
        Properties::get('broker_port',8883), 
        uniqid($_SERVER['HOSTNAME'].$_SERVER['USER']),
        $ca_path,
        $tls
    );


// connect: 
// .. to DB:
$db_dsn = 'mysql:'.Properties::get('persistence_dbhost').'=;dbname='.Properties::get('persistence_db');
if (!SensorFlow::start($db_dsn,Properties::get('persistence_dbuser'),Properties::get('persistence_dbpassword'))) {
    die('Cannot connect to database.');
}
// .. to MQTT:
$mqttc->connect_auto(true,null,Properties::get('broker_user'),Properties::get('broker_pass'));


// listen to everything but telematic and control requests:
$mqttc->subscribe(
    $topics
);

//loop forever:
exec('nohup '. __DIR__ .'/cleanup.php > /tmp/mqtt_persistor_cleanup.log 2>&1 &');
// periodically run cleanup, which removes entry older X hours (X = 24)
$next_cleanup = time() + 60 * 60 * Properties::get('cleanup_interval',2); // each 2h
while ($mqttc->proc() && $running) {
    echo ""; // Dummy echo - this surprisingly enables clean exit on CTRL+C..?
    if (!$running) break;
    if (time() > $next_cleanup) {
	$next_cleanup = time() + 60 * 60 * Properties::get('cleanup_interval',2); // each 2h
        exec('nohup '. __DIR__ .'/cleanup.php > /tmp/mqtt_persistor_cleanup.log 2>&1 &');
    }
}
$mqttc->close();
SensorFlow::close();
