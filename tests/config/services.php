<?php

declare(strict_types=1);

$services = [
    ROOT . '../services.neon',
    ROOT . '../vendor/lsr/logging/services.neon',
    ROOT . '../vendor/lsr/core/services.neon',
    ROOT . 'config/services.neon',
];
return $services;
