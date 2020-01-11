<?php /** @noinspection PhpUnhandledExceptionInspection */
require_once "vendor/autoload.php";
use WebSocket\
{Server, ServerConnection, TextFrame};
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
