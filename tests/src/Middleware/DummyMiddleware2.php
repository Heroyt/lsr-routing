<?php

namespace Lsr\Core\Routing\Tests\Mockup\Middleware;

use Lsr\Core\Routing\Middleware;
use Lsr\Interfaces\RequestInterface;

class DummyMiddleware2 implements Middleware
{

	/**
	 * @inheritDoc
	 */
	public function handle(RequestInterface $request) : bool {
		echo 'aaaaa'.PHP_EOL;
		return true;
	}
}