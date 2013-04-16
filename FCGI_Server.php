<?php

class FileSessionHandler implements SessionHandlerInterface {

	private $savePath;

	public function open($savePath, $sessionName) {
		$this->savePath = $savePath;
		if (!is_dir($this->savePath)) {
			mkdir($this->savePath, 0777);
		}
		return true;
	}

	public function close() {
		return true;
	}

	public function read($id) {
		return (string) @file_get_contents("$this->savePath/sess_$id");
	}

	public function write($id, $data) {
		return file_put_contents("$this->savePath/sess_$id", $data) === false ? false : true;
	}

	public function destroy($id) {
		$file = "$this->savePath/sess_$id";
		if (file_exists($file)) {
			unlink($file);
		}
		return true;
	}

	public function gc($maxlifetime) {
		foreach (glob("$this->savePath/sess_*") as $file) {
			if (filemtime($file) + $maxlifetime < time() && file_exists($file)) {
				unlink($file);
			}
		}
		return true;
	}

}

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

class FCGI_Server {

	const FCGI_BEGIN_REQUEST = 1;
	const FCGI_ABORT_REQUEST = 2;
	const FCGI_END_REQUEST = 3;
	const FCGI_PARAMS = 4;
	const FCGI_STDIN = 5;
	const FCGI_STDOUT = 6;
	const FCGI_STDERR = 7;
	const FCGI_DATA = 8;
	const FCGI_GET_VALUES = 9;
	const FCGI_GET_VALUES_RESULT = 10;
	const FCGI_UNKNOWN_TYPE = 11;
	const FCGI_MAXTYPE = self::FCGI_UNKNOWN_TYPE;
	const FCGI_KEEP_CONN = 1;

	private $mainTransferConnection;
	private $transferConnection;
	private $transferConnectionOpen = false;
	private $requests = array();
	public $SessionHandler = null;
	public $SessionSavePath = '';
	public $SessionName = '';
	public $SessionAutoStart = false;
	public $CookieParams = array();
	public $UseCookies = true;
	public $UseOnlyCookies = true;

	function __construct() {
		$this->mainTransferConnection = socket_import_stream(STDIN);
		socket_set_block($this->mainTransferConnection);
		$this->transferConnection = socket_accept($this->mainTransferConnection);
		$this->transferConnectionOpen = true;
		socket_set_block($this->transferConnection);

		$this->SessionHandler = new FileSessionHandler();
		$this->SessionName = ini_get('session.name');
		$this->SessionSavePath = ini_get('session.save_path');
		$this->SessionAutoStart = ini_get('session.auto_start');
		$this->CookieParams = array(
			'lifetime' => ini_get('session.cookie_lifetime'),
			'path' => ini_get('session.cookie_path'),
			'domain' => ini_get('session.cookie_domain'),
			'secure' => ini_get('session.cookie_secure'),
			'httponly' => ini_get('session.cookie_httponly'),
		);
		if ($this->CookieParams['domain'] === '') {
			$this->CookieParams['domain'] = null;
		}
		if ($this->CookieParams['secure'] === '') {
			$this->CookieParams['secure'] = false;
		}
		if ($this->CookieParams['httponly'] === '') {
			$this->CookieParams['httponly'] = false;
		}
		$this->UseCookies = ini_get('session.use_cookies');
		$this->UseOnlyCookies = ini_get('session.use_only_cookies');
	}

	function Accept($close_old = true, $start_ob = true) {
		if ($close_old) {
			foreach (array_values($this->requests) as $req) {
				$req->Close();
			}
		}
		while (true) {
			if (!$this->transferConnectionOpen) {
				$this->transferConnection = socket_accept($this->mainTransferConnection);
				$this->transferConnectionOpen = true;
				socket_set_block($this->transferConnection);
			}
			$headerData = socket_read($this->transferConnection, 8);
			while ($headerData === '') {
				$this->transferConnection = socket_accept($this->mainTransferConnection);
				$this->transferConnectionOpen = true;
				socket_set_block($this->transferConnection);
				$headerData = socket_read($this->transferConnection, 8);
			}
			$header = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $headerData);
			$content = socket_read($this->transferConnection, $header['contentLength']);
			$padding = socket_read($this->transferConnection, $header['paddingLength']);
			switch ($header['type']) {
				case self::FCGI_GET_VALUES:
					$resData = $this->EncodeNameValuePairs($this->GetValues($this->DecodeNameValuePairs($content)));
					$resLen = strlen($resData);
					$padLen = (8 - ($resLen % 8)) % 8;
					$response = pack('CCnnCC', 1, self::FCGI_GET_VALUES_RESULT, 0, $resLen, $padLen, 0) . $resData . str_repeat('\0', $padLen);
					$this->socket_safe_write($this->transferConnection, $response);
					break;
				case self::FCGI_BEGIN_REQUEST:
					$data = unpack('nrole/Cflags', $content);
					$this->requests[$header['requestId']] = new FCGI_Request($header['requestId'], $this, $data['flags']);
					break;
				case self::FCGI_PARAMS:
					if ($header['contentLength'] == 0) {
						$SERVER = $this->DecodeNameValuePairs($this->requests[$header['requestId']]->SERVER);
						if (isset($SERVER['HTTP_COOKIE'])) {
							$rawcookies = explode(';', $SERVER['HTTP_COOKIE']);
							$cookies = array();
							foreach ($rawcookies as $cookie) {
								list($name, $value) = explode('=', trim($cookie), 2);
								$cookies[$name] = urldecode($value);
							}
							$this->requests[$header['requestId']]->COOKIES = $cookies;
						}
						if (isset($SERVER['QUERY_STRING'])) {
							parse_str($SERVER['QUERY_STRING'], $this->requests[$header['requestId']]->GET);
						}
						$this->requests[$header['requestId']]->SERVER = $SERVER;
					} else {
						$this->requests[$header['requestId']]->SERVER .= $content;
					}
					break;
				case self::FCGI_STDIN:
					if ($header['contentLength'] == 0) {
						if(isset($this->requests[$header['requestId']]->SERVER['CONTENT_TYPE']))
						{
							$content_type = $this->requests[$header['requestId']]->SERVER['CONTENT_TYPE'];
							switch (strtolower(trim($content_type))) {
								case 'application/x-www-form-urlencoded':
									parse_str($this->requests[$header['requestId']]->STDIN, $this->requests[$header['requestId']]->POST);
									break;
							}
						}
						if($start_ob)
						{
							$this->requests[$header['requestId']]->Start_OB();
						}
						return $this->requests[$header['requestId']];
					} else {
						$this->requests[$header['requestId']]->STDIN .= $content;
					}
					break;
			}
		}
	}

	public function CloseRequest($id) {
		unset($this->requests[$id]);
	}

	private function GetValues($values) {
		$newValues = array();
		if (array_key_exists('FCGI_MAX_CONNS', $values)) {
			$newValues['FCGI_MAX_CONNS'] = '1';
		}
		if (array_key_exists('FCGI_MAX_REQS', $values)) {
			$newValues['FCGI_MAX_REQS'] = '1';
		}
		if (array_key_exists('FCGI_MPXS_CONNS', $values)) {
			$newValues['FCGI_MPXS_CONNS'] = '0';
		}
		echo "Got values\n";
		return $newValues;
	}

	private function DecodeNameValuePairs($data) {
		$pairs = array();
		$pos = 0;
		while (strlen($data) > $pos) {
			$namelen = unpack('C', substr($data, $pos, 1))[1];
			$pos += 1;
			if ($namelen > 127) {
				$namelen = intval(unpack('N', substr($data, $pos - 1, 4))[1]) & 2147483647;
				$pos += 3;
			}
			$vallen = unpack('C', substr($data, $pos, 1))[1];
			$pos += 1;
			if ($vallen > 127) {
				$vallen = intval(unpack('N', substr($data, $pos - 1, 4))[1]) & 2147483647;
				$pos +=3;
			}
			$name = substr($data, $pos, $namelen);
			$pos += $namelen;
			$value = substr($data, $pos, $vallen);
			$pos += $vallen;
			$pairs[$name] = $value;
		}
		return $pairs;
	}

	private function EncodeNameValuePairs($pairs) {
		$data = "";
		foreach ($pairs as $key => $value) {
			$namelen = strlen($key);
			if ($namelen > 127) {
				$data .= pack('N', $namelen | -2147483648);
			} else {
				$data .= pack('C', $namelen);
			}
			$vallen = strlen($value);
			if ($vallen > 127) {
				$data .= pack('N', $vallen | -2147483648);
			} else {
				$data .= pack('C', $vallen);
			}
			$data .= $key . $value;
		}
		return $data;
	}

	public function socket_safe_write($data) {
		$len = strlen($data);
		$offset = 0;
		while ($offset < $len) {
			$sent = socket_write($this->transferConnection, substr($data, $offset), $len - $offset);
			if ($sent === false) {
				break;
			}
			$offset += $sent;
		}
	}

}

class FCGI_Request {

	private $requestId;
	private $headers = array();
	private $headers_sent = false;
	private $server;
	private $flags;
	private $ob_started = false;
	private $open = true;
	public $SERVER = "";
	public $STDIN = "";
	public $COOKIES = array();
	public $GET = array();
	public $POST = array();
	public $SESSION = array();
	private $newCookies = array();
	
	private $SessionStarted = false;
	private $SessionHandler = null;
	private $SessionSavePath = '';
	private $SessionName = '';
	private $SessionAutoStart = false;
	private $CookieParams = array();
	private $UseCookies = true;
	private $UseOnlyCookies = true;
	private $sessionID = null;

	function __construct($requestId, $server, $flags) {
		$this->requestId = $requestId;
		$this->server = $server;
		$this->flags = $flags;
		$this->Header('Content-type', 'text/html');

		$this->SessionHandler = $server->SessionHandler;
		$this->SessionSavePath = $server->SessionSavePath;
		$this->SessionName = $server->SessionName;
		$this->SessionAutoStart = $server->SessionAutoStart;
		$this->CookieParams = $server->CookieParams;
		$this->UseCookies = $server->UseCookies;
		$this->UseOnlyCookies = $server->UseOnlyCookies;

		if ($this->SessionAutoStart) {
			$this->Session_Start();
		}
	}

	function Header($name, $value, $replace = true) {
		if ($replace || !isset($this->headers[$name])) {
			$this->headers[$name] = array($value);
		} else {
			$this->headers[$name][] = $value;
		}
	}

	function Session_Start() {
		if(!$this->SessionStarted)
		{
			$this->SessionHandler->open($this->SessionSavePath, $this->SessionName);
			$data = $this->SessionHandler->read($this->Session_ID());
			if ($this->UseCookies) {
				$expire = 0;
				if($this->CookieParams['lifetime'] > 0)
				{
					$expire = time() + $this->CookieParams['lifetime'];
				}
				$this->SetCookie($this->SessionName, $this->Session_ID(), $expire, $this->CookieParams['path'], $this->CookieParams['domain'], $this->CookieParams['secure'], $this->CookieParams['httponly']);
			}
			$this->SESSION = SessionUtils::unserialize($data);
			$this->SessionStarted = true;
		}
	}

	function Session_ID() {
		if ($this->sessionID !== null) {
			return $this->sessionID;
		}
		if ($this->UseCookies && isset($this->COOKIES[$this->SessionName])) {
			$this->sessionID = $this->COOKIES[$this->SessionName];
			return $this->sessionID;
		}
		if (!$this->UseOnlyCookies && isset($this->GET[$this->SessionName])) {
			$this->sessionID = $this->GET[$this->SessionName];
			return $this->sessionID;
		}
		$this->sessionID = Sha1(uniqid($this->SessionName, true));
		return $this->sessionID;
	}

	function SID() {
		if (!$this->UseOnlyCookies && !isset($this->COOKIES[$this->SessionName])) {
			return $this->SessionName . '=' . $this->Session_ID();
		}
		return '';
	}
	
	function Session_Destroy() {
		$this->SessionHandler->destroy($this->Session_ID());
		$this->SessionStarted = false;
	}
	
	function Session_Write_Close() {
		if($this->SessionStarted)
		{
			$this->SessionHandler->write($this->Session_ID(), SessionUtils::serialize($this->SESSION));
			$this->SessionStarted = false;
		}
	}

	function Header_Remove($name) {
		unset($this->header[$name]);
	}

	function SetRawCookie($name, $value, $expire = 0, $path = '/', $domain = null, $secure = false, $httponly = false) {
		$cookie = $value;
		if ($expire != 0) {
			$cookie .= '; expires=' . gmdate('D, d-M-Y H:i:s \G\M\T', $expire);
		}
		if ($domain != null) {
			$cookie .= '; domain=' . $domain;
		}
		$cookie .= '; path=' . $path;
		if ($secure) {
			$cookie .= '; secure';
		}
		if ($httponly) {
			$cookie .= '; httponly';
		}
		$this->newCookies[$name] = $cookie;
	}

	function SetCookie($name, $value, $expire = 0, $path = '/', $domain = null, $secure = false, $httponly = false) {
		$this->SetRawCookie($name, urlencode($value), $expire, $path, $domain, $secure, $httponly);
	}

	function Write($data) {
		if ($this->open) {
			if (!$this->headers_sent) {
				$headers = '';
				$sep = '';
				foreach ($this->headers as $name => $values) {
					foreach ($values as $value) {
						$headers .= $sep . $name . ': ' . $value;
						$sep = "\r\n";
					}
				}
				foreach ($this->newCookies as $name => $value) {
					$headers .= $sep . 'Set-Cookie: ' . $name . '=' . $value;
					$sep = "\r\n";
				}
				$data = $headers . "\r\n\r\n" . $data;
				$this->headers_sent = true;
			}

			$pos = 0;
			while (strlen($data) > $pos) {
				$resLen = strlen($data) - $pos;
				if ($resLen > 65535) {
					$resLen = 65535;
				}
				$padLen = (8 - ($resLen % 8)) % 8;
				$response = pack('CCnnCC', 1, FCGI_Server::FCGI_STDOUT, $this->requestId, $resLen, $padLen, 0) . substr($data, $pos, $resLen) . str_repeat("\0", $padLen);
				$pos += $resLen;
				$this->server->socket_safe_write($response);
			}
		}
	}

	public function Start_OB() {
		if (!$this->ob_started && $this->open) {
			ob_start(array($this, 'Write'), 4096);
			$this->ob_started = true;
		}
	}

	public function End_OB() {
		if ($this->ob_started) {
			ob_end_flush();
			$this->ob_started = false;
		}
	}

	public function Close() {
		if ($this->open) {
			$this->End_OB();
			$response = pack('CCnnCC', 1, FCGI_Server::FCGI_STDOUT, $this->requestId, 0, 0, 0);
			$this->server->socket_safe_write($response);
			$resData = pack('NCxxx', 0, 0);
			$resLen = strlen($resData);
			$padLen = (8 - ($resLen % 8)) % 8;
			$response = pack('CCnnCC', 1, FCGI_Server::FCGI_END_REQUEST, $this->requestId, $resLen, $padLen, 0) . $resData . str_repeat('\0', $padLen);
			$this->server->socket_safe_write($response);
			if($this->SessionStarted)
			{
				$this->Session_Write_Close();
			}
			$this->server->CloseRequest($this->requestId);
			$this->open = false;
		}
	}
}