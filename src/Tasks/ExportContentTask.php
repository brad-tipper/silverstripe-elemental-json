<?php

namespace BradTipper\ElementalJson\Tasks;

use BradTipper\ElementalJson\Export\Exporter;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `sake tasks:elemental-json-export` writes the current (live) CMS pages and their blocks out as content files, and
 * copies their images next to them. Existing files are kept unless --overwrite is given.
 */
class ExportContentTask extends BuildTask
{
    protected static string $commandName = 'elemental-json-export';

    protected string $title = 'Export CMS pages to JSON/YAML content files';

    protected static string $description = 'Writes pages and blocks as YAML files, and their images as committed assets.';

    public function getOptions(): array
    {
        return [new InputOption('overwrite', null, InputOption::VALUE_NONE, 'Replace files that already exist')];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $count = (new Exporter($output))->export((bool) $input->getOption('overwrite'));
        $output->writeln("Exported $count page file(s).");
        return Command::SUCCESS;
    }
}
