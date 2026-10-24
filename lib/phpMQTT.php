<?php

namespace techdada;
/*
 	phpMQTT
	A simple php class to connect/publish/subscribe to an MQTT broker


*/

/*
	Licence

	Copyright (c) 2010 Blue Rhinos Consulting | Andrew Milsted
	andrew@bluerhinos.co.uk | http://www.bluerhinos.co.uk

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.

*/

/* phpMQTT */
class phpMQTT {

	private $incomingQos2 = [];
    private $pendingSubscriptions = [];
    private $retryAt = 0;
    private $retryDelay = 1;
    private $socket; 			/* holds the socket	*/
	private $msgid = 1;			/* counter for message id */
	private $keepalive = 60;		/* default keepalive timmer */
	private $timesinceping;		/* host unix time, used to detect disconects */
	private $pingstatus = 0;		/* To prevent ping sent to often */
	public $topics = array(); 	/* used to store currently subscribed topics */
	public $debug = false;		/* should output debug messages */
	public $address;			/* broker address */
	public $port;				/* broker port */
	public $clientid;			/* client id sent to brocker */
	public $will;				/* stores the will of the client */
	private $username;			/* stores username */
	private $password;			/* stores password */
	private $tls_prot;			/* stores tls protocol to use */
	private $topichistory = array();	/* stores topics with there values to see if value has changed before sending again */

	public $cafile;

	function __construct($address, $port, $clientid, $cafile = NULL, $tls_prot = 'tls'){
		$this->broker($address, $port, $clientid, $cafile, $tls_prot);
	}

	/* sets the broker details */
	function broker($address, $port, $clientid, $cafile = NULL, $tls_prot = 'tls'){
		$this->address = $address;
		$this->port = $port;
		$this->clientid = $clientid;
		$this->cafile = $cafile;
		$this->tls_prot = $tls_prot;
	}

    function setCredentials($username, $password) {
        $this->username = $username;
        $this->password = $password;
    }

	function getsocket()
	{
		return $this->socket;
	}

	function connect_auto($clean = true, $will = NULL, $username = NULL, $password = NULL){
		while($this->connect($clean, $will, $username, $password)==false){
			sleep(10);
		}
		return true;
	}

	/* connects to the broker
		inputs: $clean: should the client send a clean session flag */
	function connect($clean = true, $will = NULL, $username = NULL, $password = NULL){

		$this->close();
		if($will) $this->will = $will;
		if($username) $this->username = $username;
		if($password) $this->password = $password;


		if ($this->cafile) {
			$socketContext = stream_context_create(["ssl" => [
				"verify_peer_name" => true,
				"cafile" => $this->cafile
				]]);
			$this->socket = stream_socket_client($this->tls_prot . "://" . $this->address . ":" . $this->port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $socketContext);
		} else {
			$this->socket = stream_socket_client("tcp://" . $this->address . ":" . $this->port, $errno, $errstr, 5, STREAM_CLIENT_CONNECT);
		}

		if (!$this->socket ) {
		    if($this->debug) error_log("stream_socket_create() $errno, $errstr \n");
			return false;
		}

		stream_set_timeout($this->socket, 5);
		stream_set_blocking($this->socket, true);

		$this->pingstatus = 0;

		$i = 0;
		$buffer = "";

        // MQTT 3.1.1 protocol name and level.
        $buffer = "\x00\x04MQTT\x04";
        $i = strlen($buffer);

		//No Will
		$var = 0;
		if($clean) $var+=2;

		//Add will info to header
		if($this->will != NULL){
			$var += 4; // Set will flag
			$var += ($this->will['qos'] << 3); //Set will qos
			if($this->will['retain'])	$var += 32; //Set will retain
		}

		if($this->username != NULL) $var += 128;	//Add username to header
		if($this->password != NULL) $var += 64;	//Add password to header

		$buffer .= chr($var); $i++;

		//Keep alive
		$buffer .= chr(($this->keepalive * 2) >> 8); $i++;
		$buffer .= chr(($this->keepalive * 2) & 0xff); $i++;

		$buffer .= $this->strwritestring($this->clientid,$i);

		//Adding will to payload
		if($this->will != NULL){
			$buffer .= $this->strwritestring($this->will['topic'],$i);
			$buffer .= $this->strwritestring($this->will['content'],$i);
		}

		if($this->username) $buffer .= $this->strwritestring($this->username,$i);
		if($this->password) $buffer .= $this->strwritestring($this->password,$i);

		$head = "  ";
		$head[0] = chr(0x10);
		$head = chr(0x10) . $this->setmsglength($i);

		$this->write($this->socket, $head);
		$this->write($this->socket,  $buffer);

	 	$string = $this->read(4);

		if(strlen($string) === 4 && $string === "\x20\x02\x00\x00"){
			if($this->debug) echo "Connected to MQTT broker\n";
			foreach ($this->topichistory as $key => $value)
			{
				if ($value["retain"]) $this->publish($key, $value["msg"] , $value["qos"], 1);
			}
		}else{
			error_log(sprintf("Connection to MQTT broker failed! (Error: 0x%02x 0x%02x)\n",
			                        ord($string[0] ?? "\x00"),ord($string[3] ?? "\x00")));
			return false;
		}

		$this->timesinceping = time();
		$this->topichistory = array();
		return true;
	}

	/* read: reads in so many bytes */
    function read($int = 8192, $nb = false) {
        if (!is_resource($this->socket)) throw new \RuntimeException('MQTT socket closed');
        $result = '';
        $deadline = microtime(true) + 5;
        do {
            $r = [$this->socket]; $w = $e = null;
            $ready = @stream_select($r, $w, $e, 0, $nb ? 0 : 100000);
            if ($ready === false) throw new \RuntimeException('MQTT select failed');
            if ($ready > 0) {
                $part = @fread($this->socket, $int - strlen($result));
                if ($part === false || ($part === '' && feof($this->socket)))
                    throw new \RuntimeException('MQTT connection lost');
                $result .= $part;
            }
            if ($nb) return $result;
            if (microtime(true) >= $deadline) throw new \RuntimeException('MQTT read timeout');
        } while (strlen($result) < $int);
        return $result;
    }

    private function write($socket, $data, $length = null) {
        $offset = 0;
        $deadline = microtime(true) + 5;
        while ($offset < strlen($data)) {
            if (!is_resource($socket)) throw new \RuntimeException('MQTT socket closed');
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0 || microtime(true) >= $deadline)
                throw new \RuntimeException('MQTT write failed');
            $offset += $written;
        }
        return $offset;
    }

	/* subscribe: subscribes to topics */
	function subscribe($topics, $qos = 0){
		foreach($topics as $key => $topic){
            if (!in_array($topic['qos'], [0, 1, 2], true))
                throw new \InvalidArgumentException('Subscription QoS must be 0, 1 or 2');
			$i = 0;
			$buffer = "";
			$id = $this->msgid;
            $this->msgid = ($this->msgid % 65535) + 1;
			$buffer .= chr($id >> 8);  $i++;
			$buffer .= chr($id % 256);  $i++;
			$buffer .= $this->strwritestring($key,$i);
			$buffer .= chr($topic["qos"]);  $i++;
			$this->topics[$key] = $topic;
            $this->pendingSubscriptions[$id] = $topic['qos'];

			$cmd = 0x82;
			//$qos
			$cmd +=	($qos << 1);

			$head = chr($cmd);
			$head .= $this->setmsglength($i);

			$this->write($this->socket, $head);
			$this->write($this->socket, $buffer, $i);
			fflush($this->socket);
		}
	}

	/* ping: sends a keep alive ping */
	function ping(){
			$head = " ";
			$head = chr(0xc0);
			$head .= chr(0x00);
			if ($this->socket)
			{
				$this->write($this->socket, $head);
				if($this->debug) echo "ping sent\n";
			}
	}

	/* disconnect: sends a proper disconect cmd */
	function disconnect(){
			$head = " ";
			$head[0] = chr(0xe0);
			$head[1] = chr(0x00);
                        if ($this->socket)
                        {
				$this->write($this->socket, $head);
			}
	}

	/* close: sends a proper disconect, then closes the socket */
    function close() {
        if (is_resource($this->socket)) fclose($this->socket);
        $this->socket = null;
        $this->incomingQos2 = [];
        $this->pendingSubscriptions = [];
    }

    function proc($loop = true) {
        try {
            if (!is_resource($this->socket)) {
                if (microtime(true) < $this->retryAt) {
                    if ($loop) usleep(100000);
                    return 1;
                }
                if (!$this->connect(true)) throw new \RuntimeException('MQTT connect rejected');
                $this->subscribe($this->topics);
            }
            return $this->processPacket($loop);
        } catch (\PDOException $error) {
            throw $error; // Database failures must reach the service supervisor.
        } catch (\RuntimeException $error) {
            error_log($error->getMessage());
            $this->close();
            $this->retryAt = microtime(true) + $this->retryDelay;
            $this->retryDelay = min(30, $this->retryDelay * 2);
            return 1;
        }
    }

	/* publish: publishes $content on a $topic */
	function publish($topic, $content, $qos = 0, $retain = 0){

		if($this->debug) echo ("Publishing ".$topic."=".$content."\n");
               	$this->topichistory[$topic] = array("msg"=>$content, "qos"=>$qos, "retain"=>$retain);

		$i = 0;
		$buffer = "";

		$buffer .= $this->strwritestring($topic,$i);

		//$buffer .= $this->strwritestring($content,$i);

		if($qos){
			$id = $this->msgid++;
			$buffer .= chr($id >> 8);  $i++;
		 	$buffer .= chr($id % 256);  $i++;
		}

		$buffer .= $content;
		$i+=strlen($content);


		$head = " ";
		$cmd = 0x30;
		if($qos) $cmd += $qos << 1;
		if($retain) $cmd += 1;

		$head[0] = chr($cmd);
		$head .= $this->setmsglength($i);

		if ($this->socket)
		{
			$this->write($this->socket, $head, strlen($head));
			$this->write($this->socket, $buffer, $i);
		}

	}

	/* publishwhenchanged: only publish if value has changed */
	function publishwhenchanged($topic, $msg, $qos = 0, $retain = 0)
	{
        	if (!isset($this->topichistory[$topic]) || ($this->topichistory[$topic]["msg"] != $msg))
        	{
                	$this->publish($topic, $msg, $qos, $retain);
		}
		else if($this->debug) echo ("Not publishing ".$topic."=".$msg." because message is the same as published earlier...\n");
	}


	/* message: processes a recieved topic */
	function message($msg){
            if (strlen($msg) < 2) throw new \RuntimeException('Invalid MQTT PUBLISH');
            $tlen = (ord($msg[0])<<8) + ord($msg[1]);
			if ($tlen === 0 || strlen($msg) < $tlen + 2) throw new \RuntimeException('Invalid MQTT topic');
            $topic = substr($msg,2,$tlen);
			$msg = substr($msg,($tlen+2));
			$found = 0;
			foreach($this->topics as $key=>$top){
                $pattern = str_replace(['\\+', '\\#'], ['[^/]*', '.*'], preg_quote($key, '~'));
                if (preg_match('~^' . $pattern . '$~D', $topic)) {
					if(is_callable($top['function'])){
						call_user_func($top['function'],$topic,$msg);
						$found = 1;
					}
				}
			}

			if($this->debug && !$found) echo "msg recieved but no match in subscriptions\n";
	}

	/* proc: the processing loop for an "allways on" client
		set true when you are doing other stuff in the loop good for watching something else at the same time */
	function processPacket( $loop = true){

		if(1){
			$sockets = array($this->socket);
			$w = $e = NULL;
			$cmd = 0;

				//$byte = fgetc($this->socket);
			$byte = $this->read(1, true);

			if(!strlen($byte)){
				if($loop){
					usleep(100000);
				}

			}else{

				$cmd = (int)(ord($byte)/16);
				if($this->debug) echo "Recevid: $cmd\n";

				$multiplier = 1;
				$value = 0;
				do{
					$digit = ord($this->read(1));
					$value += ($digit & 127) * $multiplier;
					$multiplier *= 128;
                    if ($multiplier > 268435456) throw new \RuntimeException('Invalid MQTT length');
					}while (($digit & 128) != 0);

				if($this->debug) echo "Fetching: $value\n";

				if ($value > 1048576) throw new \RuntimeException('MQTT packet exceeds 1 MiB');
                $string = "";
                if($value)
					$string = $this->read($value);

				if($cmd){
					switch($cmd){
                        case 3:
                            $qos = (ord($byte) >> 1) & 3;
                            if ($qos === 3 || strlen($string) < 2)
                                throw new \RuntimeException('Invalid MQTT PUBLISH');
                            $topicLength = unpack('n', substr($string, 0, 2))[1];
                            if ($topicLength === 0 || strlen($string) < 2 + $topicLength + ($qos ? 2 : 0))
                                throw new \RuntimeException('Truncated MQTT PUBLISH');
                            if ($qos === 0) {
                                $this->message($string);
                                break;
                            }
                            $idOffset = 2 + $topicLength;
                            $id = unpack('n', substr($string, $idOffset, 2))[1];
                            if (!$id) throw new \RuntimeException('Invalid MQTT packet identifier');
                            $message = substr($string, 0, $idOffset) . substr($string, $idOffset + 2);
                            if ($qos === 1) {
                                $this->message($message);
                                $this->write($this->socket, "\x40\x02" . pack('n', $id));
                            } else {
                                // Keep only one copy until PUBREL; repeated PUBLISH gets PUBREC again.
                                if (!isset($this->incomingQos2[$id])) {
                                    if (count($this->incomingQos2) >= 64)
                                        throw new \RuntimeException('Too many pending QoS 2 messages');
                                    $this->incomingQos2[$id] = $message;
                                }
                                $this->write($this->socket, "\x50\x02" . pack('n', $id));
                            }
                            break;
                        case 6:
                            if (ord($byte) !== 0x62 || strlen($string) !== 2)
                                throw new \RuntimeException('Invalid MQTT PUBREL');
                            $id = unpack('n', $string)[1];
                            if (!$id) throw new \RuntimeException('Invalid MQTT packet identifier');
                            if (isset($this->incomingQos2[$id])) {
                                $this->message($this->incomingQos2[$id]);
                                unset($this->incomingQos2[$id]);
                            }
                            // Also acknowledge duplicate PUBREL without delivering twice.
                            $this->write($this->socket, "\x70\x02" . $string);
                            break;
                        case 9:
                            if (strlen($string) !== 3)
                                throw new \RuntimeException('Invalid MQTT SUBACK');
                            $id = unpack('n', substr($string, 0, 2))[1];
                            $granted = ord($string[2]);
                            if (!isset($this->pendingSubscriptions[$id]) || $granted > $this->pendingSubscriptions[$id])
                                throw new \RuntimeException('MQTT subscription rejected');
                            unset($this->pendingSubscriptions[$id]);
                            break;
                        case 13:
							if ($this->debug) echo "PING: Reply received!\n";
							$this->timesinceping = time();
							$this->pingstatus = 0;
                            $this->retryDelay = 1;
						break;
					}

				}
			}

			if(($this->pingstatus == 0) && ($this->timesinceping < (time() - $this->keepalive))){
				if($this->debug) echo "PING: First request send!\n";
				$this->ping();
				$this->pingstatus = 1;
			}

			if(($this->pingstatus == 1) && ($this->timesinceping < (time() - ($this->keepalive * 1.5)))){
				if($this->debug) echo "PING: Second request send!\n";
				$this->ping();
				$this->pingstatus = 2;
			}


			if($this->timesinceping<(time()-($this->keepalive * 2))){
				if($this->debug) echo "PING: Timeout! Reply not received in time, reconnecting\n";
                throw new \RuntimeException('MQTT ping timeout');
			}

		}
		return 1;
	}

	/* getmsglength: */
	function getmsglength(&$msg, &$i){

		$multiplier = 1;
		$value = 0 ;
		do{
		  $digit = ord($msg[$i]);
		  $value += ($digit & 127) * $multiplier;
		  $multiplier *= 128;
		  $i++;
		}while (($digit & 128) != 0);

		return $value;
	}


	/* setmsglength: */
	function setmsglength($len){
		$string = "";
		do{
		  $digit = $len % 128;
		  $len = $len >> 7;
		  // if there are more digits to encode, set the top bit of this digit
		  if ( $len > 0 )
		    $digit = ($digit | 0x80);
		  $string .= chr($digit);
		}while ( $len > 0 );
		return $string;
	}

	/* strwritestring: writes a string to a buffer */
	function strwritestring($str, &$i){
		$ret = " ";
		$len = strlen($str);
		$msb = $len >> 8;
		$lsb = $len % 256;
		$ret = chr($msb);
		$ret .= chr($lsb);
		$ret .= $str;
		$i += ($len+2);
		return $ret;
	}

	function printstr($string){
		$strlen = strlen($string);
			for($j=0;$j<$strlen;$j++){
				$num = ord($string[$j]);
				if($num > 31)
					$chr = $string[$j]; else $chr = " ";
				printf("%4d: %08b : 0x%02x : %s \n",$j,$num,$num,$chr);
			}
	}
}
