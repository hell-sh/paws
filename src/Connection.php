<?php
namespace WebSocket;
use RuntimeException;
/** A websocket connection. */
abstract class Connection
{
	/**
	 * The connection is closed and was never open. Only a possible with ServerConnection.
	 */
	const STATUS_CONNECT_FAILED = 0;
	/**
	 * The connection is open.
	 */
	const STATUS_OPEN = 1;
	/**
	 * The connection was open and had been closed properly.
	 */
	const STATUS_CLOSED = 2;
	/**
	 * The connection was open but was lost without a proper goodbye.
	 */
	const STATUS_LOST = 3;
	/**
	 * @var int $status
	 */
	public $status = Connection::STATUS_CONNECT_FAILED;
	/**
	/**
	 * @var int $close_code
	 * @since 0.5.1
	 */
	public $close_code = 0;
	/**
	 * @var resource|null $resource
	 */
	public $stream;

	protected function ensureOpen(): bool
	{
		if($this->stream === null || feof($this->stream))
		{
			if($this->status == Connection::STATUS_OPEN)
			{
				$this->status = Connection::STATUS_LOST;
			}
			fclose($this->stream);
			return false;
		}
		return true;
	}

	function close()
	{
		if($this->ensureOpen())
		{
			$this->status = Connection::STATUS_CLOSED;
			@fwrite($this->stream, "\x88\x00");
			if($this instanceof ServerConnection)
			{
				@fwrite($this->stream, pack("N", rand(1, 0x7FFFFFFF)));
			}
			fclose($this->stream);
			$this->stream = null;
		}
	}

	abstract function writeFrame(Frame $frame);

	function flush(): Connection
	{
		if($this->ensureOpen())
		{
			@fflush($this->stream);
		}
		return $this;
	}

	/**
	 * @param float $timeout
	 * @return Frame|null
	 */
	function readFrame(float $timeout = 3.000)
	{
		$frame = null;
		$start = microtime(true);
		do
		{
			$header_1 = @fgetc($this->stream);
			while($header_1 === false)
			{
				if((microtime(true) - $start) >= $timeout)
				{
					return null;
				}
				$header_1 = @fgetc($this->stream);
			}
			$header_2 = @fgetc($this->stream);
			while($header_2 === false)
			{
				if((microtime(true) - $start) >= $timeout)
				{
					throw new RuntimeException("Timed out reading header");
				}
				$header_2 = @fgetc($this->stream);
			}
			$header_2 = ord($header_2);
			$payload_len = $header_2 & 0x7F;
			if($payload_len >= 126)
			{
				$ext_len = ($payload_len == 127 ? 8 : 2);
				$header_3 = @fread($this->stream, $ext_len);
				if($header_3 === null || strlen($header_3) != $ext_len)
				{
					throw new RuntimeException("Failed to read extended length data");
				}
				$payload_len = 0;
				for($i = 0; $i < $ext_len; $i++)
				{
					$payload_len <<= 8;
					$payload_len += ord($header_3[$i]);
				}
			}
			if($header_2 & 0x80)
			{
				$mask = @fread($this->stream, 4);
				while($mask === null)
				{
					if((microtime(true) - $start) >= $timeout)
					{
						throw new RuntimeException("Timed out reading mask");
					}
					$mask = @fread($this->stream, 4);
				}
			}
			if($payload_len == 0)
			{
				$data = "";
			}
			else
			{
				if($timeout == 0)
				{
					$timeout += 0.1;
				}
				$payload = fread($this->stream, $payload_len);
				while(strlen($payload) < $payload_len)
				{
					if((microtime(true) - $start) >= $timeout)
					{
						throw new RuntimeException("Timed out reading payload");
					}
					$read = @fread($this->stream, $payload_len - strlen($payload));
					if($read === false)
					{
						$this->close();
						return null;
					}
					$payload .= $read;
					$timeout += 0.1;
				}
				if(isset($mask))
				{
					$data = "";
					for($i = 0; $i < $payload_len; $i++)
					{
						$data .= chr(ord(substr($payload, $i, 1)) ^ ord(substr($mask, $i % 4, 1)));
					}
				}
				else
				{
					$data = $payload;
				}
			}
			$header_1 = ord($header_1);
			$opcode = $header_1 & 0x0F;
			switch($opcode)
			{
				case 0: // Continuation Frame
					if($frame instanceof Frame)
					{
						$frame->data .= $data;
					}
					else
					{
						throw new RuntimeException("Unexpected continuation frame");
					}
					break;
				case 1: // Text Frame
					$frame = new TextFrame($data);
					break;
				case 2: // Binary Frame
					$frame = new BinaryFrame($data);
					break;
				case 8: // Close
					@fclose($this->stream);
					$this->stream = null;
					$this->status = Connection::STATUS_CLOSED;
					$this->close_code = @unpack("ncode", substr($data, 0, 2))["code"];
					break;
				case 9: // Ping
					if(!$this instanceof ServerConnection)
					{
						throw new RuntimeException("Unexpected ping");
					}
					fwrite($this->stream, "\x8A\x80".pack("N", rand(1, 0x7FFFFFFF)));
					break;
				case 10: // Pong
					if($this instanceof ClientConnection)
					{
						$this->next_ping = microtime(true) + 30;
						$this->disconnect_after = 0;
					}
					else
					{
						throw new RuntimeException("Unexpected pong");
					}
					break;
				default:
					throw new RuntimeException("Unexpected opcode: $opcode");
			}
		}
		while(($header_1 & 0x80) === 0);
		return $frame;
	}
}
