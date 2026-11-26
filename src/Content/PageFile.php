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

    /** Every `@asset` reference in the file, including those inside HTML. @return list<string> */
    public function assetRefs(): array
    {
        $refs = [];
        foreach ($this->strings() as $value) {
            if (str_starts_with($value, '@')) {
                $refs[] = $value;
            } elseif (str_contains($value, '<')) {
                array_push($refs, ...HtmlRefs::find($value)['assets']);
            }
        }
        return $refs;
    }

    /** Slugs of the pages linked to with `page:slug` in the file's HTML. @return list<string> */
    public function pageRefs(): array
    {
        $refs = [];
        foreach ($this->strings() as $value) {
            if (str_contains($value, '<')) {
                array_push($refs, ...HtmlRefs::find($value)['pages']);
            }
        }
        return array_values(array_unique($refs));
    }

    /** Whether any `page:slug#anchor` link is in the file. */
    public function hasAnchorLinks(): bool
    {
        return (bool) preg_grep('/\shref\s*=\s*["\']page:[^"\'#]*#/i',$this->strings());
    }

    /** @return list<string> */
    private function strings(): array
    {
        $strings = [];
        $data = $this->data;
        array_walk_recursive($data, static function ($value) use (&$strings): void {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });
        return $strings;
    }
}
