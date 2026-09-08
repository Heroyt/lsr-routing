<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\DI;

use Lsr\Core\Routing\Exceptions\ServiceReferenceException;
use Lsr\Core\Routing\Interfaces\ServiceResolverInterface;
use Lsr\Core\Routing\ServiceReference;
use Nette\DI\Container;
use Throwable;

final readonly class NetteServiceResolver implements ServiceResolverInterface
{
    public function __construct(private Container $container) {
    }

    public function getServiceId(ServiceReference $reference): string {
        if ( ! $reference->isTypeReference()) {
            if ( ! $this->container->hasService($reference->service)) {
                throw new ServiceReferenceException(
                    sprintf('Route service "%s" is not registered in the DI container.', $reference->service),
                );
            }

            return $reference->service;
        }

        if ( ! class_exists($reference->service) && ! interface_exists($reference->service)) {
            throw new ServiceReferenceException(
                sprintf('Route service type "%s" does not exist.', $reference->service),
            );
        }

        $services = $this->container->findByType($reference->service);
        if (count($services) !== 1) {
            throw new ServiceReferenceException(
                sprintf(
                    'Route service type "%s" must resolve to exactly one service; found %d%s.',
                    $reference->service,
                    count($services),
                    $services === [] ? '' : ': ' . implode(', ', $services),
                ),
            );
        }

        return reset($services);
    }

    public function getService(string $serviceId): object {
        try {
            return $this->container->getService($serviceId);
        } catch (Throwable $exception) {
            throw new ServiceReferenceException(
                sprintf('Unable to resolve route service "%s".', $serviceId),
                previous: $exception,
            );
        }
    }
}
