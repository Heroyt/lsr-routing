<?php
/**
 * @author Tomáš Vojík <xvojik00@stud.fit.vutbr.cz>, <vojik@wboy.cz>
 */

namespace Lsr\Core\Routing\Tracy;

use Lsr\Core\App;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\CliRoute;
use Lsr\Core\Routing\Route;
use Lsr\Core\Routing\Router;
use Tracy\Dumper;
use Tracy\IBarPanel;

class RoutingTracyPanel implements IBarPanel
{
	/**
	 * @inheritDoc
	 */
	public function getTab() : string {
		return <<<HTML
        <span title="Routing">
            <svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="512" height="512" x="0" y="0" viewBox="0 0 480 480"
                 style="enable-background:new 0 0 512 512" xml:space="preserve" class=""><g>
        <g xmlns="http://www.w3.org/2000/svg">
                    <path d="M352,208c-26.464,0-48,21.536-48,48s21.536,48,48,48s48-21.536,48-48S378.464,208,352,208z M352,288     c-17.648,0-32-14.352-32-32s14.352-32,32-32s32,14.352,32,32S369.648,288,352,288z"
                          fill="#000000" data-original="#000000" style="" class=""></path>
                    <path d="M352,128c-70.576,0-128,56.864-128,126.768c0,30.272,22.256,80.096,66.128,148.096     c11.28,17.472,21.936,32.976,30.528,45.12H86.64C65.328,448,48,430.048,48,408c0-22.048,17.328-40,38.64-40h31.216     c38.944,0,70.624-32.304,70.624-72c0-36.56-26.96-66.496-61.616-71.072c5.168-7.312,10.992-15.744,17.072-24.992     C192,127.008,192,101.824,192,93.536C192,41.968,148.944,0,96,0C43.056,0,0,41.968,0,93.536c0,8.288,0,33.472,48.048,106.384     c18.144,27.6,34.592,48.96,35.28,49.84c0.192,0.24,0.496,0.336,0.704,0.576c1.056,1.216,2.304,2.16,3.68,3.008     c0.56,0.352,1.024,0.816,1.632,1.088C91.376,255.392,93.6,256,96,256h21.856c21.296,0,38.624,17.952,38.624,40     c0,22.048-17.328,40-38.624,40H86.64C47.68,336,16,368.304,16,408c0,39.696,31.68,72,70.64,72H352     c2.416,0,4.656-0.624,6.704-1.584c0.608-0.272,1.056-0.736,1.616-1.088c1.392-0.864,2.64-1.824,3.696-3.072     c0.208-0.256,0.544-0.352,0.736-0.608c0.96-1.28,23.808-31.584,49.104-70.784C457.744,334.88,480,285.04,480,254.768     C480,184.864,422.576,128,352,128z M117.232,182.336c-7.744,11.776-15.264,22.48-21.232,30.768     c-5.968-8.288-13.488-18.992-21.232-30.784C36.176,123.744,32,100.72,32,93.536C32,59.616,60.704,32,96,32s64,27.616,64,61.536     C160,100.72,155.824,123.744,117.232,182.336z M387.008,385.52c-13.248,20.528-26.016,38.816-35.008,51.36     c-8.992-12.56-21.744-30.832-35.008-51.36C263.92,303.264,256,268.288,256,254.768C256,202.512,299.056,160,352,160     c52.944,0,96,42.512,96,94.768C448,268.288,440.08,303.264,387.008,385.52z"
                          fill="#000000" data-original="#000000" style="" class=""></path>
                    <path d="M96,64c-17.648,0-32,14.352-32,32s14.352,32,32,32s32-14.352,32-32S113.648,64,96,64z M96,112c-8.832,0-16-7.184-16-16     s7.168-16,16-16c8.832,0,16,7.184,16,16S104.832,112,96,112z"
                          fill="#000000" data-original="#000000" style="" class=""></path>
        </g>
            </svg>
            <span class="tracy-label">Routing</span>
        </span>
        HTML;

	}

	/**
	 * @inheritDoc
	 */
	public function getPanel() : string {
        $routes = $this->formatRoutes(['' => Router::$availableRoutes]);
        $requestObj = App::getInstance()->getRequest();
        $route = $requestObj->getRoute();
        if ($requestObj instanceof Request) {
            $request = $requestObj->request;
            $params = $requestObj->params;
            $path = $requestObj->getPath();
        }
        else {
            $request = $_REQUEST;
            $params = [];
            $path = explode('/', $requestObj->getUri()->getPath());
        }

        $requestDump = Dumper::toHtml($request);
        $pathDump = Dumper::toHtml($path);
        $paramsDump = Dumper::toHtml($params);
        $routesDump = Dumper::toHtml($routes);
        $routeDump = '';
        if (isset($route)) {
            $routePath = empty($route->path) ? '/' : implode('/', $route->path);
            $routeDump = <<<HTML
            <div class="p-3 my-2 rounded border">
                <h5 class="fs-5">Route</h5>
                <p><strong>Name:</strong> {$route->getName()}</p>
                <p><strong>Path:</strong> $routePath</p>
            </div>
            HTML;

        }

        return <<<HTML
        <h1>Routing</h1>
        <div class="tracy-inner">
            <div class="tracy-inner-container">
                <div class="p-3 my-2 rounded border">
                    <h5 class="fs-5">Request</h5>
                    {$requestDump}
                </div>
                <div class="p-3 my-2 rounded border">
                    <h5 class="fs-5">Path</h5>
                    {$pathDump}
                </div>
                <div class="p-3 my-2 rounded border">
                    <h5 class="fs-5">Parameters</h5>
                    {$paramsDump}
                </div>
                {$routeDump}
                <div class="p-3 my-2 rounded border">
                    <h5 class="fs-5">Available routes</h5>
                    {$routesDump}
                </div>
            </div>
        </div>
        HTML;
	}

	/**
	 * Formats routing array to more readable format
	 *
	 * @param array $routes
	 *
	 * @return array
	 */
	private function formatRoutes(array $routes) : array {
		$formatted = [];
		foreach ($routes as $key => $route) {
			if ($route instanceof CliRoute) {
				continue;
			}
			if (count($route) === 1 && ($route[0] ?? null) instanceof Route) {
				$name = $route[0]->getName();
				$formatted[$key] = (!empty($name) ? $name.': ' : '').$this->formatHandler($route[0]->getHandler());
			}
			else if (is_array($routes)) {
				$formatted[$key.'/'] = $this->formatRoutes($route);
			}
		}
		return $formatted;
	}

	/**
	 * Formats any type of handler to a string
	 *
	 * @param callable|array $handler
	 *
	 * @return string
	 */
	private function formatHandler(callable|array $handler) : string {
		if (is_string($handler)) {
			return $handler.'()';
		}
		if (is_array($handler)) {
			$class = array_shift($handler);
			return $class.'::'.implode('()->', $handler).'()';
		}
		return 'closure';
	}

}