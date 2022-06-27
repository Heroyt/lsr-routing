<?php

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use Lsr\Enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Cli extends Route
{

	public function __construct(
		public readonly string $path = '',
		public readonly string $usage = '',
		public readonly string $description = '',
		public readonly array  $arguments = [],
	) {
		parent::__construct(RequestMethod::CLI, $path);
	}

}