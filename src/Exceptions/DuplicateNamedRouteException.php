<?php

namespace Lsr\Core\Routing\Exceptions;

use Lsr\Interfaces\RouteInterface;
use Throwable;

class DuplicateNamedRouteException extends DuplicateRouteException
{

	public function __construct(RouteInterface $original, RouteInterface $new, int $code = 0, ?Throwable $previous = null) {
		parent::__construct($original, $new, $code, $previous);
		$this->message = sprintf(
			'Duplicate named route "%s: %s %s"',
			$original->getName(),
			$original->getMethod()->value,
			$original->getReadable(),
		);
	}

	public function getRoutesPrinted(): string {
		return
			$this->route1->getMethod()->value . ' ' . $this->route1->getReadable() . ' ' . $this->printCallable(
				$this->route1->getHandler()
			) . PHP_EOL .
			$this->route2->getMethod()->value . ' ' . $this->route2->getReadable() . ' ' . $this->printCallable(
				$this->route2->getHandler()
			) . PHP_EOL;
	}
}