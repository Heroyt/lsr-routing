<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Attributes;

use Attribute;

/** Excludes a method's routes, taking precedence over a Sitemap attribute. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SitemapExclude
{
}
