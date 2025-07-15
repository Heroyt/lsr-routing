<?php
declare(strict_types=1);

namespace Lsr\Core\Routing;

use ArrayAccess;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Stringable;

/**
 * @phpstan-import-type RouteNode from Router
 */
class RouteParameter implements ArrayAccess, Stringable
{

	/**
	 * @param string                         $name
	 * @param RouteNode                      $routes
	 * @param RouteParamValidatorInterface[] $validators
	 */
	public function __construct(
		public readonly string $name,
		public array           $routes = [],
		public array           $validators = [],
		public bool            $optional = false,
		public ?string         $default = null,
	) {
	}

	public function offsetExists(mixed $offset): bool {
		return isset($this->routes[$offset]);
	}

	public function offsetGet(mixed $offset): mixed {
		return $this->routes[$offset];
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		$this->routes[$offset] = $value;
	}

	public function offsetUnset(mixed $offset): void {
		unset($this->routes[$offset]);
	}

	public function __toString(): string {
		return $this->name;
	}

	public function validate(mixed $value): bool {
		if (array_any($this->validators, static fn($validator) => !$validator->validate($value))) {
			return false;
		}
		return true;
	}

	public function addValidators(RouteParamValidatorInterface ...$validators): void {
		foreach ($validators as $validator) {
			if (!in_array($validator, $this->validators, true)) {
				$this->validators[] = $validator;
			}
		}
	}
}