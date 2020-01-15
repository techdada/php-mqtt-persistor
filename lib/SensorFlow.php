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
    protected $dburl,$dbuser,$dbpassword,$dbtype;
    
    public function __construct($dburl,$user,$password) {
        $this->dburl = $dburl;
        $this->dbuser = $user;
        $this->dbpassword = $password;
        $this->dbtype = explode(':',$this->dburl)[0];
        
        $this->connectDB();
        
        //$this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING); //TODO: deactive database error messages for production use
        //	date_default_timezone_set('UTC'); //enable this, if you would like to use UTC whereever possible, otherwise use server�s default. recommended.
    }
    
    protected function connectDB() {
        if ( $this->dbtype=='mysql' ) {
            $this->connection=new PDO(
                $this->dburl,
                $this->dbuser,
                $this->dbpassword
                );
        } elseif ($this->dbtype == 'sqlite') {
            $this->connection=new PDO(
                $this->dburl
                );
        } else {
            die('unsupported DB');
        }
        return $this->connection;
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
    
    /**
     * 
     * @return NULL|\PDO
     */
    protected function getConnection() {
        if ($this->connection === null) {
            return $this->connectDB();
        } else return $this->connection;
    }
    
    public function _execute($sql,$valuearray) {
        try {
            
            if (!$stmt = $this->getConnection()->prepare($sql)) {
                echo "Could not prepare Statement\n";
                return false;
            }
            
            if ($stmt->execute($valuearray)) {
                $this->affectedRows = $stmt->rowCount();
                return $stmt;
            } else {
                global $debug;
                if ($debug) {
                    $ei = $stmt->errorInfo();
		    if ($ei[0] == "HY000") {
			// reset connection to reconnect to DB:
			$this->connection = null;
		    }
		    echo "{$ei[3]} ({$ei[0]}): \n";
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
        $sqp = "SELECT topic,value,created_at FROM persistence\n
				WHERE ( 0";
        $sqc = " SELECT topic,value,edited_at as created_at FROM current_state
                     WHERE ( 0";
              
        foreach ($topics as $ix => $topic) {
            $topic = str_replace(['+','#'],['%','%'],$topic);
            $sqp .= " OR topic like :t{$ix} ";
            $sqc .= " OR topic like :t{$ix} ";
            $executor[':t'.$ix] = $topic;
        }
        $sqp .= ") \n";
        $sqc .= ") \n";
        if ($from) {
            $sqp.= " AND unix_timestamp(created_at) > :fromts ";
            $sqc.= " AND unix_timestamp(edited_at) > :fromts ";
            $executor[':fromts'] = $from;
        }
        if ($to) {
            $sqp.= " AND unix_timestamp(created_at) < :tots ";
            $sqc.= " AND unix_timestamp(edited_at) < :tots ";
            $executor[':tots'] = $to;
        }
        $sql = $sqp; 
        $sql .= " UNION ALL ";
        $sql .= $sqc;
        
        $sql .= " ORDER BY topic,created_at\n";
       
//         $sqd = $sql;
//         foreach ($executor as $search => $replace) {
//             $sqd = str_replace($search,"'".$replace."'",$sqd);
//         }
//         echo $sqd;
        //;
        
        if ($result = $this->_execute($sql, $executor)) {
            $dataset = [
                'data' => [],
                'fromts' => $from,
                'tots' => $to,
                'sql' => '' //$sql //dbug
            ];
            
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                $dataset['data'][$row['topic']][$row['created_at']] = $row['value'];
            }
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
        
        
        $stmt = $this->getConnection()->prepare($sql);
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
        
        $stmt = $this->getConnection()->prepare($sql);
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
    
    public function _getAllTopics() {
        $sql = "SELECT DISTINCT topic FROM current_state order by topic";
        $r = [];
        $stmt = $this->getConnection()->prepare($sql);
        if ($stmt->execute($exec)) {
            while ($result = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $r[]= $result['topic'];
            }
            return $r;
        }
        return "";
    }
    
    public static function getAllTopics() {
        return self::$me->_getAllTopics();
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
    public function writeToTable($table, $kvpairs,$retry = 0) {
        $sql  = "INSERT INTO current_state ( topic, value ) VALUES ( :topic, :value ) ";
        $sql .= "ON DUPLICATE KEY UPDATE  value = :value";
        try {
            
            if (!$stmt = $this->getConnection()->prepare($sql)) {
                echo "Could not prepare Statement\n";
                return false;
            }
            $executor = [];
            $executor[':topic'] = $kvpairs['topic'];
            $executor[':value'] = $kvpairs['value'];
            
            $stmt->execute($executor);
                if (!$stmt->execute($executor)) {
                    $errInfo = $stmt->errorInfo();

                    echo $sql."\n";
                    echo 'Topic: '.$kvpairs['topic'].', value: '.$kvpairs['value']."\n";
                    if ( $errInfo[1] == 2006 ) {
                       $this->connection = null;
                       if ($retry < 20) return $this->writeToTable($table,$kvpairs,$retry++);
                       die("Number of retries (20) exceeded"); 
                    }
                }
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

