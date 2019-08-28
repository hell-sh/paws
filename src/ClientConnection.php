<?php
namespace paws;
/** A server-to-client websocket connection. */
class ClientConnection extends Connection
{
	public $stream;
	public $path;
	public $host;
	public $next_ping;
	public $disconnect_after = 0;

	function __construct($stream, string $path = "", string $host = "")
	{
		stream_set_blocking($stream, false);
		$this->stream = $stream;
		$this->path = $path;
		$this->host = $host;
		$this->next_ping = microtime(true) + 30;
	}

	function writeFrame(Frame $frame): ClientConnection
	{
		fwrite($this->stream, chr(0x80 | $frame::OP_CODE));
		$length = strlen($frame->data);
		if($length < 0x7E)
		{
			fwrite($this->stream, chr($length));
		}
		else if($length < 0xFFFF)
		{
			fwrite($this->stream, "\x7E");
			fwrite($this->stream, pack("n", $length));
		}
		else
		{
			fwrite($this->stream, "\x7F");
			fwrite($this->stream, pack("n", $length));
		}
		fwrite($this->stream, $frame->data);
		return $this;
	}
}
