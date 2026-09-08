<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Exceptions;

use LogicException;

final class MiddlewareGroupsResolvedException extends LogicException
{
    public function __construct() {
        parent::__construct('Middleware groups have already been resolved for this router.');
    }
}
