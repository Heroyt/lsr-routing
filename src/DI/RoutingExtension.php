<?php
declare(strict_types=1);

namespace Lsr\Core\Routing\DI;

use Lsr\Core\Routing\Router;
use Nette;
use Nette\DI\CompilerExtension;

/**
 * @property-read object{routeFiles: non-empty-string[], controllers: non-empty-string[]} $config
 */
class RoutingExtension extends CompilerExtension
{

	public function getConfigSchema(): Nette\Schema\Schema {
		return Nette\Schema\Expect::structure(
			[
				'routeFiles' => Nette\Schema\Expect::listOf(
					Nette\Schema\Expect::string()->assert(
						static fn(string $value) => file_exists($value),
						'Route file must be a valid file'
					)
				)->default([]),
				'controllers' => Nette\Schema\Expect::listOf(
					Nette\Schema\Expect::string()->assert(
						static fn(string $value) => file_exists($value),
						'Route controller must be a valid file'
					)
				)->default([]),
			]
		);
	}

	public function loadConfiguration(): void {
		$builder = $this->getContainerBuilder();

		$builder->addDefinition($this->name)
		        ->setType(Router::class)
		        ->setFactory(
			        Router::class,
			        [
				        '@cache',
				        $this->config->routeFiles,
				        $this->config->controllers,
			        ]
		        )
		        ->addSetup('setup')
		        ->setTags(['lsr', 'routing']);
	}

}