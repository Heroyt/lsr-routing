<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Domain;

use InvalidArgumentException;

/** Host-only constraints: schemes, ports, user information and wildcards are not domains. */
final class Hostname
{
    public static function normalize(string $host): string {
        $ip = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($ip);
            assert($packed !== false);
            return '[' . inet_ntop($packed) . ']';
        }

        $normalized = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);
        if (
            $normalized === ''
            || str_ends_with($normalized, '.')
            || strlen($normalized) > 253
            || filter_var($normalized, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new InvalidArgumentException(sprintf('Invalid routing hostname "%s". Use a hostname without scheme, port or path.', $host));
        }
        return $normalized;
    }
}
