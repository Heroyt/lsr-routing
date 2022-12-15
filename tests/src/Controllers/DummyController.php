<?php

namespace Lsr\Core\Routing\Tests\Mockup\Controllers;

use Lsr\Core\Controller;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\Tests\Mockup\Middleware\DummyMiddleware;
use Lsr\Core\Templating\Latte;
use Lsr\Interfaces\RequestInterface;

class DummyController extends Controller
{

	public function __construct(Latte $latte) {
		parent::__construct($latte);
		$this->middleware[] = new DummyMiddleware(['controller' => 'Middleware']);
	}

	public function init(RequestInterface $request) : void {
		parent::init($request);
		echo 'Controller init'.PHP_EOL;
	}

	public function action(Request $request) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

	public function actionWithParams(Request $request, int $id) : void {
		echo 'action: <'.$id.'> '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

}