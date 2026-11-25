<?php

namespace BradTipper\ElementalJson\Content;

use BradTipper\ElementalJson\ContentException;
use BradTipper\ElementalJson\ElementalJson;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Finds and validates the page files under `<content_dir>/pages`. A file's path is its slug
 * (`pages/help/billing.yml` is `help/billing`; `index` means the folder itself, and a root `index` is `home`),
 * unless the file sets `slug:`.
 */
class ContentLoader
{
    private const PAGE_KEYS = ['slug', 'create', 'class', 'title', 'menu_title', 'meta_description', 'show_in_menus', 'show_in_search', 'blocks'];

    /** @return list<PageFile> */
    public function load(?string $pagesDir = null): array
    {
        $pagesDir ??= ElementalJson::pagesDir();
        if (!is_dir($pagesDir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pagesDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['json', 'yml', 'yaml'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $pages = [];
        $seen = [];
        foreach ($files as $path) {
            $page = $this->loadFile($path, $pagesDir);
            if (isset($seen[$page->slug])) {
                throw new ContentException("Two files configure the page '{$page->slug}': {$seen[$page->slug]} and {$page->relativePath}.");
            }
            $seen[$page->slug] = $page->relativePath;
            $pages[] = $page;
        }
        return $pages;
    }

    public function loadFile(string $path, string $pagesDir): PageFile
    {
        $normalised = str_replace('\\', '/', $path);
        $pagesRoot = rtrim(str_replace('\\', '/', $pagesDir), '/');
        $contentRoot = dirname($pagesRoot);
        $relative = ltrim(substr($normalised, strlen($contentRoot)), '/');
        $relativeToPages = ltrim(substr($normalised, strlen($pagesRoot)), '/');
        $raw = (string) file_get_contents($path);
        $data = $this->parse($raw, strtolower(pathinfo($path, PATHINFO_EXTENSION)), $relative);

        $unknown = array_diff(array_keys($data), self::PAGE_KEYS);
        if ($unknown) {
            throw new ContentException("$relative: unknown top-level key(s) " . implode(', ', $unknown) . '. Allowed: ' . implode(', ', self::PAGE_KEYS) . '.');
        }
        if (!isset($data['blocks']) || !is_array($data['blocks']) || !array_is_list($data['blocks'])) {
            throw new ContentException("$relative: 'blocks' must be a list.");
        }
        $ids = [];
        foreach ($data['blocks'] as $i => $block) {
            if (!is_array($block) || !isset($block['type']) || !is_string($block['type'])) {
                throw new ContentException("$relative: blocks[$i] needs a 'type'.");
            }
            if (!isset($block['id']) || !is_string($block['id']) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $block['id'])) {
                throw new ContentException("$relative: blocks[$i] needs an 'id' (letters, digits, - and _). It is how re-syncs find the block.");
            }
            if (isset($ids[$block['id']])) {
                throw new ContentException("$relative: block id '{$block['id']}' is used twice.");
            }
            $ids[$block['id']] = true;
        }

        $slug = isset($data['slug']) ? (string) $data['slug'] : $this->slugFromPath($relativeToPages);
        return new PageFile($path, $relative, self::normaliseSlug($slug, $relative), $data, sha1($raw));
    }

    public static function normaliseSlug(string $slug, string $where = ''): string
    {
        $slug = strtolower(trim($slug, '/'));
        $segment = '[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?';
        if ($slug === '' || !preg_match("#^$segment(?:/$segment)*$#", $slug)) {
            throw new ContentException(($where ? "$where: " : '') . "invalid slug '$slug'.");
        }
        return $slug;
    }

    private function slugFromPath(string $relativeToPages): string
    {
        $withoutExt = (string) preg_replace('/\.(json|ya?ml)$/i', '', $relativeToPages);
        $withoutExt = (string) preg_replace('#(^|/)index$#', '', $withoutExt);
        return $withoutExt === '' ? 'home' : $withoutExt;
    }

    /** @return array<string, mixed> */
    private function parse(string $raw, string $extension, string $where): array
    {
        try {
            $data = $extension === 'json'
                ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parse($raw);
        } catch (\JsonException | ParseException $e) {
            throw new ContentException("$where: could not parse: " . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new ContentException("$where: the file must contain a mapping at the top level.");
        }
        return $data;
    }
}
