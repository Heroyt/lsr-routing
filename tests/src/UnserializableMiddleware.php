<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class UnserializableMiddleware implements MiddlewareInterface
{
    public function __serialize(): array {
        throw new RuntimeException('This middleware cannot be serialized.');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        return $handler->handle($request);
    }
}
