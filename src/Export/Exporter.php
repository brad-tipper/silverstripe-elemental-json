<?php

namespace BradTipper\ElementalJson\Export;

use BradTipper\ElementalJson\ElementalJson;
use BradTipper\ElementalJson\Sync\TypeResolver;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes existing CMS pages out as content files (the inverse of the sync), so a site that was built in the CMS can
 * move to files. Only pages of the configured `page_class` that have blocks are exported. Values equal to the block's
 * defaults are left out. Images are copied next to the content as committed assets. Form submissions are never exported.
 */
class Exporter
{
    private const SKIP_FIELDS = ['ID', 'ClassName', 'LastEdited', 'Created', 'Version', 'Sort', 'SortOrder', 'ManagedKey', 'ManagedHash', 'ManagedPath', 'ManagedSource'];

    private const PROTECTED_RELATIONS = ['Submissions', 'Values', 'CustomRules'];

    /** @var array<string, bool> files already copied, by relative path */
    private array $copied = [];

    public function __construct(private readonly ?PolyOutput $output = null)
    {
    }

    /** @return int number of page files written */
    public function export(bool $overwrite = false): int
    {
        $written = 0;
        Versioned::withVersionedMode(function () use ($overwrite, &$written): void {
            Versioned::set_stage(Versioned::LIVE);
            $class = (string) ElementalJson::config()->get('page_class');
            foreach ($class::get()->filter('ClassName', $class)->sort('ParentID, Sort') as $page) {
                $area = $page->hasMethod('ElementalArea') ? $page->ElementalArea() : null;
                if (!$area || !$area->exists() || !$area->Elements()->exists()) {
                    continue;
                }
                $slug = $this->slugOf($page);
                $relative = ($slug === 'home' ? 'index' : $slug) . '.yml';
                $path = ElementalJson::pagesDir() . '/' . $relative;
                if (is_file($path) && !$overwrite) {
                    $this->say("kept $relative (exists)");
                    continue;
                }
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                file_put_contents($path, $this->dump($this->pageData($page, $slug, $area)));
                $this->say("wrote pages/$relative");
                $written++;
            }
        });
        return $written;
    }

    /** @return array<string, mixed> */
    private function pageData(SiteTree $page, string $slug, $area): array
    {
        $data = ['title' => $page->Title];
        if ($slug !== 'home' && !str_contains($slug, '/')) {
            // The slug comes from the file name; nothing to add.
        }
        if ($page->MenuTitle && $page->MenuTitle !== $page->Title) {
            $data['menu_title'] = $page->MenuTitle;
        }
        if ($page->MetaDescription) {
            $data['meta_description'] = $page->MetaDescription;
        }
        $data['show_in_menus'] = (bool) $page->ShowInMenus;
        $data['show_in_search'] = (bool) $page->ShowInSearch;
        $data['create'] = true;

        $types = new TypeResolver();
        $used = [];
        $blocks = [];
        foreach ($area->Elements()->sort('Sort') as $element) {
            $alias = TypeResolver::aliasFor(get_class($element));
            $type = $alias;
            try {
                if ($types->block($alias, '') !== get_class($element)) {
                    $type = get_class($element);
                }
            } catch (\Throwable) {
                $type = get_class($element);
            }
            $id = $alias;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = "$alias-$n";
            }
            $used[$id] = true;
            $blocks[] = ['id' => $id, 'type' => $type] + $this->objectData($element, ['ParentID']);
        }
        $data['blocks'] = $blocks;
        return $data;
    }

    /**
     * @param list<string> $extraSkip
     * @return array<string, mixed>
     */
    private function objectData(DataObject $object, array $extraSkip = []): array
    {
        $schema = DataObject::getSchema();
        $class = get_class($object);
        $blank = $class::create();
        $out = [];

        foreach ($schema->databaseFields($class, false) + $schema->databaseFields(get_parent_class($class) ?: $class, true) as $field => $spec) {
            if (in_array($field, self::SKIP_FIELDS, true) || in_array($field, $extraSkip, true) || str_ends_with($field, 'ID') || $field === 'ParentClass') {
                continue;
            }
            $value = $object->getField($field);
            if ($value === null || $value === '' || (string) $value === (string) $blank->getField($field)) {
                continue;
            }
            $out[$this->snake($field)] = $this->scalar($value, $spec);
        }

        foreach ($object->hasOne() as $name => $target) {
            if (!is_string($target) || in_array($name, $extraSkip, true) || in_array($name . 'ID', $extraSkip, true)) {
                continue;
            }
            $id = (int) $object->getField($name . 'ID');
            if (!$id) {
                continue;
            }
            if (is_a($target, File::class, true)) {
                $ref = $this->copyAsset($object->$name());
                if ($ref) {
                    $out[$this->snake($name)] = $ref;
                }
            } elseif (is_a($target, 'SilverStripe\\UserForms\\Model\\EditableFormField', true)) {
                $field = $object->$name();
                if ($field->exists()) {
                    $out[$this->snake($name)] = $field->Name;
                }
            }
        }

        // Fields before recipients, so recipients can refer to a field by name.
        $many = $object->hasMany();
        uksort($many, static fn ($a, $b) => [$a === 'Fields' ? 0 : 1, $a] <=> [$b === 'Fields' ? 0 : 1, $b]);
        foreach ($many as $name => $childBase) {
            if (in_array($name, self::PROTECTED_RELATIONS, true)) {
                continue;
            }
            $items = [];
            $list = $object->$name();
            $list = $schema->fieldSpec($childBase, 'Sort') ? $list->sort('Sort') : $list->sort('ID');
            foreach ($list as $child) {
                $item = [];
                if (get_class($child) !== $childBase) {
                    $item['type'] = TypeResolver::childAliasFor(get_class($child));
                }
                $items[] = $item + $this->objectData($child, ['ParentID', 'FormID']);
            }
            if ($items) {
                $out[$this->snake($name)] = $items;
            }
        }
        return $out;
    }

    private function scalar(mixed $value, string $spec): mixed
    {
        if (str_starts_with($spec, 'Boolean')) {
            return (bool) $value;
        }
        if (str_starts_with($spec, 'Int')) {
            return (int) $value;
        }
        if (str_starts_with($spec, 'Decimal') || str_starts_with($spec, 'Float') || str_starts_with($spec, 'Double')) {
            return (float) $value;
        }
        return (string) $value;
    }

    private function copyAsset(File $file): ?string
    {
        if (!$file->exists() || !$file->getFilename()) {
            return null;
        }
        $relative = ltrim($file->getFilename(), '/');
        if (!isset($this->copied[$relative])) {
            $target = ElementalJson::assetsDir() . '/' . $relative;
            $stream = $file->getStream();
            if (!$stream) {
                $this->say("<comment>skipped asset $relative (file content not found)</comment>");
                return null;
            }
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }
            file_put_contents($target, stream_get_contents($stream));
            $this->copied[$relative] = true;
        }
        return '@' . $relative;
    }

    private function slugOf(SiteTree $page): string
    {
        $parts = [];
        for ($p = $page; $p && $p->exists(); $p = $p->ParentID ? SiteTree::get_by_id($p->ParentID) : null) {
            array_unshift($parts, $p->URLSegment);
        }
        return implode('/', $parts);
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    /** @param array<string, mixed> $data */
    private function dump(array $data): string
    {
        return "# Configured here and synced to the CMS on dev/build. See the silverstripe-elemental-json README.\n"
            . Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE);
    }

    private function say(string $line): void
    {
        $this->output?->writeln($line);
    }
}
