<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Domain
{
    public function __construct(public string $domain) {
    }
}
