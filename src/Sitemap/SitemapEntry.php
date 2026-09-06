<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Sitemap;

use Lsr\Core\Routing\Route;

/**
 * One canonical sitemap path and its language alternatives, not rendered URLs.
 *
 * Resolve each route with application-owned parameters and an absolute origin.
 * Emit a separate URL entry for every available translation and the same set of
 * xhtml:link alternatives (including self) on each entry. An application that
 * filters unavailable translations must filter the alternative map as well.
 */
final readonly class SitemapEntry
{
    /** @param array<string,Route> $alternates Normalized hreflang => canonical route. */
    public function __construct(
        public Route $route,
        public SitemapMetadata $metadata,
        public array $alternates,
    ) {
    }
}
