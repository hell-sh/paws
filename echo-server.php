<?php /** @noinspection PhpUnhandledExceptionInspection */
require "vendor/autoload.php";
use WebSocket\
{ClientConnection, Frame, Server};
$server = new Server([
	Server::createStream("0.0.0.0:80"),
	Server::createStream("0.0.0.0:443", "server.crt", "server.key")
]);
$server->frame_function = function(ClientConnection $con, Frame $frame)
{
	$con->writeFrame($frame)
		->flush();
};
echo "Listening for connections on ws://localhost:80 and wss://localhost:443\n";
\Asyncore\Asyncore::loop();
