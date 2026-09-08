<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup;

use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Lsr\Core\Routing\Route;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;

final class ValidatorOnlyRoute implements RouteInterface
{
    private function __construct(private Route $route) {
    }

    public static function create(RequestMethod $type, string $pathString, callable|array $handler): RouteInterface {
        return new self(Route::create($type, $pathString, $handler));
    }

    public static function compareRoutePaths(array $path1, array $path2): bool {
        return Route::compareRoutePaths($path1, $path2);
    }

    public function getHandler(): callable|array {
        return $this->route->getHandler();
    }

    public function getReadable(): string {
        return $this->route->getReadable();
    }

    public function getPath(): array {
        return $this->route->getPath();
    }

    public function getName(): string {
        return $this->route->getName();
    }

    public function getMethod(): RequestMethod {
        return $this->route->getMethod();
    }

    public function compare(RouteInterface $route): bool {
        return $this->route->compare($route);
    }

    public function param(string $name, RouteParamValidatorInterface ...$validators): static {
        $this->route->param($name, ...$validators);
        return $this;
    }

    public function acceptsParameter(string $name, mixed $value): bool {
        foreach ($this->route->paramValidators[$name] ?? [] as $validator) {
            if ( ! $validator->validate($value)) {
                return false;
            }
        }
        return true;
    }
}
