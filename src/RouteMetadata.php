<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use InvalidArgumentException;
use ReflectionReference;

/**
 * Application-owned route metadata with live group inheritance.
 *
 * Local keys replace inherited values shallowly; null is a value. All stored
 * values are detached from caller references, so returned arrays are snapshots.
 * No sitemap declarations or inclusion policy participate in this storage.
 *
 * @internal
 */
final class RouteMetadata
{
    /** @var array<string,mixed> */
    private array $data = [];

    public function __construct(private ?self $parent = null)
    {
    }

    public function setParent(self $parent): void
    {
        $this->parent = $parent;
    }

    /** @param array<array-key,mixed> $data */
    public function merge(array $data): void
    {
        $this->data = array_replace($this->data, self::copyMetadata($data));
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->parent === null ? $this->data : array_replace($this->parent->all(), $this->data);
    }

    /**
     * Restore a flattened cache snapshot, replacing local values and ancestry.
     *
     * @param array<array-key,mixed> $data
     */
    public function restore(array $data): void
    {
        $this->data = self::copyMetadata($data);
        $this->parent = null;
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<string,mixed>
     */
    private static function copyMetadata(array $data): array
    {
        $values = [];
        foreach ($data as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Route metadata must use string keys.');
            }
            $values[$key] = self::copyMetadataValue($value);
        }
        return $values;
    }

    /**
     * Detach nested references and reject cycles. Repeated non-cyclic references
     * in separate branches are allowed and copied independently.
     *
     * @param array<string,true> $ancestors Array-reference IDs on the current traversal branch.
     */
    private static function copyMetadataValue(mixed $value, array $ancestors = []): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('Route metadata values must be scalar, null, or nested arrays.');
        }

        $copy = [];
        foreach ($value as $key => $child) {
            $reference = is_array($child) ? ReflectionReference::fromArrayElement($value, $key) : null;
            $id = $reference?->getId();
            if ($id !== null) {
                if (isset($ancestors[$id])) {
                    throw new InvalidArgumentException('Route metadata must not contain cyclic arrays.');
                }
                $ancestors[$id] = true;
            }
            $copy[$key] = self::copyMetadataValue($child, $ancestors);
            if ($id !== null) {
                unset($ancestors[$id]);
            }
        }
        return $copy;
    }
}
