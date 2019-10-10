<?php
namespace paws;
use Exception;
/** A client-to-server websocket connection. */
class ServerConnection extends Connection
{
	/**
	 * @param string $url
	 * @throws Exception
	 */
	function __construct(string $url)
	{
		$components = parse_url($url);
		assert(in_array($components["scheme"], [
			"ws",
			"wss"
		]));
		assert(!empty($components["host"]));
		if(!array_key_exists("path", $components))
		{
			$components["path"] = "/";
		}
		if(array_key_exists("query", $components))
		{
			$components["query"] = "?".$components["query"];
		}
		else
		{
			$components["query"] = "";
		}
		$secure = ($components["scheme"] == "wss");
		$this->stream = stream_socket_client(($secure ? "ssl://" : "").$components["host"].":".(@$components["port"] ?? ($secure ? 443 : 80)), $errno, $errstr);
		if(!$this->isOpen())
		{
			throw new Exception("Failed to connect to WebSocket server: $errstr ($errno)");
		}
		try
		{
			$key = base64_encode(random_bytes(16));
		}
		catch(Exception $e)
		{
			$key = "";
			for($i = 0; $i < 16; $i++)
			{
				$key .= chr(rand(0, 256));
			}
			$key = base64_encode($key);
		}
		fwrite($this->stream, "GET {$components['path']}{$components['query']} HTTP/1.1\r\nHost: {$components['host']}\r\nPragma: no-cache\r\nUpgrade: WebSocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
		$res = fread($this->stream, 4096);
		$accept_pos = stripos($res, "Sec-WebSocket-Accept:");
		if(substr($res, 0, 12) != "HTTP/1.1 101" || $accept_pos === false)
		{
			throw new Exception("Received unexpected response to WebSocket handshake: ".$res);
		}
		$hash = Server::hashKey($key);
		if(substr(trim(substr($res, $accept_pos + 21)), 0, strlen($hash)) != $hash)
		{
			throw new Exception("WebSocket server failed to correctly hash WebSocket key {$key}. Response: ".$res);
		}
		stream_set_blocking($this->stream, false);
	}

	function writeFrame(Frame $frame): ServerConnection
	{
		fwrite($this->stream, chr(0x80 | $frame::OP_CODE));
		$length = strlen($frame->data);
		if($length < 0x7E)
		{
			fwrite($this->stream, chr(0x80 | $length));
		}
		else if($length < 0xFFFF)
		{
			fwrite($this->stream, "\xFE");
			fwrite($this->stream, pack("n", $length));
		}
		else
		{
			fwrite($this->stream, "\xFF");
			fwrite($this->stream, pack("n", $length));
		}
		$mask = pack("N", rand(1, 0x7FFFFFFF));
		fwrite($this->stream, $mask);
		for($i = 0; $i < $length; $i++)
		{
			fwrite($this->stream, chr(ord(substr($frame->data, $i, 1)) ^ ord(substr($mask, $i % 4, 1))));
		}
		return $this;
	}
}
