<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing;


use Lsr\Core\Request;

interface Middleware
{

	/**
	 * Handles a request
	 *
	 * @param Request $request
	 *
	 * @return bool
	 */
	public function handle(Request $request) : bool;

}
