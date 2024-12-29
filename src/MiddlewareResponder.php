<?php

namespace Lsr\Core\Routing;

use Lsr\Core\Requests\Dto\ErrorResponse;
use Lsr\Core\Requests\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @phpstan-ignore trait.unused */
trait MiddlewareResponder
{
	protected function respond(ServerRequestInterface $request, ErrorResponse $error): ResponseInterface {
		$response = Response::create(403);

		// Find HTTP Accept headers
		$types = [];
		foreach ($request->getHeader('Accept') as $value) {
			$types[] = strtolower(trim(explode(';', $value, 2)[0]));
		}

		// Determine the correct type to send
		if (in_array('text/plain', $types, true)) {
			return $response->withStringBody($error->title);
		}
		if (in_array('application/json', $types, true)) {
			return $response->withJsonBody($error);
		}
		if (in_array('application/xml', $types, true)) {
			return $response->withXmlBody($error);
		}
		// Default to JSON
		return $response->withJsonBody($error);
	}
}