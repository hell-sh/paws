<?php
namespace paws;
/** A server-to-client websocket connection. */
class ClientConnection extends Connection
{
	public $path;
	public $host;
	/**
	 * The remote's address, e.g. "127.0.0.1:50420". This value persists after the stream is closed.
	 *
	 * @var string $remote_addr
	 */
	public $remote_addr;
	public $next_ping;
	public $disconnect_after = 0;

	function __construct($stream, string $path = "", string $host = "")
	{
		$this->stream = $stream;
		$this->status = Connection::STATUS_OPEN;
		$this->remote_addr = stream_socket_get_name($stream, true);
		$this->path = $path;
		$this->host = $host;
		$this->next_ping = microtime(true) + 30;
	}

	function writeFrame(Frame $frame): ClientConnection
	{
		if($this->stream === null || @feof($this->stream))
		{
			if($this->status == Connection::STATUS_OPEN)
			{
				$this->status = Connection::STATUS_LOST;
			}
		}
		else
		{
			@fwrite($this->stream, chr(0x80 | $frame::OP_CODE));
			$length = strlen($frame->data);
			if($length < 0x7E)
			{
				@fwrite($this->stream, chr($length));
			}
			else if($length < 0xFFFF)
			{
				@fwrite($this->stream, "\x7E");
				@fwrite($this->stream, pack("n", $length));
			}
			else
			{
				@fwrite($this->stream, "\x7F");
				@fwrite($this->stream, pack("n", $length));
			}
			@fwrite($this->stream, $frame->data);
		}
		return $this;
	}
}
