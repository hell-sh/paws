<?php /** @noinspection PhpUnhandledExceptionInspection */
if(empty($argv[1]))
{
	die("Syntax: php client.php <url>\n");
}
require "vendor/autoload.php";
use Asyncore\
{Asyncore, Condition, stdin};
use WebSocket\
{Connection, ServerConnection, TextFrame};
echo "Connecting...";
$con = new ServerConnection($argv[1]);
echo " Connection established.";
stdin::init(function(string $line) use (&$con)
{
	$con->writeFrame(new TextFrame($line))
		->flush();
}, false);
echo " Client ready.\n";
$open_condition = new Condition(function() use (&$con)
{
	return $con->status == Connection::STATUS_OPEN;
});
$open_condition->add(function() use (&$con)
{
	if($frame = $con->readFrame(0))
	{
		echo $frame."\n";
	}
}, 0.001);
Asyncore::loop();
echo "\nConnection closed.\n";
