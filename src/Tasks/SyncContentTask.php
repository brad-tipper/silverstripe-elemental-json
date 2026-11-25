<?php

namespace BradTipper\ElementalJson\Tasks;

use BradTipper\ElementalJson\Sync\Synchroniser;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `sake tasks:elemental-json-sync` brings page blocks in line with the content files without a full db:build.
 * Options: --dry-run, --force (re-sync unchanged pages), --page=help/billing.
 */
class SyncContentTask extends BuildTask
{
    protected static string $commandName = 'elemental-json-sync';

    protected string $title = 'Sync page content from JSON/YAML files';

    protected static string $description = 'Creates and updates Elemental blocks from the committed content files.';

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing'),
            new InputOption('force', null, InputOption::VALUE_NONE, 'Re-sync pages even if their file has not changed'),
            new InputOption('page', null, InputOption::VALUE_REQUIRED, 'Only sync this slug, e.g. help/billing'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $report = (new Synchroniser($output))->syncAll(
            (bool) $input->getOption('dry-run'),
            (bool) $input->getOption('force'),
            $input->getOption('page') ?: null,
        );
        return $report->errors ? Command::FAILURE : Command::SUCCESS;
    }
}
