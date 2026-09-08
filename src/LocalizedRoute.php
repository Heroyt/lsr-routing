<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use Lsr\Core\Routing\Sitemap\SitemapDefinition;
use Lsr\Core\Routing\Sitemap\SitemapMetadata;
use Lsr\Enums\RequestMethod;
use Lsr\Interfaces\RouteInterface;

class LocalizedRoute extends Route
{
    public function __construct(
        RequestMethod $type,
        string $locale,
        public protected(set) Route $parent,
    ) {
        /** @phpstan-ignore argument.type */
        parent::__construct($type, $parent->getHandler());
        $this->locale = $locale;
    }

    public static function createLocalized(
        RequestMethod $type,
        string $pathString,
        string $locale,
        Route $parent,
    ): LocalizedRoute {
        $route = new self($type, $locale, $parent);
        $route->path = array_filter(explode('/', $pathString), 'not_empty');
        $route->readablePath = $pathString;
        return $route;
    }

    public function compare(RouteInterface $route): bool {
        return $route instanceof self
            && $this->locale === $route->locale
            && $this->parent === $route->parent
            && parent::compare($route);
    }

    public function getName(): string {
        return $this->parent->getName();
    }

    public function getDomainReference(): ?string {
        return $this->parent->getDomainReference();
    }

    public function getDomain(): ?string {
        return $this->parent->getDomain();
    }

    /**
     * A localized path is part of one logical family, including its Router inclusion policy.
     */
    public function getSitemapMetadata(): SitemapMetadata {
        return $this->parent->getSitemapMetadata();
    }

    /**
     * Resolve the root dynamically so declaration order and cache hydration cannot freeze a copy.
     * Fluent metadata declarations on a localized wrapper intentionally update the whole family.
     */
    protected function sitemapDefinition(): SitemapDefinition {
        return $this->parent->sitemapDefinition();
    }

    /** Generic metadata remains shared even when the family is excluded from sitemaps. */
    protected function metadataDefinition(): RouteMetadata {
        return $this->parent->metadataDefinition();
    }

    public function getRouteForLocale(string $locale): ?RouteInterface {
        return $this->parent->getRouteForLocale($locale);
    }

    public function getCanonicalRoute(): RouteInterface {
        return $this->parent;
    }

    public function hasLocalizedRoutes(): bool {
        return true;
    }
}
