<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup;

use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;

final readonly class NamedValidator implements RouteParamValidatorInterface
{
    public function __construct(public string $name) {
    }

    public function validate(mixed $value): bool {
        return true;
    }
}
