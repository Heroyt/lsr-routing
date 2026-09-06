<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Sitemap;

/**
 * Resolved sitemap settings snapshot. Generic application metadata is available through Route::getMeta().
 */
final readonly class SitemapMetadata
{
    public function __construct(
        public bool $included,
        public ?string $name,
        public ?float $priority,
        public ?SitemapChangeFrequency $changefreq,
    ) {
    }
}
