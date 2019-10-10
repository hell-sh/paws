<?php
namespace paws;
use Exception;
use SplObjectStorage;
class Server
{
	const HTTP_400 = "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nServer: hell-sh/paws\r\n\r\n";
	/**
	 * The streams the server listens for new connections on.
	 *
	 * @var resource $streams
	 */
	public $streams;
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
	 * @see Server::createStream()
	 */
	function __construct(array $streams = [])
	{
		if($streams)
		{
			$this->streams = $streams;
		}
		$this->clients = new SplObjectStorage();
	}

	/**
	 * Creates a stream for a server to listen for new connections on.
	 *
	 * @param string $address e.g. "0.0.0.0:80"
	 * @param string|null $public_key_file Path to the file containing your PEM-encoded public key or null if you don't want encryption
	 * @param string|null $private_key_file Path to the file containing your PEM-encoded private key or null if you don't want encryption
	 * @return resource
	 * @throws Exception
	 * @see https://en.wikipedia.org/wiki/Privacy-Enhanced_Mail
	 */
	static function createStream(string $address, $public_key_file = null, $private_key_file = null)
	{
		if($public_key_file && $private_key_file)
		{
			$stream = stream_socket_server("tcp://".$address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create([
				"ssl" => [
					"verify_peer" => false,
					"allow_self_signed" => true,
					"local_cert" => $public_key_file,
					"local_pk" => $private_key_file,
					"ciphers" => "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384"
				]
			]));
		}
		else
		{
			$stream = stream_socket_server("tcp://".$address, $errno, $errstr);
		}
		if(!is_resource($stream))
		{
			throw new Exception($errstr);
		}
		return $stream;
	}

	/**
	 * Accepts new clients.
	 *
	 * @return Server $this
	 */
	function accept(): Server
	{
		foreach($this->streams as $stream)
		{
			while(($client = @stream_socket_accept($stream, 0)) !== false)
			{
				stream_set_timeout($client, 2);
				if(array_key_exists("ssl", stream_context_get_options($stream)))
				{
					@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
				}
				$header = @fread($client, 4096);
				stream_set_blocking($client, false);
				if(!stristr($header, "Upgrade: WebSocket\r\n"))
				{
					fwrite($client, self::HTTP_400);
					fclose($client);
					continue;
				}
				$host_pos = stripos($header, "Host:");
				if($host_pos === false)
				{
					fwrite($client, self::HTTP_400);
					fclose($client);
					continue;
				}
				$key_pos = stripos($header, "Sec-WebSocket-Key:");
				if($key_pos === false)
				{
					fwrite($client, self::HTTP_400);
					fclose($client);
					continue;
				}
				$request = explode(" ", explode("\r\n", $header)[0]);
				if(count($request) != 3)
				{
					fwrite($client, self::HTTP_400);
					fclose($client);
					continue;
				}
				fwrite($client, "HTTP/1.1 101 WebSocket Upgrade\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nServer: hell-sh/paws\r\nSec-WebSocket-Accept: ".Server::hashKey(explode("\r\n", trim(substr($header, $key_pos + 18)))[0])."\r\n\r\n");
				$con = new ClientConnection($client, $request[1]);
				$this->clients->attach($con);
				if($this->connect_function)
				{
					($this->connect_function)($con);
				}
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
