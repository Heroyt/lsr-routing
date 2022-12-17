<?php

namespace Lsr\Core\Routing\Tests\Mockup\Middleware;

use Lsr\Core\Routing\Middleware;
use Lsr\Interfaces\RequestInterface;

class DummyMiddleware3 implements Middleware
{

	/**
	 * @inheritDoc
	 */
	public function handle(RequestInterface $request) : bool {
		echo 'bbbbb'.PHP_EOL;
		return true;
	}
}