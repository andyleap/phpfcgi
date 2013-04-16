<?php

class FCGI_Request {

	private $requestId;
	private $headers = array();
	private $headers_sent = false;
	private $server;
	private $flags;
	private $ob_started = false;
	private $open = true;
	public $SERVER = array();
	public $STDIN = "";
	public $COOKIE = array();
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

	function ProcessParams($Params) {
		if (isset($Params['HTTP_COOKIE'])) {
			$this->COOKIE = $this->ParseHeader($Params['HTTP_COOKIE']);
		}
		if (isset($Params['QUERY_STRING'])) {
			parse_str($Params['QUERY_STRING'], $this->GET);
		}
		$this->SERVER = $Params;
	}

	function ProcessSTDIN() {
		if (isset($this->SERVER['CONTENT_TYPE'])) {
			$content_type_info = $this->ParseHeader($this->SERVER['CONTENT_TYPE']);

			switch (strtolower(trim($content_type_info[0]))) {
				case 'application/x-www-form-urlencoded':
					parse_str($this->STDIN, $this->POST);
					break;
				case 'multipart/form-data':
					$this->ParseMultipart($this->STDIN, $content_type_info['boundary']);
					break;
			}
		}
	}

	private function ParseHeader($header) {
		$headerParts = explode(';', $header);
		$header_info = array();
		foreach ($headerParts as $headerPart) {
			$parts = explode('=', trim($headerPart), 2);
			if (count($parts) > 1) {
				$header_info[$parts[0]] = trim(urldecode($parts[1]), '"');
			} else {
				$header_info[] = $parts[0];
			}
		}
		return $header_info;
	}

	function ParseMultipart($data, $boundary) {
		
		$blocks = preg_split('/\r\n-+' . $boundary . '/', "\r\n" . $data);
		array_pop($blocks);

		foreach ($blocks as $id => $block) {
			if (empty($block))
				continue;
			
			list($headerdata, $body) = explode("\r\n\r\n", $block, 2);
			$headerdatas = explode("\r\n", $headerdata);
			$headers = array();
			foreach($headerdatas as $header)
			{
				if(trim($header) != '')
				{
					list($name, $params) = explode(':', $header, 2);
					$headers[strtolower($name)] = array_change_key_case($this->ParseHeader($params));
				}
			}
			if(strtolower($headers['content-disposition'][0]) == 'form-data')
			{
				$this->POST[$headers['content-disposition']['name']] = $body;
			}
		}
		return array();
	}

	function Header($name, $value, $replace = true) {
		if ($replace || !isset($this->headers[$name])) {
			$this->headers[$name] = array($value);
		} else {
			$this->headers[$name][] = $value;
		}
	}

	function Session_Start() {
		if (!$this->SessionStarted) {
			$this->SessionHandler->open($this->SessionSavePath, $this->SessionName);
			$data = $this->SessionHandler->read($this->Session_ID());
			if ($this->UseCookies) {
				$expire = 0;
				if ($this->CookieParams['lifetime'] > 0) {
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
		if ($this->UseCookies && isset($this->COOKIE[$this->SessionName])) {
			$this->sessionID = $this->COOKIE[$this->SessionName];
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
		if (!$this->UseOnlyCookies && !isset($this->COOKIE[$this->SessionName])) {
			return $this->SessionName . '=' . $this->Session_ID();
		}
		return '';
	}

	function Session_Destroy() {
		$this->SessionHandler->destroy($this->Session_ID());
		$this->SessionStarted = false;
	}

	function Session_Write_Close() {
		if ($this->SessionStarted) {
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
			if ($this->SessionStarted) {
				$this->Session_Write_Close();
			}
			$this->server->CloseRequest($this->requestId);
			$this->open = false;
		}
	}

}