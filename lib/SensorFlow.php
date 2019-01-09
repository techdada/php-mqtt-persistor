<?php

namespace techdada;
use \PDO;
use \PDOException;
/**
 * SensorFlow class for retrieving MQTT data out of the persisted sensor flow.
 *
 * Version 1.0 Initial
 *
 *
 * Version Changelog:
 *
 * 1.0 2019-01-05 Initial
 *
 * @author dada
 *
 */
class SensorFlow {
    protected $connection = NULL;
    protected static $me;
    public $affectedRows;
    
    public function __construct($dburl,$user,$password) {
        $type = explode(':',$dburl)[0];
        if ( $type=='mysql' ) {
            $this->connection=new PDO(
                $dburl,
                $user,
                $password
                );
        } elseif ($type == 'sqlite') {
            $this->connection=new PDO(
                $dburl
                );
        } else {
            die('unsupported DB');
        }
        //$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING); //TODO: deactive database error messages for production use
        //	date_default_timezone_set('UTC'); //enable this, if you would like to use UTC whereever possible, otherwise use server�s default. recommended.
    }
    
    public static function affectedRows() {
        return self::$me->affectedRows;
    }
    
    public function _close() {
        $this->connection = null;
        return true;
    }
    
    public static function close() {
        return self::$me->_close();
    }
    
    public static function execute($sql,$valuearray) {
        return self::$me->_execute($sql, $valuearray);
    }
    
    public function _execute($sql,$valuearray) {
        try {
            
            if (!$stmt = $this->connection->prepare($sql)) {
                echo "Could not prepare Statement\n";
                return false;
            }
            
            if ($stmt->execute($valuearray)) {
                $this->affectedRows = $stmt->rowCount();
                return $stmt;
            } else {
                global $debug;
                if ($debug) {
                    var_dump( $stmt->errorInfo() );
                    echo $sql."\n";
                }
                return false;
            }
        } catch (PDOException $pe) {
            echo $pe->getMessage();
            return false;
        }
    }
    
    
    public static function getDatasets($topics,$from=0,$to=0) {
        return self::$me->_getDatasets($topics,$from,$to);
    }
    /**
     * generates a dataset array from topic search patterns:
     *
     * [
     *  'data' => [
     *      'topic1' => [ x1 => y1, x2 => y2, ...],
     *      'topic2' => [ x1 => y1, x2 => y2, ...]
     *      ],
     *  'fromts' => 1234567800, //unix ts of selection start
     *  'tots' =>   1234567899  //unix ts of selection end
     * ]
     *
     * @param array $topics
     * @param number $from
     * @param number $to
     * @return mixed
     */
    public function _getDatasets($topics,$from=0,$to=0) {
        $from = intval($from);
        $to = intval($to);
        $executor = [];
        $sql = "SELECT topic,value,created_at FROM persistence\n
				WHERE ( 0";
        foreach ($topics as $ix => $topic) {
            $sql .= " OR topic like :t{$ix} ";
            $executor[':t'.$ix] = $topic;
        }
        $sql .= ") \n";
        if ($from) {
            $sql.= " AND unix_timestamp(created_at) > :fromts ";
            $executor[':fromts'] = $from;
        }
        if ($to) {
            $sql.= " AND unix_timestamp(created_at) < :tots ";
            $executor[':tots'] = $to;
        }
        
        $sql .= " ORDER BY topic,created_at\n";
        if ($result = $this->_execute($sql, $executor)) {
            $dataset = [
                'data' => [],
                'fromts' => $from,
                'tots' => $to,
                'sql' => $sql
                //'labels'  => [],
            ];
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $dataset['data'][$row['topic']][$row['created_at']] = $row['value'];
            }
            //$dataset['labels'][] = $t;
            return $dataset;
        }
    }
    
    public static function getSensorValues($topics,$limit=0) {
        return self::$me->_getSensorValues($topics,$limit);
    }
    
    public function _getSensorValues($topics,$limit = 0) {
        $response = [];
        $exec = [];
        $sql = "SELECT topic,value,edited_at FROM current_state WHERE 0 ";
        $wc = 0;
        foreach ($topics as $topic) {
            
            $sql.= "OR topic like :topic".$wc." ";
            $exec[':topic'.$wc] = $topic;
            
            $wc++;
        }
        if ($limit) $sql .=" LIMIT {$limit}"; // max 15 sensor values
        
        
        $stmt = $this->connection->prepare($sql);
        if ($stmt->execute($exec)) {
            while ($result = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $response[] = [
                    'topic' => $result['topic'],
                    'value' => $result['value'],
                    'edited_at' => $result['edited_at']
                ];
            }
        }
        return $response;
    }
    
    public static function getSensorValue($topic) {
        return self::$me->_getSensorValue($topic);
    }
    
    public function _getSensorValue($topic) {
        $exec = [':topic' => $topic ];
        $sql = "SELECT topic,value,edited_at FROM current_state WHERE topic = :topic";
        
        $stmt = $this->connection->prepare($sql);
        if ($stmt->execute($exec)) {
            while ($result = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                return [
                    'topic' => $result['topic'],
                    'value' => $result['value'],
                    'edited_at' => $result['edited_at']
                ];
            }
        }
        return "";
    }
    
    
    public static function start($dburl,$user,$password,$type='mysql') {
        if (!self::$me) self::$me = new SensorFlow($dburl,$user,$password,$type);
        return self::$me;
    }
    
    
    /**
     * Insert on duplicate key update!
     *
     * @param string $table
     * @param array $kvpairs
     */
    public function writeToTable($table, $kvpairs) {
        $sql  = "INSERT INTO {$table} (";
        $sql .=  join(',',array_keys($kvpairs));
        $sql .= ") VALUES (";
        $sql .= ":".join(',:',array_keys($kvpairs));
        $sql .= ") ";
        $sql .= "ON DUPLICATE KEY UPDATE  ";
        foreach (array_keys($kvpairs) as $key) {
            $sql .= " {$key} = :{$key},";
        }
        $sql[strlen($sql)-1] = ' '; // remove trailing ','
        $sql .= "";
        try {
            
            if (!$stmt = $this->connection->prepare($sql)) {
                echo "Could not prepare Statement\n";
                return false;
            }
            $executor = [];
            foreach ($kvpairs as $k => $v) {
                $executor[':'.$k] = $v;
            }
            $stmt->execute($executor);
            //             if (!$stmt->execute($executor)) {
            //                 var_dump( $stmt->errorInfo() );
            //                 echo $sql."\n";
            //                 return false;
            //             }
        } catch (PDOException $pe) {
            echo $pe->getMessage();
            return false;
        }
        return true;
        
    }
    
    public static function write($table, $kvpairs) {
        return self::$me->writeToTable($table, $kvpairs);
    }
}
