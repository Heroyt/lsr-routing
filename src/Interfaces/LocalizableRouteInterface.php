<?php
declare(strict_types=1);

namespace Lsr\Core\Routing\Interfaces;

use Lsr\Interfaces\RouteInterface;

/**
 * A route with canonical path variants selected by locale.
 */
interface LocalizableRouteInterface extends RouteInterface
{
	/**
	 * Get the locale represented by this route path.
	 *
	 * A null locale means the path is locale-neutral.
	 */
	public function getLocale(): ?string;

	/**
	 * Get the exact path variant for a locale.
	 */
	public function getRouteForLocale(string $locale): ?RouteInterface;

	/**
	 * Get the logical route dispatched for this path.
	 */
	public function getCanonicalRoute(): RouteInterface;

	public function hasLocalizedRoutes(): bool;
}
