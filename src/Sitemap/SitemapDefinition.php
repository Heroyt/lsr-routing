<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Sitemap;

use InvalidArgumentException;

/**
 * Mutable declarations linked to live group defaults, never to Router policy.
 *
 * Null fields inherit independently. Application metadata is stored separately on routes.
 *
 * @internal
 * @phpstan-type SitemapDefinitionData array{included: ?bool, name: ?string, priority: ?float, changefreq: ?string}
 */
final class SitemapDefinition
{
    private ?bool $included = null;
    private ?string $name = null;
    private ?float $priority = null;
    private ?SitemapChangeFrequency $changefreq = null;

    public function __construct(private ?self $parent = null)
    {
    }

    /**
     * Only route-to-group or child-to-parent-group links are established by the routing API.
     */
    public function setParent(self $parent): void
    {
        $this->parent = $parent;
    }

    public function sitemap(?string $name = null): void
    {
        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('A sitemap name must not be empty.');
        }
        $this->included = true;
        if ($name !== null) {
            $this->name = $name;
        }
    }

    public function sitemapExclude(): void
    {
        $this->included = false;
    }

    public function priority(float $priority): void
    {
        if (!is_finite($priority) || $priority < 0.0 || $priority > 1.0) {
            throw new InvalidArgumentException('Sitemap priority must be finite and between 0 and 1.');
        }
        $this->priority = $priority;
    }

    public function changefreq(SitemapChangeFrequency|string $frequency): void
    {
        $this->changefreq = is_string($frequency)
            ? SitemapChangeFrequency::tryFrom($frequency)
                ?? throw new InvalidArgumentException('Invalid sitemap change frequency.')
            : $frequency;
    }


    /**
     * Resolve ancestry without resolving the Router's inclusion default.
     *
     * @return SitemapDefinitionData
     */
    public function export(): array
    {
        $parent = $this->parent?->export();
        return [
            'included' => $this->included ?? $parent['included'] ?? null,
            'name' => $this->name ?? $parent['name'] ?? null,
            'priority' => $this->priority ?? $parent['priority'] ?? null,
            'changefreq' => $this->changefreq->value ?? $parent['changefreq'] ?? null,
        ];
    }

    /**
     * Atomically restore flattened cache declarations, dropping live group ancestry.
     * Undefined inclusion remains null so a different Router policy can reuse the cache.
     *
     * @param array<array-key, mixed> $definition
     */
    public function restore(array $definition): void
    {
        if (
            count($definition) !== 4
            || !array_key_exists('included', $definition)
            || !array_key_exists('name', $definition)
            || !array_key_exists('priority', $definition)
            || !array_key_exists('changefreq', $definition)
        ) {
            throw new InvalidArgumentException('Invalid sitemap cache definition.');
        }

        $included = $definition['included'];
        $name = $definition['name'];
        $priority = $definition['priority'];
        $frequency = $definition['changefreq'];
        if (
            ($included !== null && !is_bool($included))
            || ($name !== null && !is_string($name))
            || ($priority !== null && !is_float($priority) && !is_int($priority))
            || ($frequency !== null && !is_string($frequency))
        ) {
            throw new InvalidArgumentException('Invalid sitemap cache definition values.');
        }

        $restored = new self();
        if ($name !== null) {
            $restored->sitemap($name);
        }
        $restored->included = $included;
        if ($priority !== null) {
            $restored->priority($priority);
        }
        if ($frequency !== null) {
            $restored->changefreq($frequency);
        }

        $this->included = $restored->included;
        $this->name = $restored->name;
        $this->priority = $restored->priority;
        $this->changefreq = $restored->changefreq;
        $this->parent = null;
    }
}
