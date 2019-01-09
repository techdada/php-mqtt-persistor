#!/usr/bin/php
<?php
declare(ticks = 1);

require_once __DIR__ . '/lib/SensorFlow.php';
require_once __DIR__ . '/lib/Properties.php';

use techdada\SensorFlow;

function sigint($sig) {
    echo "Signal $sig\n";
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

$debug = false;
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
$priority_topic_cnt = 0;
while ($topic = Properties::get('priority_topic'.$priority_topic_cnt)) {
    @list($topic,$retention) = explode(';',$topic);
    $topics[$topic] = $retention;
    $priority_topic_cnt++;
}

// connect:
// .. to DB:
$db_dsn = 'mysql:'.Properties::get('persistence_dbhost').'=;dbname='.Properties::get('persistence_db');
if (!SensorFlow::start($db_dsn,Properties::get('persistence_dbuser'),Properties::get('persistence_dbpassword'))) {
    die('Cannot connect to database.');
}


// remove all topics that are not priorized:
$sql = "DELETE FROM persistence WHERE 1 \n";
foreach ($topics as $topic=>$retention) {
    //TODO: retention time
    $topic = str_replace(['+','#'],['%','%'],$topic);
    $sql.= "AND topic NOT LIKE '{$topic}'\n";
}
// add retention of 24h: //TODO: make configurable
$sql .= "AND ( unix_timestamp(created_at) < ( unix_timestamp(now()) - ( 60 * 60 * 24 ) ) )";

if ($debug) echo $sql."\n";
SensorFlow::execute($sql, NULL);
echo SensorFlow::affectedRows()." row(s)s deleted\n";

SensorFlow::close();
