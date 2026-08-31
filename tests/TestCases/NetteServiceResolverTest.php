<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Tests\TestCases;

use Lsr\Core\Routing\DI\NetteServiceResolver;
use Lsr\Core\Routing\Exceptions\ServiceReferenceException;
use Lsr\Core\Routing\ServiceReference;
use Lsr\Core\Routing\Tests\Mockup\NamedMiddleware;
use Nette\DI\Container;
use PHPUnit\Framework\TestCase;

final class WiredTestContainer extends Container
{
    /** @param list<string> $services */
    public function wire(string $type, array $services): void
    {
        $this->wiring[$type] = [$services];
    }
}

final class NetteServiceResolverTest extends TestCase
{
    public function testClassReferenceResolvesExactlyOneService(): void
    {
        $middleware = new NamedMiddleware('typed');
        $container = $this->createContainer(['middleware.typed' => $middleware]);
        $container->wire(NamedMiddleware::class, ['middleware.typed']);
        $resolver = new NetteServiceResolver($container);

        $id = $resolver->getServiceId(new ServiceReference(NamedMiddleware::class));
        self::assertSame('middleware.typed', $id);
        self::assertSame($middleware, $resolver->getService($id));
    }

    public function testAmbiguousClassReferenceFailsDuringCompilationLookup(): void
    {
        $container = $this->createContainer([
            'middleware.first' => new NamedMiddleware('first'),
            'middleware.second' => new NamedMiddleware('second'),
        ]);
        $container->wire(NamedMiddleware::class, ['middleware.first', 'middleware.second']);
        $resolver = new NetteServiceResolver($container);

        $this->expectException(ServiceReferenceException::class);
        $this->expectExceptionMessage('found 2');
        $resolver->getServiceId(new ServiceReference(NamedMiddleware::class));
    }

    public function testNamedReferenceUsesTheExactServiceId(): void
    {
        $middleware = new NamedMiddleware('named');
        $container = $this->createContainer(['middleware.named' => $middleware]);
        $resolver = new NetteServiceResolver($container);

        $reference = new ServiceReference('middleware.named');
        self::assertSame('middleware.named', $resolver->getServiceId($reference));
        self::assertSame($middleware, $resolver->getService('middleware.named'));
    }

    /**
     * @param array<string,object> $services
     */
    private function createContainer(array $services): WiredTestContainer
    {
        $container = new WiredTestContainer();
        foreach ($services as $name => $service) {
            $container->addService($name, $service);
        }
        return $container;
    }
}
