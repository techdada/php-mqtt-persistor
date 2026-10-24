<?php
require __DIR__ . '/../lib/SensorFlow.php';
function check($ok, $msg) { if (!$ok) throw new Exception($msg); }
class FakeStatement {
    public function execute($values) { return true; }
}
class FakeConnection {
    public $calls = 0;
    public $error;
    public function prepare($sql) {
        $this->calls++;
        if ($this->error) { $e=$this->error; $this->error=null; throw $e; }
        return new FakeStatement;
    }
}
class TestFlow extends techdada\SensorFlow {
    public $fresh;
    public $reconnects = 0;
    public function __construct($connection) { $this->connection=$connection; $this->fresh=new FakeConnection; }
    protected function connectDB() { $this->reconnects++; return $this->connection=$this->fresh; }
}
function dbError($code) {
    $e=new PDOException('injected failure'); $e->errorInfo=['HY000',$code,'injected']; return $e;
}
$c=new FakeConnection; $f=new TestFlow($c);
check($f->writeToTable('current_state',['topic'=>'test','value'=>'1']), 'normal write');
check($c->calls===1,'write must execute once');
foreach ([2002,2003,2006,2013,2055] as $code) {
    $c=new FakeConnection; $c->error=dbError($code); $f=new TestFlow($c);
    check($f->writeToTable('current_state',['topic'=>'test','value'=>'1']),'recovered write');
    check($f->reconnects===1 && $f->fresh->calls===1,'fresh connection required');
}
$c=new FakeConnection; $c->error=dbError(1146); $f=new TestFlow($c);
try { $f->writeToTable('current_state',['topic'=>'test','value'=>'1']); throw new Exception('schema error swallowed'); }
catch (PDOException $e) { check($f->reconnects===0,'schema error retried'); }
echo "PASS: single execution, reconnect for five connection errors, permanent error propagated\n";
class FailingConnection {
    public function prepare($sql) { throw dbError(2006); }
}
class FailingFlow extends TestFlow {
    protected function connectDB() { $this->reconnects++; return $this->connection=new FailingConnection; }
}
$f=new FailingFlow(new FailingConnection);
try { $f->writeToTable('current_state',['topic'=>'test','value'=>'1']); throw new Exception('retry limit missing'); }
catch (PDOException $e) { check($f->reconnects===2,'exactly two retries'); }
require __DIR__ . '/../lib/phpMQTT.php';
class ThrowingClient extends techdada\phpMQTT {
    public function processPacket($loop=true) { throw dbError(1146); }
}
$c=new ThrowingClient('localhost',1,'test');
$rp=new ReflectionProperty(techdada\phpMQTT::class,'socket'); $rp->setAccessible(true);
$rp->setValue($c, fopen('php://temp','r+'));
try { $c->proc(); throw new Exception('database failure swallowed by MQTT'); }
catch (PDOException $e) { $c->close(); }
echo "PASS: retry bound and database errors escape MQTT loop for supervisor restart\n";
