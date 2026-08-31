<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Commands;

use Lsr\Core\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'routes:cache:compile', description: 'Compile application routes into the PHP route cache.')]
final class RouteCacheCompileCommand extends Command
{
    public function __construct(private readonly Router $router)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->router->compileCache();
        } catch (Throwable $exception) {
            $output->writeln('<error>Route cache compilation failed: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Route cache compiled successfully.</info>');
        return Command::SUCCESS;
    }
}
