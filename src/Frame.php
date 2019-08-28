<?php
namespace paws;
abstract class Frame
{
	/**
	 * @var string $data
	 */
	public $data;
	const OP_CODE = 2;

	function __construct(string $data)
	{
		$this->data = $data;
	}
}
