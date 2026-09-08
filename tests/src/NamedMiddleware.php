<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class NamedMiddleware implements MiddlewareInterface
{
    public function __construct(public string $name) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        return $handler->handle($request);
    }
}
