<?php

declare(strict_types=1);

namespace Hl\AssetBundle\Command;

use Hl\AssetBundle\AssetProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'hl:assets:cleanup', description: 'Remove expired generated assets and unreferenced publication symlinks.')]
final class CleanupCommand extends Command
{
    public function __construct(private readonly AssetProcessor $processor, private readonly int $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->processor->cleanup($this->retention);
        $output->writeln(sprintf('Removed %d cache entries, %d files and %d symlinks.', ...$counts));

        return Command::SUCCESS;
    }
}
