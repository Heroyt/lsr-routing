<?php

namespace Lsr\Core\Routing\Tests\Mockup\Middleware;

use Lsr\Core\Routing\Middleware;
use Lsr\Interfaces\RequestInterface;

class DummyMiddleware implements Middleware
{

	public function __construct(
		private readonly array $data
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function handle(RequestInterface $request) : bool {
		$request->request = $this->data;
		return true;
	}
}