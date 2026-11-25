<?php

namespace BradTipper\ElementalJson\Sync;

use BradTipper\ElementalJson\Assets\AssetImporter;
use BradTipper\ElementalJson\Content\ContentLoader;
use BradTipper\ElementalJson\Content\PageFile;
use BradTipper\ElementalJson\ContentException;
use BradTipper\ElementalJson\ElementalJson;
use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;

/**
 * Brings the CMS in line with the content files. Idempotent: a page whose file (and assets) are unchanged is skipped.
 * The file owns the page's whole block area. Blocks are matched by their `id`, updated in place, and reordered.
 * A block that holds form submissions is never removed or retyped by a sync: that page fails with an error
 * instead, and nothing about it changes.
 */
class Synchroniser
{
    private readonly AssetImporter $assets;

    private readonly FieldMapper $mapper;

    private readonly TypeResolver $types;

    public function __construct(private readonly ?PolyOutput $output = null)
    {
        $this->assets = new AssetImporter();
        $this->types = new TypeResolver();
        $this->mapper = new FieldMapper($this->assets, $this->types);
    }

    public function syncAll(bool $dryRun = false, bool $force = false, ?string $only = null): SyncReport
    {
        $report = new SyncReport();
        try {
            $files = (new ContentLoader())->load();
        } catch (ContentException $e) {
            $report->errors['(content)'] = $e->getMessage();
            $this->say('<error>' . $e->getMessage() . '</error>');
            return $report;
        }
        $only = $only !== null ? ContentLoader::normaliseSlug($only) : null;

        Versioned::withVersionedMode(function () use ($files, $dryRun, $force, $only, $report): void {
            Versioned::set_stage(Versioned::DRAFT);
            foreach ($files as $file) {
                if ($only !== null && $file->slug !== $only) {
                    continue;
                }
                try {
                    $this->syncPage($file, $dryRun, $force, $report);
                } catch (\Throwable $e) {
                    $report->errors[$file->slug] = $e->getMessage();
                    $this->say("<error>{$file->slug}: {$e->getMessage()}</error>");
                }
            }
            if ($only === null) {
                $this->release($files, $dryRun, $report);
            }
        });

        $this->say(($dryRun ? '[dry run] ' : '') . 'Elemental JSON: ' . $report->summary());
        return $report;
    }

    private function syncPage(PageFile $file, bool $dryRun, bool $force, SyncReport $report): void
    {
        $hash = $this->hashFor($file);
        $page = $this->find($file->slug);

        if ($page && !$force && $page->ManagedSource === $file->relativePath && $page->ManagedHash === $hash) {
            $report->unchanged[] = $file->slug;
            return;
        }
        if (!$page && empty($file->data['create'])) {
            throw new ContentException("{$file->relativePath}: no page at '{$file->slug}'. Create it in the CMS, or add 'create: true'.");
        }
        $creating = !$page;
        if ($dryRun) {
            $this->say(($creating ? 'would create ' : 'would update ') . $file->slug);
            if ($creating) {
            $report->created[] = $file->slug;
        } else {
            $report->updated[] = $file->slug;
        }
            return;
        }

        DB::get_conn()->transactionStart();
        try {
            $page ??= $this->create($file);
            $wasPublished = !$creating && $page->isPublished();
            $forms = $this->configurePage($page, $file, $hash);
            if (ElementalJson::config()->get('publish') && ($creating || $wasPublished)) {
                $page->publishRecursive();
            }
            // Writing and publishing a form makes UserForms add a "First Page" step; remove it unless the file lists steps.
            foreach ($forms as [$form, $block]) {
                $this->healFormSteps($form, $block);
            }
            DB::get_conn()->transactionEnd();
        } catch (\Throwable $e) {
            DB::get_conn()->transactionRollback();
            throw $e;
        }

        $this->say(($creating ? 'created ' : 'updated ') . $file->slug);
        if ($creating) {
            $report->created[] = $file->slug;
        } else {
            $report->updated[] = $file->slug;
        }
    }

    /** @return list<array{0: DataObject, 1: array<string, mixed>}> the form blocks that were synced, with their file data */
    private function configurePage(SiteTree $page, PageFile $file, string $hash): array
    {
        $forms = [];
        $data = $file->data;
        $pageData = array_intersect_key($data, array_flip(['title', 'menu_title', 'meta_description', 'show_in_menus', 'show_in_search']));
        $page->ManagedSource = $file->relativePath;
        $page->ManagedHash = $hash;
        $this->mapper->apply($page, $pageData, $file->relativePath, $page);

        $area = $page->ElementalArea();
        if (!$area->exists()) {
            throw new ContentException("{$file->relativePath}: page class " . get_class($page) . ' has no Elemental area (add ElementalPageExtension).');
        }

        /** @var list<BaseElement> $existing */
        $existing = array_values($area->Elements()->toArray());
        $byKey = [];
        $unkeyed = [];
        foreach ($existing as $element) {
            if ($element->ManagedKey) {
                $byKey[$element->ManagedKey] = $element;
            } else {
                $unkeyed[] = $element;
            }
        }

        $keep = [];
        foreach ($file->blocks() as $i => $block) {
            $where = "{$file->relativePath}.blocks[$i]({$block['id']})";
            $class = $this->types->block($block['type'], $where);
            $element = $byKey[$block['id']] ?? null;
            if (!$element) {
                // First sync of a page built in the CMS: adopt its existing block of the same type (in order), so
                // nothing is recreated. This is what keeps a form block, and any submissions on it, in place.
                foreach ($unkeyed as $at => $candidate) {
                    if (get_class($candidate) === $class) {
                        $element = $candidate;
                        $element->ManagedKey = $block['id'];
                        unset($unkeyed[$at]);
                        break;
                    }
                }
            }
            if ($element && get_class($element) !== $class) {
                $this->remove($element, "$where changes type");
                $element = null;
            }
            if (!$element) {
                $element = $class::create();
                $element->ManagedKey = $block['id'];
            }
            $element->ParentID = $area->ID;
            $element->Sort = $i + 1;
            $this->mapper->apply($element, $block, $where, $element, ['type', 'id']);
            $keep[$element->ID] = true;
            if (DataObject::getSchema()->hasManyComponent(get_class($element), 'Fields') !== null) {
                $forms[] = [$element, $block];
            }
        }
        foreach ($existing as $element) {
            if ($element->ID && !isset($keep[$element->ID])) {
                $this->remove($element, "{$file->relativePath} no longer lists block " . ($element->ManagedKey ?: '#' . $element->ID));
            }
        }
        $page->write();
        return $forms;
    }

    /**
     * UserForms adds a "First Page" step whenever a form is written and its first field is not a step, and renumbers
     * the other fields. A form configured from a file has exactly the fields it lists, so take the extra step away
     * (from both stages) and put the sort order back to the file's order.
     *
     * @param array<string, mixed> $block
     */
    private function healFormSteps(DataObject $form, array $block): void
    {
        $names = [];
        foreach (($block['fields'] ?? []) as $item) {
            if (isset($item['name'])) {
                $names[] = (string) $item['name'];
            }
        }
        $order = array_flip($names);
        $fieldClass = DataObject::getSchema()->hasManyComponent(get_class($form), 'Fields');
        $table = DataObject::getSchema()->tableName($fieldClass);
        foreach ($form->Fields() as $field) {
            if (!isset($order[$field->Name])) {
                $field->doArchive();
                continue;
            }
            foreach ([$table, $table . '_Live'] as $t) {
                DB::prepared_query("UPDATE \"$t\" SET \"Sort\" = ? WHERE \"ID\" = ?", [$order[$field->Name] + 1, $field->ID]);
            }
        }
    }

    /** Remove a block, unless that would lose the submissions made through it. */
    private function remove(DataObject $element, string $why): void
    {
        $schema = DataObject::getSchema();
        if ($schema->hasManyComponent(get_class($element), 'Submissions') !== null) {
            $count = $element->Submissions()->count();
            if ($count > 0) {
                throw new ContentException(
                    "$why, but that form block has $count submission(s) and removing it would orphan them. "
                    . 'Keep the block in the file, or export and delete the submissions in the CMS first.'
                );
            }
        }
        if ($element->hasExtension(Versioned::class)) {
            $element->doArchive();
        } else {
            $element->delete();
        }
    }

    /** Pages whose file has been deleted go back to the CMS: their blocks stay, but they become editable again. */
    private function release(array $files, bool $dryRun, SyncReport $report): void
    {
        $slugs = array_map(static fn (PageFile $f) => $f->slug, $files);
        foreach (SiteTree::get()->where('"ManagedSource" IS NOT NULL AND "ManagedSource" <> \'\'') as $page) {
            if (in_array($this->slugOf($page), $slugs, true)) {
                continue;
            }
            $report->released[] = $this->slugOf($page);
            $this->say(($dryRun ? 'would release ' : 'released ') . $this->slugOf($page) . ' (its file was removed)');
            if ($dryRun) {
                continue;
            }
            $published = $page->isPublished();
            $page->ManagedSource = null;
            $page->ManagedHash = null;
            $page->write();
            if ($published) {
                $page->publishRecursive();
            }
        }
    }

    private function hashFor(PageFile $file): string
    {
        $assetHashes = array_map(fn (string $ref) => $ref . ':' . $this->assets->hashOf($ref), $file->assetRefs());
        return sha1(ElementalJson::FORMAT_VERSION . '|' . $file->rawHash . '|' . implode(',', $assetHashes));
    }

    private function find(string $slug): ?SiteTree
    {
        $parentId = 0;
        $page = null;
        foreach (explode('/', $slug) as $segment) {
            $page = SiteTree::get()->filter(['URLSegment' => $segment, 'ParentID' => $parentId])->first();
            if (!$page) {
                return null;
            }
            $parentId = $page->ID;
        }
        return $page;
    }

    private function create(PageFile $file): SiteTree
    {
        $parentId = 0;
        $segments = explode('/', $file->slug);
        $last = array_pop($segments);
        $class = (string) ($file->data['class'] ?? ElementalJson::config()->get('page_class'));
        if (!is_a($class, SiteTree::class, true)) {
            throw new ContentException("{$file->relativePath}: '$class' is not a page class.");
        }

        foreach ($segments as $segment) {
            $parent = SiteTree::get()->filter(['URLSegment' => $segment, 'ParentID' => $parentId])->first();
            if (!$parent) {
                $parent = ElementalJson::config()->get('page_class')::create();
                $parent->Title = ucwords(str_replace(['-', '_'], ' ', $segment));
                $parent->URLSegment = $segment;
                $parent->ParentID = $parentId;
                $parent->write();
                $parent->publishRecursive();
            }
            $parentId = $parent->ID;
        }

        /** @var SiteTree $page */
        $page = $class::create();
        $page->Title = (string) ($file->data['title'] ?? ucwords(str_replace(['-', '_'], ' ', $last)));
        $page->URLSegment = $last;
        $page->ParentID = $parentId;
        $page->write();
        return $page;
    }

    private function slugOf(SiteTree $page): string
    {
        $parts = [];
        for ($p = $page; $p && $p->exists(); $p = $p->ParentID ? SiteTree::get()->byID($p->ParentID) : null) {
            array_unshift($parts, $p->URLSegment);
        }
        return implode('/', $parts);
    }

    private function say(string $line): void
    {
        $this->output?->writeln($line);
    }
}
