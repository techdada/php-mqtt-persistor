<?php
$properties=array();

/**
 * Simple properties class
 * 
 * @author dada
 *
 */
class Properties {
	public static function init($cfile) {
		global $properties;
		$properties = array();
		if (file_exists($cfile)) {
			$cnf=file_get_contents($cfile);
			foreach (explode("\n",$cnf) as $line) {
				$line = trim($line);
				if (!$line) continue;
				if ($line[0] == '#') continue;
				if ($line[0] == ';') continue;
				$t=explode('=',$line);
				$key = array_shift($t);
				$value = join('=',$t);
				$properties[$key]=$value;
				$t=null;
			}
			return true;
		} else {
			echo 'File does not exist:'.$cfile;
			return false;
		}
	}
	public static function get($key,$default='') {
		global $properties;
		if (array_key_exists($key,$properties)) {
			return $properties[$key];
		}
		return $default;
	}
}

