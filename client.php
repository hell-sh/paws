<?php /** @noinspection PhpUnhandledExceptionInspection */
if(empty($argv[1]))
{
	die("Syntax: php client.php <url>\n");
}
require "vendor/autoload.php";
use hellsh\pai;
use paws\
{ServerConnection, TextFrame};
echo "Connecting...";
$con = new ServerConnection($argv[1]);
echo " Connection established.";
pai::init();
echo " Client ready.\n";
do
{
	$start = microtime(true);
	if($frame = $con->readFrame(0))
	{
		echo $frame."\n";
	}
	if(pai::hasLine())
	{
		$con->writeFrame(new TextFrame(pai::getLine()))
			->flush();
	}
	if(($remaining = (0.001 - (microtime(true) - $start))) > 0)
	{
		time_nanosleep(0, $remaining * 1000000000);
	}
}
while($con->isOpen());
echo "\nConnection closed.\n";
