<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Interfaces;

/** Optional host constraint; custom RouteInterface implementations need not implement it. */
interface DomainRouteInterface
{
    /** Return the resolved hostname, or null for an unrestricted route. */
    public function getDomain(): ?string;
}
