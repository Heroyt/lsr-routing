<?php

declare(strict_types=1);

namespace Lsr\Core\Routing;

use InvalidArgumentException;

final readonly class ServiceReference
{
    public string $service;

    private bool $typeReference;

    public function __construct(string $service, ?bool $typeReference = null) {
        $service = trim($service);
        if ($service === '') {
            throw new InvalidArgumentException('A route service reference must not be empty.');
        }

        $this->service = $service;
        $this->typeReference = $typeReference
            ?? (class_exists($service) || interface_exists($service));
    }

    public static function named(string $service): self {
        return new self($service, false);
    }

    public function isTypeReference(): bool {
        return $this->typeReference;
    }
}
