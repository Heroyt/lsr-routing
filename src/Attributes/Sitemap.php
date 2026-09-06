<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Attributes;

use Attribute;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Sitemap\SitemapChangeFrequency;

/** Declares sitemap metadata for the routes on a controller method. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Sitemap
{
    public function __construct(
        public ?string $name = null,
        public ?float $priority = null,
        public SitemapChangeFrequency|string|null $changefreq = null,
    ) {
    }

    /** @internal Shares validation and semantics with fluent declarations. */
    public function apply(Route $route): void
    {
        $route->sitemap($this->name);
        if ($this->priority !== null) {
            $route->priority($this->priority);
        }
        if ($this->changefreq !== null) {
            $route->changefreq($this->changefreq);
        }
    }
}
