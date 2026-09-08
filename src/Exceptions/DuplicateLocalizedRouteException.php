<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Exceptions;

use Lsr\Interfaces\RouteInterface;
use RuntimeException;

class DuplicateLocalizedRouteException extends RuntimeException
{
    public function __construct(RouteInterface $route, string $locale) {
        parent::__construct(
            sprintf('Route "%s" already has a canonical path for locale "%s".', $route->getName(), $locale),
        );
    }
}
