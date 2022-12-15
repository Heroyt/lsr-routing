<?php

namespace Lsr\Core\Routing\Tests\Mockup\Controllers;

use Lsr\Core\Controller;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\Tests\Mockup\Middleware\DummyMiddleware;
use Lsr\Core\Routing\Tests\Mockup\Models\TestModel;
use Lsr\Core\Routing\Tests\Mockup\Test;
use Lsr\Core\Routing\Tests\Mockup\Test2;
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

	public function actionWithParams2(Request $request, Test $test) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR).PHP_EOL;
		$test->echo();
	}

	public function actionWithModel(Request $request, TestModel $model) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR).PHP_EOL.$model->name;
	}

	public function actionWithOptionalModel(Request $request, ?TestModel $model = null) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR).PHP_EOL.(!isset($model) ? 'empty' : $model->name);
	}

	public function actionWithMultipleModels(Request $request, TestModel $model1, TestModel $model2) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR).PHP_EOL.$model1->name.PHP_EOL.$model2->name;
	}

	public function actionWithInvalidParams(Request $request, string|int $id) : void {
		echo 'action: <'.$id.'> '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

	public function actionWithInvalidParams2(Request $request, object $id) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

	public function actionWithInvalidService(Request $request, Test2 $test) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

	public function actionWithInvalidOptionalService(Request $request, ?Test2 $test = null) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

}