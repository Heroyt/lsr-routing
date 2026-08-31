<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Interfaces;

use Lsr\Core\Routing\ServiceReference;

interface ServiceResolverInterface
{
    public function getServiceId(ServiceReference $reference): string;

    public function getService(string $serviceId): object;
}
