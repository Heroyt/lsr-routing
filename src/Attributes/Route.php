<?php

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use InvalidArgumentException;
use Lsr\Enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Route
{

	/**
	 * @codeCoverageIgnore
	 */
	public function __construct(
		public RequestMethod $method = RequestMethod::GET,
		public string        $path = '',
		public string        $name = '',
	) {
		if (empty($this->path)) {
			throw new InvalidArgumentException('Missing required argument - path');
		}
	}

}