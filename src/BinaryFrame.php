<?php
namespace WebSocket;
class BinaryFrame extends Frame
{
	function __toString()
	{
		return "{BinaryFrame}";
	}
}
