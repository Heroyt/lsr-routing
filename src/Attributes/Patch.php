<?php

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use Lsr\Enums\RequestMethod;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Patch extends Route
{
    public function __construct(
        public string $path,
        public string $name = '',
    ) {
        parent::__construct(RequestMethod::PATCH, $path, $this->name);
    }
}
