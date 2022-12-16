<?php

namespace Lsr\Core\Routing\Tests\Mockup\Controllers;

use Lsr\Core\Controller;
use Lsr\Core\Requests\Request;
use Lsr\Core\Routing\Attributes\Cli;
use Lsr\Core\Routing\Attributes\Delete;
use Lsr\Core\Routing\Attributes\Get;
use Lsr\Core\Routing\Attributes\Post;
use Lsr\Core\Routing\Attributes\Put;
use Lsr\Core\Routing\Attributes\Update;

class DummyController2 extends Controller
{

	#[Get('registered/action'), Post('registered/action', 'registered-post'), Delete('registered/action'), Update('registered/action')]
	public function actionRegistered(Request $request) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

	#[Put('registered/action2')]
	public function actionRegistered2(Request $request) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

	#[Cli('cli/action', 'cli/action', 'Cli test action', [])]
	public function actionRegisteredCli(Request $request) : void {
		echo 'action: '.json_encode($request->request, JSON_THROW_ON_ERROR);
	}

}