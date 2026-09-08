<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\DI;

use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Lsr\Core\Routing\Commands\RouteCacheCleanCommand;
use Lsr\Core\Routing\Commands\RouteCacheCompileCommand;
use Lsr\Core\Routing\Router;
use Nette;
use Nette\DI\CompilerExtension;
use Symfony\Component\Console\Command\Command;
use UnexpectedValueException;

/**
 * @property object{
 *     routeFiles: non-empty-string[],
 *     controllers: non-empty-string[],
 *     cache: object{file:string,autoCompile:bool,checkTimestamps:bool,commands:bool},
 *     sitemap: object{defaultIncluded:bool},
 * } $config
 */
class RoutingExtension extends CompilerExtension
{
    public function getConfigSchema(): Nette\Schema\Schema {
        return Nette\Schema\Expect::structure([
            'routeFiles' => Nette\Schema\Expect::listOf(
                Nette\Schema\Expect::string()->assert(
                    static fn (mixed $value): bool => is_string($value) && file_exists($value),
                    'Route file must be a valid file',
                ),
            )->default([]),
            'controllers' => Nette\Schema\Expect::listOf(
                Nette\Schema\Expect::string()->assert(
                    static fn (mixed $value): bool => is_string($value) && file_exists($value),
                    'Route controller must be a valid file',
                ),
            )->default([]),
            'sitemap' => Nette\Schema\Expect::structure([
                'defaultIncluded' => Nette\Schema\Expect::bool()->default(false),
            ]),
            'cache' => Nette\Schema\Expect::structure([
                'file' => Nette\Schema\Expect::string()->default($this->getDefaultCacheFile()),
                'autoCompile' => Nette\Schema\Expect::bool()->default(true),
                'checkTimestamps' => Nette\Schema\Expect::bool()->default(false),
                'commands' => Nette\Schema\Expect::bool()->default(true),
            ]),
        ]);
    }

    public function loadConfiguration(): void {
        $builder = $this->getContainerBuilder();

        $resolverName = $this->prefix('serviceResolver');
        $builder->addDefinition($resolverName)
            ->setFactory(NetteServiceResolver::class, ['@container']);

        $cacheName = $this->prefix('compiledCache');
        $builder->addDefinition($cacheName)
            ->setFactory(CompiledRouteCache::class, [
                $this->config->cache->file,
                $this->config->cache->autoCompile,
                $this->config->cache->checkTimestamps,
                $this->config->routeFiles,
                $this->config->controllers,
            ]);

        $router = $builder->addDefinition($this->name)
            ->setType(Router::class)
            ->setFactory(Router::class, [
                $this->config->routeFiles,
                $this->config->controllers,
                '@' . $cacheName,
                '@' . $resolverName,
                $this->config->sitemap->defaultIncluded,
            ])
            ->setTags(['lsr' => true, 'routing' => true]);
        $router->lazy = false;

        if ( ! $this->config->cache->commands || ! class_exists(Command::class)) {
            return;
        }
        $tags = [
            'lsr' => true,
            'routing' => true,
            'cache' => true,
            'console.command' => true,
            'command' => true,
        ];
        $builder->addDefinition($this->prefix('commands.cacheCompile'))
            ->setFactory(RouteCacheCompileCommand::class)
            ->setTags($tags);
        $builder->addDefinition($this->prefix('commands.cacheClean'))
            ->setFactory(RouteCacheCleanCommand::class)
            ->setTags($tags);
    }

    private function getDefaultCacheFile(): string {
        $directory = defined('TMP_DIR')
            ? constant('TMP_DIR')
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lsr';
        if ( ! is_string($directory)) {
            throw new UnexpectedValueException('TMP_DIR must be a string.');
        }
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'routes.php';
    }

}
