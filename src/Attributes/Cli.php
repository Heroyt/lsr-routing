<?php

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use Lsr\Enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Cli extends Route
{

	public function __construct(
		public string $path,
		public string $usage = '',
		public string $description = '',
		public array  $arguments = [],
	) {
		parent::__construct(RequestMethod::CLI, $path);
	}

}