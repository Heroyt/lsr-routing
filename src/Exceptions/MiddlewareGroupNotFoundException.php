<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Exceptions;

use RuntimeException;

final class MiddlewareGroupNotFoundException extends RuntimeException
{
    /**
     * @param array<non-empty-string, list<non-empty-string>> $references
     */
    public function __construct(public readonly array $references)
    {
        $details = [];
        foreach ($references as $group => $contexts) {
            $details[] = sprintf('%s (%s)', $group, implode(', ', $contexts));
        }

        parent::__construct('Unknown middleware groups: ' . implode('; ', $details));
    }
}
