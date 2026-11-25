<?php

namespace BradTipper\ElementalJson\Sync;

use BradTipper\ElementalJson\Assets\AssetImporter;
use BradTipper\ElementalJson\ContentException;
use SilverStripe\Assets\File;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Copies a file's keys onto a DataObject using its schema, so no per-block adapter is needed:
 *  - db fields take scalars (`lead: ...`, `hero_variant: split`; keys may be snake_case, camelCase or the field name)
 *  - has_one to a File takes an `@asset` reference
 *  - has_one to anything else is only supported to a sibling form field, by its `name` (recipients)
 *  - has_many takes a list; items are matched by `name` when the child has one, otherwise by position. A child
 *    class may be chosen per item with `type` (form fields: text, email, dropdown ...). Extra children are removed.
 * Relations that hold user data (form submissions) can never be configured or removed from files.
 */
final class FieldMapper
{
    /** Relations a file may not touch, because they hold data users created. */
    private const PROTECTED = ['Submissions', 'Values', 'SubmittedBy'];

    public function __construct(private readonly AssetImporter $assets, private readonly TypeResolver $types)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $skip keys to ignore (already handled by the caller)
     * @param DataObject|null $root the block being configured; sibling lookups (form fields) are scoped to it
     */
    public function apply(DataObject $object, array $data, string $where, ?DataObject $root = null, array $skip = []): void
    {
        $root ??= $object;
        $schema = DataObject::getSchema();
        $class = get_class($object);
        $later = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            $path = "$where.$key";
            [$kind, $name, $target] = $this->resolve($schema, $class, (string) $key, $path);

            if (in_array($name, self::PROTECTED, true)) {
                throw new ContentException("$path: '$name' holds data users created and cannot be configured from files.");
            }
            switch ($kind) {
                case 'field':
                    if (is_array($value)) {
                        throw new ContentException("$path: expected a single value for '$name', got a list or mapping.");
                    }
                    $object->setField($name, $value);
                    break;
                case 'file':
                    if ($value === null || $value === '') {
                        $object->setField($name . 'ID', 0);
                    } elseif (is_string($value) && str_starts_with($value, '@')) {
                        $object->setField($name . 'ID', $this->assets->import($value)->ID);
                    } else {
                        throw new ContentException("$path: files must be committed assets, written as '@path/in/assets'.");
                    }
                    break;
                case 'sibling':
                    $later[] = ['sibling', $name, $target, $value, $path];
                    break;
                case 'many':
                    $later[] = ['many', $name, $target, $value, $path];
                    break;
            }
        }

        // Children need the parent to exist, so write it first.
        $object->write();
        foreach ($later as [$kind, $name, $target, $value, $path]) {
            if ($kind === 'many') {
                $this->applyMany($object, $name, $target, $value, $path, $root);
            } else {
                $this->applySibling($object, $name, $target, $value, $path, $root);
            }
        }
    }

    /** @return array{0: string, 1: string, 2: string|null} kind (field|file|sibling|many), name, target class */
    private function resolve($schema, string $class, string $key, string $path): array
    {
        $studly = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $key)));
        foreach (array_unique([$key, ucfirst($key), $studly]) as $name) {
            $hasOne = $schema->hasOneComponent($class, $name);
            if ($hasOne !== null) {
                if (!is_string($hasOne)) {
                    throw new ContentException("$path: '$name' is a polymorphic relation and cannot be configured.");
                }
                if (is_a($hasOne, File::class, true)) {
                    return ['file', $name, $hasOne];
                }
                if (is_a($hasOne, 'SilverStripe\\UserForms\\Model\\EditableFormField', true)) {
                    return ['sibling', $name, $hasOne];
                }
                throw new ContentException("$path: '$name' relates to " . $hasOne . ', which files cannot set.');
            }
            $hasMany = $schema->hasManyComponent($class, $name);
            if ($hasMany !== null) {
                return ['many', $name, $hasMany];
            }
            if ($schema->manyManyComponent($class, $name) !== null) {
                throw new ContentException("$path: many-many relation '$name' is not supported.");
            }
            if ($schema->fieldSpec($class, $name) !== null && !str_ends_with($name, 'ID')) {
                return ['field', $name, null];
            }
        }
        throw new ContentException("$path: '$key' is not a field or relation on " . $class . '.');
    }

    /** A has_one to a form field of the same form, by its `name` (e.g. a recipient's address field). */
    private function applySibling(DataObject $object, string $name, string $target, mixed $value, string $path, DataObject $root): void
    {
        if ($value === null || $value === '') {
            $object->setField($name . 'ID', 0);
            $object->write();
            return;
        }
        $field = $target::get()->filter(['Name' => (string) $value, 'ParentID' => $root->ID])->first();
        if (!$field) {
            throw new ContentException("$path: no field named '$value' in this form. List 'fields' before 'recipients'.");
        }
        $object->setField($name . 'ID', $field->ID);
        $object->write();
    }

    /** @param class-string<DataObject> $childBase */
    private function applyMany(DataObject $parent, string $name, string $childBase, mixed $items, string $path, DataObject $root): void
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new ContentException("$path: '$name' must be a list.");
        }
        $schema = DataObject::getSchema();
        $join = $schema->getRemoteJoinField(get_class($parent), $name, 'has_many', $polymorphic);
        $relation = $polymorphic ? $join : (str_ends_with($join, 'ID') ? substr($join, 0, -2) : $join);

        /** @var list<DataObject> $existing */
        $existing = array_values($parent->$name()->toArray());
        $byName = [];
        foreach ($existing as $child) {
            if ($child->hasField('Name') && $child->Name !== '') {
                $byName[$child->Name] ??= $child;
            }
        }
        $isFormField = is_a($childBase, 'SilverStripe\\UserForms\\Model\\EditableFormField', true);
        $keep = [];

        foreach ($items as $i => $item) {
            $where = "{$path}[$i]";
            if (!is_array($item)) {
                throw new ContentException("$where: expected a mapping.");
            }
            $childClass = isset($item['type'])
                ? $this->types->child($childBase, (string) $item['type'], $where)
                : $childBase;
            if ($isFormField && (!isset($item['name']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', (string) $item['name']))) {
                throw new ContentException("$where: form fields need a 'name' (letters, digits, _). It is how submissions and re-syncs identify the field.");
            }

            $named = isset($item['name']) ? ($byName[(string) $item['name']] ?? null) : null;
            $child = $named ?? ($existing[$i] ?? null);
            if ($child && isset($keep[$child->ID])) {
                $child = null;
            }
            if ($child && get_class($child) !== $childClass) {
                // A different field type is a different record: the old one goes, submissions keep their own copy of the value.
                $this->archive($child);
                $child = null;
            }
            if (!$child) {
                $child = $childClass::create();
            }
            $child->setField($relation . 'ID', $parent->ID);
            if ($polymorphic) {
                $child->setField($relation . 'Class', get_class($parent));
            }
            if ($schema->fieldSpec($childClass, 'Sort') !== null) {
                $child->setField('Sort', $i + 1);
            } elseif ($schema->fieldSpec($childClass, 'SortOrder') !== null) {
                $child->setField('SortOrder', $i + 1);
            }
            $this->apply($child, $item, $where, $root, ['type']);
            $keep[$child->ID] = true;
        }

        foreach ($existing as $child) {
            if ($child->ID && !isset($keep[$child->ID])) {
                $this->archive($child);
            }
        }
    }

    private function archive(DataObject $object): void
    {
        if ($object->hasExtension(Versioned::class)) {
            $object->doArchive();
        } else {
            $object->delete();
        }
    }
}
