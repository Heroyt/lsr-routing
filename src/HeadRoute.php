<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use Lsr\Core\Requests\Response;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Message\ResponseInterface;

class HeadRoute extends Route
{
    public function __construct(
        public protected(set) RouteInterface $fallbackFor,
    ) {
        parent::__construct(RequestMethod::HEAD, [$this, 'respond']);
    }

    public static function createFallback(RouteInterface $fallbackFor): HeadRoute {
        $route = new self($fallbackFor);
        $route->path = $fallbackFor->getPath();
        $route->readablePath = $fallbackFor->getReadable();
        $route->setName($fallbackFor->getName());

        if ($fallbackFor instanceof Route) {
            $route->middleware(...$fallbackFor->getMiddleware());
        }

        return $route;
    }

    public function respond(): ResponseInterface {
        return Response::create();
    }
}
