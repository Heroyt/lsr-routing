<?php

namespace Lsr\Core\Routing\Exceptions;

use Exception;
use Lsr\Interfaces\RouteInterface;
use Throwable;

class DuplicateRouteException extends Exception
{

	public function __construct(public readonly RouteInterface $route1, public readonly RouteInterface $route2, int $code = 0, Throwable $previous = null) {
		parent::__construct('Duplicate route "'.$route1->getMethod()->value.' '.$route1->getReadable().'"', $code, $previous);
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