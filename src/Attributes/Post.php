<?php

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use Lsr\Enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Post extends Route
{

	public function __construct(
		public readonly string $path = '',
		public readonly string $name = '',
	) {
		parent::__construct(RequestMethod::POST, $path, $this->name);
	}

}