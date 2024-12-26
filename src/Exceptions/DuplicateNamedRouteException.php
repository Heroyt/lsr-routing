<?php

namespace Lsr\Core\Routing\Exceptions;

use Lsr\Interfaces\RouteInterface;
use Throwable;

class DuplicateNamedRouteException extends DuplicateRouteException
{

	public function __construct(RouteInterface $original, RouteInterface $new, int $code = 0, ?Throwable $previous = null) {
		parent::__construct($original, $new, $code, $previous);
		$this->message = 'Duplicate named route "'.$original->getName().': '.$original->getMethod()->value.' '.$original->getReadable().'"';
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function getRoutesPrinted() : string {
		return
			$this->route1->getMethod()->value.' '.$this->route1->getReadable().' '.$this->printCallable($this->route1->getHandler()).PHP_EOL.
			$this->route2->getMethod()->value.' '.$this->route2->getReadable().' '.$this->printCallable($this->route2->getHandler()).PHP_EOL;
	}

	/**
	 * @codeCoverageIgnore
	 */
	private function printCallable(array|callable $callable) : string {
		if (is_array($callable)) {
			$str = '';
			if (is_object($callable[0])) {
				$str .= $callable[0]::class;
			}
			else {
				$str .= $callable[0];
			}
			$str .= '::'.$callable[1].'()';
			return $str;
		}
		if (is_string($callable)) {
			return $callable;
		}
		return 'Closure()';
	}
}