<?php

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use Lsr\Enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Route
{

	public function __construct(
		public readonly RequestMethod $method = RequestMethod::GET,
		public readonly string        $path = '',
		public readonly string        $name = '',
	) {
		match (true) {
			empty($this->path) => throw new \InvalidArgumentException('Missing required argument - path'),
		};
	}

}