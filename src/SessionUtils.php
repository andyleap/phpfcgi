<?php

class SessionUtils {

	public static function unserialize($session_data) {
		$method = ini_get("session.serialize_handler");
		switch ($method) {
			case "php":
				return self::unserialize_php($session_data);
				break;
			case "php_binary":
				return self::unserialize_phpbinary($session_data);
				break;
			default:
				throw new Exception("Unsupported session.serialize_handler: " . $method . ". Supported: php, php_binary");
		}
	}

	public static function serialize($session_vars) {
		$method = ini_get("session.serialize_handler");
		switch ($method) {
			case "php":
				return self::serialize_php($session_vars);
				break;
			case "php_binary":
				return self::serialize_phpbinary($session_vars);
				break;
			default:
				throw new Exception("Unsupported session.serialize_handler: " . $method . ". Supported: php, php_binary");
		}
	}

	private static function unserialize_php($session_data) {
		$return_data = array();
		$offset = 0;
		while ($offset < strlen($session_data)) {
			if (!strstr(substr($session_data, $offset), "|")) {
				throw new Exception("invalid data, remaining: " . substr($session_data, $offset));
			}
			$deserialize = true;
			if (substr($session_data, $offset, 1) == '!') {
				$deserialize = false;
				$offset += 1;
			}
			$pos = strpos($session_data, "|", $offset);
			$num = $pos - $offset;
			$varname = substr($session_data, $offset, $num);
			$offset += $num + 1;
			if ($deserialize) {
				$data = unserialize(substr($session_data, $offset));
				$return_data[$varname] = $data;
				$offset += strlen(serialize($data));
			}
		}
		return $return_data;
	}

	private static function unserialize_phpbinary($session_data) {
		$return_data = array();
		$offset = 0;
		while ($offset < strlen($session_data)) {
			$num = ord($session_data[$offset]);
			$deserialize = true;
			if ($num > 127) {
				$num -= 127;
				$deserialize = false;
			}
			$offset += 1;
			$varname = substr($session_data, $offset, $num);
			$offset += $num;
			if ($deserialize) {
				$data = unserialize(substr($session_data, $offset));
				$return_data[$varname] = $data;
				$offset += strlen(serialize($data));
			}
		}
		return $return_data;
	}

	private static function serialize_php($session_vars) {
		$data = '';
		foreach ($session_vars as $key => $value) {
			if (strpos($key, '|') || strpos($key, '!')) {
				continue;
			}
			$data .= $key . '|' . serialize($value);
		}
		return $data;
	}

	private static function serialize_phpbinary($session_vars) {
		$data = '';
		foreach ($session_vars as $key => $value) {
			if (strlen($key) > 127) {
				continue;
			}
			$data .= chr(strlen($key)) . $key . serialize($value);
		}
		return $data;
	}
}