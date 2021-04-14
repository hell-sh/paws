<?php
namespace WebSocket;
use Asyncore\
{Asyncore, Loop};
use Exception;
use SplObjectStorage;
class Server extends \Asyncore\Server
{
	const HTTP_400 = "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nServer: hell-sh/websocket\r\n\r\n";
	/**
	 * @var Loop $ws_loop
	 */
	private $ws_loop;
	/**
	 * @var SplObjectStorage $clients
	 */
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
	 * @param array<resource> $streams The streams the server listens for new connections on.
	 */
	public function __construct(array $streams = [])
	{
		parent::__construct($streams);
		$this->clients = new SplObjectStorage();
		$this->onClient(function($client)
		{
			stream_set_blocking($client, true);
			stream_set_timeout($client, 1);
			$header = @fread($client, 4096);
			stream_set_blocking($client, false);
			if(!stristr($header, "Upgrade: WebSocket\r\n"))
			{
				fwrite($client, self::HTTP_400);
				fclose($client);
				return;
			}
			$host_pos = stripos($header, "Host:");
			if($host_pos === false)
			{
				fwrite($client, self::HTTP_400);
				fclose($client);
				return;
			}
			$key_pos = stripos($header, "Sec-WebSocket-Key:");
			if($key_pos === false)
			{
				fwrite($client, self::HTTP_400);
				fclose($client);
				return;
			}
			$request = explode(" ", explode("\r\n", $header)[0]);
			if(count($request) != 3)
			{
				fwrite($client, self::HTTP_400);
				fclose($client);
				return;
			}
			fwrite($client, "HTTP/1.1 101 WebSocket Upgrade\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nServer: hell-sh/websocket\r\nSec-WebSocket-Accept: ".Server::hashKey(explode("\r\n", trim(substr($header, $key_pos + 18)))[0])."\r\n\r\n");
			$con = new ClientConnection($client);
			$this->clients->attach($con);
			if($this->connect_function)
			{
				($this->connect_function)($con);
			}
		});
		$this->ws_loop = Asyncore::add(function()
		{
			$this->handle();
		});
	}

	function __destruct()
	{
		parent::__destruct();
		$this->ws_loop->remove();
	}

	public static function hashKey(string $key): string
	{
		return base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
	}

	/**
	 * Deals with all connected clients.
	 *
	 * @return Server $this
	 */
	public function handle(): Server
	{
		$frame_function = $this->frame_function ?? function()
			{
			};
		foreach($this->clients as $con)
		{
			/**
			 * @var ClientConnection $con
			 */
			if($con->status == Connection::STATUS_OPEN)
			{
				try
				{
					$frame = $con->readFrame(0);
					if($con->disconnect_after != 0 && $con->disconnect_after <= microtime(true))
					{
						$con->close();
						$con->status = Connection::STATUS_LOST;
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
			if($con->status != Connection::STATUS_OPEN)
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
