<?php

namespace BradTipper\ElementalJson\Content;

/** One parsed content file: the page it configures and what should be on it. */
final class PageFile
{
    /**
     * @param string $path absolute path of the file
     * @param string $relativePath path relative to the content directory (shown in the CMS banner)
     * @param string $slug full slug of the page, e.g. `help/billing`
     * @param array<string, mixed> $data the validated file contents
     * @param string $rawHash sha1 of the file contents
     */
    public function __construct(
        public readonly string $path,
        public readonly string $relativePath,
        public readonly string $slug,
        public readonly array $data,
        public readonly string $rawHash,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function blocks(): array
    {
        return $this->data['blocks'];
    }

    /** Every `@asset` reference in the file, in order. @return list<string> */
    public function assetRefs(): array
    {
        $refs = [];
        $data = $this->data;
        array_walk_recursive($data, static function ($value) use (&$refs): void {
            if (is_string($value) && str_starts_with($value, '@')) {
                $refs[] = $value;
            }
        });
        return $refs;
    }
}
