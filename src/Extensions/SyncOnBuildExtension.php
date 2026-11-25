<?php

namespace BradTipper\ElementalJson\Extensions;

use BradTipper\ElementalJson\ContentException;
use BradTipper\ElementalJson\Sync\Synchroniser;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Command\DbBuild;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Runs the sync after dev/build (sake db:build), which every deploy runs. A bad file throws in dev so it is noticed;
 * in live it is logged and that page keeps its current content, so a typo cannot break a deploy.
 *
 * @extends Extension<DbBuild>
 */
class SyncOnBuildExtension extends Extension
{
    protected function onAfterBuild(PolyOutput $output, bool $populate = true, bool $testMode = false): void
    {
        if (!$populate || $testMode) {
            return;
        }
        $output->writeln('<options=bold>Syncing page content from files</>');
        $report = (new Synchroniser($output))->syncAll();
        if (!$report->errors) {
            return;
        }
        if (Director::isDev()) {
            throw new ContentException("Content files could not be synced:\n" . implode("\n", array_map(
                static fn (string $slug, string $message) => " - $slug: $message",
                array_keys($report->errors),
                $report->errors
            )));
        }
        $logger = Injector::inst()->get(LoggerInterface::class);
        foreach ($report->errors as $slug => $message) {
            $logger->error("Elemental JSON: $slug: $message");
        }
    }
}
