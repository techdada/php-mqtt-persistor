<?php

use techdada\SensorFlow;

function persist($topic,$value) {
    global $debug;
    if ($debug) echo "DatabaseWriter::write('current_state',['topic' => '{$topic}','value' => '{$value}']); \n";
    
    if (!SensorFlow::write('current_state',[
        'topic' => $topic,
        'value' => $value
    ])) {
        echo "FAILED TO INSERT {$topic} = {$value} TO DB!\n";
    }
    
}