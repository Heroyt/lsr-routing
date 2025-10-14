<?php
declare(strict_types=1);

namespace Lsr\Core\Routing;

use ArrayAccess;
use Countable;
use Iterator;
use Lsr\Core\Routing\Interfaces\RouteParamValidatorInterface;
use Stringable;

/**
 * @implements Iterator<string, RouteNode>
 * @implements ArrayAccess<non-empty-string, RouteNode>
 */
class RouteParameter implements ArrayAccess, Stringable, Countable, Iterator
{

	/**
	 * @param string                         $name
	 * @param array<string,RouteNode> $routes
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

	public function count(): int {
		return count($this->routes);
	}

	public function validate(mixed $value): bool {
		if (array_any($this->validators, static fn($validator) => !$validator->validate($value))) {
			return false;
		}
		return true;
	}

	/**
	 * Adds new validators for the parameter. Checks for duplicates.
	 */
	public function addValidators(RouteParamValidatorInterface ...$validators): void {
		foreach ($validators as $validator) {
			if (!in_array($validator, $this->validators, true)) {
				$this->validators[] = $validator;
			}
		}
	}

	/**
	 * @return RouteNode
	 */
	public function current(): mixed {
		$current = current($this->routes);
		if ($current === false) {
			throw new \RuntimeException('Invalid RouteParameter::$routes array access');
		}
		return $current;
	}

	public function next(): void {
		next($this->routes);
	}

	/**
	 * @return string
	 */
	public function key(): mixed {
		$key = key($this->routes);
		if ($key === null) {
			throw new \RuntimeException('Invalid RouteParameter::$routes array access');
		}
		return $key;
	}

	public function valid(): bool {
		return key($this->routes) !== null;
	}

	public function rewind(): void {
		reset($this->routes);
	}
}