<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Attributes;

use Attribute;

/** Application-owned metadata for every route on a controller method, regardless of sitemap participation. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Meta
{
    /** @param array<string,mixed> $data Values follow the same cache-safe contract as Route::meta(). */
    public function __construct(public array $data) {
    }
}
