<?php
namespace paws;
abstract class Frame
{
	const OP_CODE = 2;
	/**
	 * @var string $data
	 */
	public $data;

	function __construct(string $data)
	{
		$this->data = $data;
	}
}
