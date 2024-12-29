<?php
declare(strict_types=1);

namespace Lsr\Core\Routing;

use Lsr\Core\Requests\Response;
use Lsr\Enums\RequestMethod;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LocalizedRoute extends Route
{

	public function __construct(
		RequestMethod          $type,
		public readonly string $locale,
		public protected(set) Route $parent,
	) {
		parent::__construct($type, [$this, 'redirect']);
	}

	public static function createLocalized(RequestMethod $type, string $pathString, string $locale, Route $parent): LocalizedRoute {
		$route = new self($type, $locale, $parent);
		$route->path = array_filter(explode('/', $pathString), 'not_empty');
		$route->readablePath = $pathString;
		return $route;
	}

	public function redirect(ServerRequestInterface $request): ResponseInterface {
		$lang = $request->getAttribute('lang');
		$redirectTo = $this->parent;
		if (is_string($lang)) {
			// Find localized route to redirect to
			$redirectTo = $this->parent->localizedRoutes[$lang] ?? $this->parent;
		}

		$path = $redirectTo->getPath();
		foreach ($path as $key => $part) {
			if (preg_match('/{([^}]+)}\/?/', $part, $matches) > 0) {
				$value = $request->getAttribute($matches[1]);
				if ($value !== null) {
					$path[$key] = $value;
				}
			}
		}

		return Response::create(
			300, // Multiple choices
			[
				'Location' => '/'.implode('/', $path),
			]
		);
	}

}