<?php
/** @noinspection PhpUnhandledExceptionInspection */
require_once "vendor/autoload.php";
use paws\Server;
use paws\ServerConnection;
use paws\TextFrame;
class PawsTest
{
	function testHashKey()
	{
		Nose::assertEquals(Server::hashKey("aGVsbC1zaC9wd3MhISExIQ=="), "MkwROKXtaESs+lrNCtau8W2X42M=");
	}

	function testClientAgainstRealServer()
	{
		$c = new ServerConnection("wss://demos.kaazing.com/echo");
		$c->writeFrame(new TextFrame("Hello, world!"))
		  ->flush();
		Nose::assertEquals($c->readFrame()->data, "Hello, world!");
	}
}
