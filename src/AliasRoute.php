<?php
declare(strict_types=1);

namespace Lsr\Core\Routing;

use Lsr\Core\Requests\Response;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Message\ResponseInterface;

class AliasRoute extends Route
{

	public function __construct(
		RequestMethod $type,
		public protected(set) RouteInterface $redirectTo,
	) {
		parent::__construct($type, [$this, 'redirect']);
	}

	public static function createAlias(RequestMethod $type, string $pathString, RouteInterface $redirectTo): static {
		$route = new self($type, $redirectTo);
		$route->path = array_filter(explode('/', $pathString), 'not_empty');
		$route->readablePath = $pathString;
		return $route;
	}

	public function redirect() : ResponseInterface {
		return Response::create(
			308, // Permanent redirect
			[
				'Location' => $this->redirectTo->getPath(),
			]
		);
	}

}