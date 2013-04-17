<?php

class FCGI_Server
{
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
	private $requestParams = array();
	public $SessionHandler = null;
	public $SessionSavePath = '';
	public $SessionName = '';
	public $SessionAutoStart = false;
	public $CookieParams = array();
	public $UseCookies = true;
	public $UseOnlyCookies = true;

	function __construct()
	{
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
		if($this->CookieParams['domain'] === '')
		{
			$this->CookieParams['domain'] = null;
		}
		if($this->CookieParams['secure'] === '')
		{
			$this->CookieParams['secure'] = false;
		}
		if($this->CookieParams['httponly'] === '')
		{
			$this->CookieParams['httponly'] = false;
		}
		$this->UseCookies = ini_get('session.use_cookies');
		$this->UseOnlyCookies = ini_get('session.use_only_cookies');
	}

	function Accept($close_old = true, $start_ob = true)
	{
		if($close_old)
		{
			foreach(array_values($this->requests) as $req)
			{
				$req->Close();
			}
		}
		while(true)
		{
			if(!$this->transferConnectionOpen)
			{
				$this->transferConnection = socket_accept($this->mainTransferConnection);
				$this->transferConnectionOpen = true;
				socket_set_block($this->transferConnection);
			}
			$headerData = socket_read($this->transferConnection, 8);
			while($headerData === '')
			{
				$this->transferConnection = socket_accept($this->mainTransferConnection);
				$this->transferConnectionOpen = true;
				socket_set_block($this->transferConnection);
				$headerData = socket_read($this->transferConnection, 8);
			}
			$header = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $headerData);
			$content = socket_read($this->transferConnection, $header['contentLength']);
			$padding = socket_read($this->transferConnection, $header['paddingLength']);
			switch($header['type'])
			{
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
					$this->requestParams[$header['requestId']] = '';
					break;
				case self::FCGI_PARAMS:
					if($header['contentLength'] == 0)
					{
						$SERVER = $this->DecodeNameValuePairs($this->requestParams[$header['requestId']]);
						$this->requests[$header['requestId']]->ProcessParams($SERVER);
					}
					else
					{
						$this->requestParams[$header['requestId']] .= $content;
					}
					break;
				case self::FCGI_STDIN:
					if($header['contentLength'] == 0)
					{
						$this->requests[$header['requestId']]->ProcessSTDIN();
						if($start_ob)
						{
							$this->requests[$header['requestId']]->Start_OB();
						}
						return $this->requests[$header['requestId']];
					}
					else
					{
						$this->requests[$header['requestId']]->STDIN .= $content;
					}
					break;
			}
		}
	}

	public function CloseRequest($id)
	{
		unset($this->requests[$id]);
	}

	private function GetValues($values)
	{
		$newValues = array();
		if(array_key_exists('FCGI_MAX_CONNS', $values))
		{
			$newValues['FCGI_MAX_CONNS'] = '1';
		}
		if(array_key_exists('FCGI_MAX_REQS', $values))
		{
			$newValues['FCGI_MAX_REQS'] = '1';
		}
		if(array_key_exists('FCGI_MPXS_CONNS', $values))
		{
			$newValues['FCGI_MPXS_CONNS'] = '0';
		}
		echo "Got values\n";
		return $newValues;
	}

	private function DecodeNameValuePairs($data)
	{
		$pairs = array();
		$pos = 0;
		while(strlen($data) > $pos)
		{
			$namelen = unpack('C', substr($data, $pos, 1))[1];
			$pos += 1;
			if($namelen > 127)
			{
				$namelen = intval(unpack('N', substr($data, $pos - 1, 4))[1]) & 2147483647;
				$pos += 3;
			}
			$vallen = unpack('C', substr($data, $pos, 1))[1];
			$pos += 1;
			if($vallen > 127)
			{
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

	private function EncodeNameValuePairs($pairs)
	{
		$data = "";
		foreach($pairs as $key => $value)
		{
			$namelen = strlen($key);
			if($namelen > 127)
			{
				$data .= pack('N', $namelen | -2147483648);
			}
			else
			{
				$data .= pack('C', $namelen);
			}
			$vallen = strlen($value);
			if($vallen > 127)
			{
				$data .= pack('N', $vallen | -2147483648);
			}
			else
			{
				$data .= pack('C', $vallen);
			}
			$data .= $key . $value;
		}
		return $data;
	}

	public function socket_safe_write($data)
	{
		$len = strlen($data);
		$offset = 0;
		while($offset < $len)
		{
			$sent = socket_write($this->transferConnection, substr($data, $offset), $len - $offset);
			if($sent === false)
			{
				break;
			}
			$offset += $sent;
		}
	}

}