<?php

declare(strict_types=1);

namespace Lsr\Core\Routing\Commands;

use Lsr\Core\Routing\Cache\CompiledRouteCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'routes:cache:clean',
    description: 'Remove the compiled PHP route cache.',
    aliases: ['routes:cache:clear'],
)]
final class RouteCacheCleanCommand extends Command
{
    public function __construct(private readonly CompiledRouteCache $cache) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $this->cache->clear();
        } catch (Throwable $exception) {
            $output->writeln('<error>Route cache cleanup failed: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Route cache cleared successfully.</info>');
        return Command::SUCCESS;
    }
}
