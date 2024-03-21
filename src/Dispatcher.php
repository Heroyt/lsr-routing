<?php

namespace Lsr\Core\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

readonly class Dispatcher implements RequestHandlerInterface
{

	/**
	 * @param iterable<Middleware|RequestHandlerInterface|callable> $queue
	 */
	public function __construct(
		private iterable $queue,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function handle(ServerRequestInterface $request): ResponseInterface {
		$current = current($this->queue);
		next($this->queue);

		if ($current instanceof Middleware) {
			return $current->process($request, $this);
		}

		if ($current instanceof RequestHandlerInterface) {
			return $current->handle($request);
		}

		if (is_callable($current)) {
			return $current($request);
		}

		throw new \RuntimeException(
			sprintf(
				'Invalid middleware queue entry: %s. Middleware must either be callable or implement %s.',
				$current,
				Middleware::class
			)
		);
	}
}