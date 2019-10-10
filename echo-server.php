<?php
require "vendor/autoload.php";
use paws\
{ClientConnection, Frame, Server};
$stream = stream_socket_server("tcp://0.0.0.0:80", $errno, $errstr) or die(" {$errstr}\n");
$server = new Server($stream);
$server->frame_function = function(ClientConnection $con, Frame $frame)
{
	$con->writeFrame($frame)
		->flush();
};
do
{
	$start = microtime(true);
	$server->accept();
	$server->handle();
	if(($remaining = (0.050 - (microtime(true) - $start))) > 0)
	{
		time_nanosleep(0, $remaining * 1000000000);
	}
}
while(true);
