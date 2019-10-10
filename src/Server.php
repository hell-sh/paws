<?php
namespace paws;
use Exception;
use SplObjectStorage;
class Server
{
	/**
	 * The stream the server listens for new connections on.
	 *
	 * @var resource $stream
	 */
	public $stream;
	public $clients;
	/**
	 * The function called when a new connection has been established with the ClientConnection as argument.
	 *
	 * @see Server:accept()
	 * @var callable $connect_function
	 */
	public $connect_function = null;
	/**
	 * The function called when a client sends a frame with the ClientConnection and Frame as arguments.
	 *
	 * @see Server::handle()
	 * @var callable $frame_function
	 */
	public $frame_function = null;
	/**
	 * The function called when a connection has been closed with the ClientConnection as argument.
	 *
	 * @see Server:handle()
	 * @var callable $disconnect_function
	 */
	public $disconnect_function = null;

	/**
	 * @param resource $stream A stream created by stream_socket_server.
	 */
	function __construct($stream = null)
	{
		if($stream)
		{
			stream_set_blocking($stream, false);
			$this->stream = $stream;
		}
		$this->clients = new SplObjectStorage();
	}

	/**
	 * Accepts new clients.
	 *
	 * @return Server $this
	 */
	function accept(): Server
	{
		while(($stream = @stream_socket_accept($this->stream, 0)) !== false)
		{
			$header = fread($stream, 4096);
			if(!stristr($header, "Upgrade: WebSocket\r\n"))
			{
				fwrite($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
				fclose($stream);
				continue;
			}
			$host_pos = stripos($header, "Host:");
			if($host_pos === false)
			{
				fwrite($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
				fclose($stream);
				continue;
			}
			$key_pos = stripos($header, "Sec-WebSocket-Key:");
			if($key_pos === false)
			{
				fwrite($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
				fclose($stream);
				continue;
			}
			$request = explode(" ", explode("\r\n", $header)[0]);
			if(count($request) != 3)
			{
				fwrite($stream, "HTTP/1.1 400 Bad Request\r\n\r\n");
				fclose($stream);
				continue;
			}
			fwrite($stream, "HTTP/1.1 101 WebSocket Upgrade\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nServer: hell-sh/paws\r\nSec-WebSocket-Accept: ".Server::hashKey(explode("\r\n", trim(substr($header, $key_pos + 18)))[0])."\r\n\r\n");
			$con = new ClientConnection($stream, $request[1]);
			$this->clients->attach($con);
			if($this->connect_function)
			{
				($this->connect_function)($con);
			}
		}
		return $this;
	}

	static function hashKey(string $key): string
	{
		return base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
	}

	/**
	 * Deals with all connected clients.
	 *
	 * @return Server $this
	 */
	function handle(): Server
	{
		$frame_function = $this->frame_function ?? function()
			{
			};
		foreach($this->clients as $con)
		{
			/**
			 * @var ClientConnection $con
			 */
			if($con->isOpen())
			{
				try
				{
					$frame = $con->readFrame(0);
					if($con->disconnect_after != 0 && $con->disconnect_after <= microtime(true))
					{
						$con->close();
					}
					else if($con->next_ping != 0 && $con->next_ping <= microtime(true))
					{
						@fwrite($con->stream, "\x89\x00");
						$con->disconnect_after = microtime(true) + 10;
					}
					while($frame)
					{
						$frame_function($con, $frame);
						$frame = $con->readFrame(0);
					}
				}
				catch(Exception $e)
				{
					$con->writeFrame(new TextFrame($e->getMessage()))
						->flush()
						->close();
				}
			}
			if(!$con->isOpen())
			{
				if($this->disconnect_function)
				{
					($this->disconnect_function)($con);
				}
				$this->clients->detach($con);
			}
		}
		return $this;
	}
}
