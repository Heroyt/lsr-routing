<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use LogicException;
use Lsr\Core\Requests\Response;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Stringable;

class AliasRoute extends Route
{
    public function __construct(
        RequestMethod $type,
        public protected(set) RouteInterface $redirectTo,
    ) {
        parent::__construct($type, [$this, 'redirect']);
    }

    public static function createAlias(RequestMethod $type, string $pathString, RouteInterface $redirectTo): AliasRoute {
        $route = new self($type, $redirectTo);
        $route->path = array_filter(explode('/', $pathString), 'not_empty');
        $route->readablePath = $pathString;
        return $route;
    }

    public function redirect(ServerRequestInterface $request): ResponseInterface {
        $path = $this->redirectTo->getPath();
        foreach ($path as $key => $part) {
            $part = preg_replace_callback(
                '/\{([^}]+)}/',
                static function (array $match) use ($request): string {
                    $value = $request->getAttribute($match[1]);
                    if ( ! is_scalar($value) && ! $value instanceof Stringable) {
                        throw new RuntimeException(sprintf('Missing route parameter "%s" for redirect.', $match[1]));
                    }
                    return rawurlencode((string) $value);
                },
                $part,
            );
            assert($part !== null);
            $path[$key] = $part;
        }

        $location = '/' . implode('/', $path);
        $query = $request->getUri()->getQuery();
        if ($query !== '') {
            $location .= '?' . $query;
        }
        if ($location === $request->getRequestTarget()) {
            throw new LogicException(sprintf('Route alias "%s" redirects to itself.', $this->getReadable()));
        }

        return Response::create(308, ['Location' => $location]);
    }

}
