<?php
namespace paws;
use RuntimeException;
/** A websocket connection. */
abstract class Connection
{
	public $stream;

	function isOpen(): bool
	{
		return $this->stream != null && @feof($this->stream) === false;
	}

	function close()
	{
		@fwrite($this->stream, "\x88\x00");
		if($this instanceof ServerConnection)
		{
			@fwrite($this->stream, pack("N", rand(1, 0x7FFFFFFF)));
		}
		fclose($this->stream);
		$this->stream = null;
	}

	abstract function writeFrame(Frame $frame);

	function flush(): Connection
	{
		fflush($this->stream);
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
					return null;
				}
				$header_2 = @fgetc($this->stream);
			}
			$header_2 = ord($header_2);
			$payload_len = $header_2 & 0x7F;
			if($payload_len >= 0x7E)
			{
				$ext_len = ($payload_len == 0x7F ? 8 : 2);
				$header_3 = @fread($this->stream, $ext_len);
				while($header_3 === null)
				{
					if((microtime(true) - $start) >= $timeout)
					{
						return null;
					}
					$header_3 = @fread($this->stream, $ext_len);
				}
				$payload_len = 0;
				for($i = 0; $i < $ext_len; $i++)
				{
					$payload_len += ord($header_3[$i]) << (($ext_len - $i - 1) * 8);
				}
			}
			if($header_2 & 0x80)
			{
				$mask = @fread($this->stream, 4);
				while($mask === null)
				{
					if((microtime(true) - $start) >= $timeout)
					{
						return null;
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
						return null;
					}
					$payload .= fread($this->stream, $payload_len - strlen($payload));
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
					fclose($this->stream);
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
