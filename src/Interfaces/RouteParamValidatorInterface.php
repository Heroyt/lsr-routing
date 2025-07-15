<?php
declare(strict_types=1);

namespace Lsr\Core\Routing\Interfaces;

interface RouteParamValidatorInterface
{

	/**
	 * Validate a route parameter value.
	 */
	public function validate(mixed $value): bool;

}