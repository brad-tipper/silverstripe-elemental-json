<?php

namespace BradTipper\ElementalJson\Sync;

use BradTipper\ElementalJson\ContentException;
use BradTipper\ElementalJson\ElementalJson;
use DNADesign\Elemental\Models\BaseElement;
use SilverStripe\Core\ClassInfo;

/**
 * Turns a block `type` (or a child `type`, such as a form field's) into a class.
 * Accepts a full class name, a configured alias, or a derived alias: the short class name lower-cased with a
 * `Block`/`Element` suffix (blocks) or `Editable` prefix and `Field` suffix (form fields) removed:
 * HeroBlock => hero, EditableTextField => text.
 */
final class TypeResolver
{
    /** @return class-string<BaseElement> */
    public function block(string $type, string $where): string
    {
        $configured = (array) ElementalJson::config()->get('types');
        foreach ($configured as $alias => $class) {
            if (strcasecmp((string) $alias, $type) === 0 && is_a($class, BaseElement::class, true)) {
                return $class;
            }
        }
        return $this->among(BaseElement::class, $type, $where, ['/(Block|Element)$/']);
    }

    /**
     * @param class-string $base
     * @return class-string
     */
    public function child(string $base, string $type, string $where): string
    {
        return $this->among($base, $type, $where, ['/^Editable/', '/Field$/']);
    }

    /**
     * @param class-string $base
     * @param list<string> $strip patterns removed from the short class name to make the alias
     * @return class-string
     */
    private function among(string $base, string $type, string $where, array $strip): string
    {
        if (class_exists($type) && is_a($type, $base, true) && !$this->isAbstract($type)) {
            return $type;
        }
        $aliases = [];
        foreach (ClassInfo::subclassesFor($base, true) as $class) {
            if ($this->isAbstract($class) || str_contains($class, 'Test')) {
                continue;
            }
            $short = ClassInfo::shortName($class);
            $alias = strtolower((string) preg_replace($strip, '', $short));
            $aliases[$alias] ??= $class;
            $aliases[strtolower($short)] ??= $class;
        }
        $wanted = strtolower($type);
        if (isset($aliases[$wanted])) {
            return $aliases[$wanted];
        }
        ksort($aliases);
        throw new ContentException("$where: unknown type '$type'. Known: " . implode(', ', array_keys($aliases)) . '.');
    }

    private function isAbstract(string $class): bool
    {
        return (new \ReflectionClass($class))->isAbstract();
    }
}
