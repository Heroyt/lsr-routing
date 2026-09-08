<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\Mockup;

use Lsr\Core\Routing\Attributes\Connect;
use Lsr\Core\Routing\Attributes\Delete;
use Lsr\Core\Routing\Attributes\Domain;
use Lsr\Core\Routing\Attributes\Get;
use Lsr\Core\Routing\Attributes\Head;
use Lsr\Core\Routing\Attributes\Options;
use Lsr\Core\Routing\Attributes\Patch;
use Lsr\Core\Routing\Attributes\Post;
use Lsr\Core\Routing\Attributes\Put;
use Lsr\Core\Routing\Attributes\Route;
use Lsr\Core\Routing\Attributes\Trace;
use Lsr\Core\Routing\Attributes\Update;

#[Domain('class-host')]
final class DomainController
{
    #[Get('/attribute/class', name: 'domain.attribute.class')]
    public function inherited(): void {
    }

    #[Domain('method-host')]
    #[Get('/attribute/method', name: 'domain.attribute.method')]
    #[Get('/attribute/repeated', name: 'domain.attribute.repeated', domain: 'route-host')]
    public function overridden(): void {
    }

    #[Domain('method-host')]
    #[Route(path: '/attribute/route', name: 'domain.attribute.route', domain: 'route-host')]
    public function route(): void {
    }

    #[Connect('/attribute/connect', domain: 'route-host')]
    #[Delete('/attribute/delete', domain: 'route-host')]
    #[Head('/attribute/head', domain: 'route-host')]
    #[Options('/attribute/options', domain: 'route-host')]
    #[Patch('/attribute/patch', domain: 'route-host')]
    #[Post('/attribute/post', domain: 'route-host')]
    #[Put('/attribute/put', domain: 'route-host')]
    #[Trace('/attribute/trace', domain: 'route-host')]
    #[Update('/attribute/update', domain: 'route-host')]
    public function methods(): void {
    }
}
