<?php

namespace BradTipper\ElementalJson\Content;

/**
 * References inside HTML fields, and their conversion to and from Silverstripe shortcodes. Files are written as
 * plain HTML with portable references, because shortcodes hold database IDs that differ between environments:
 *
 *   <img src="@images/shot.png" alt="...">     <->  [image src="..." id="12" width="800" height="600" ...]
 *   <a href="@files/guide.pdf">                <->  <a href="[file_link,id=13]">
 *   <a href="page:help/billing#refunds">       <->  <a href="[sitetree_link,id=7]#refunds">
 *
 * Pure string handling; looking up files and pages is left to the callbacks.
 */
final class HtmlRefs
{
    /** The classes the CMS editor puts on an image it inserts. */
    public const IMAGE_CLASS = 'leftAlone ss-htmleditorfield-file image';

    /** A tag, allowing '>' inside quoted attribute values. */
    private const TAG = '/<(img|a)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

    private const ATTRIBUTE = '/([^\s"\'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/';

    /** @return array{assets: list<string>, pages: list<string>} every `@asset` and `page:slug` reference in the HTML */
    public static function find(string $html): array
    {
        $found = ['assets' => [], 'pages' => []];
        preg_match_all(self::TAG, $html, $tags, PREG_SET_ORDER);
        foreach ($tags as [$tag, $name]) {
            $attributes = self::attributes($tag, $name);
            $value = $attributes[strtolower($name) === 'img' ? 'src' : 'href'] ?? '';
            if (str_starts_with($value, '@')) {
                $found['assets'][] = self::split($value)[0];
            } elseif (strtolower($name) === 'a' && str_starts_with($value, 'page:')) {
                $found['pages'][] = self::pageSlug($value);
            }
        }
        return $found;
    }

    /**
     * @param callable(string): array{id: int, url: string, width: int, height: int} $image
     * @param callable(string): int $file
     * @param callable(string): int $page
     */
    public static function toShortcodes(string $html, callable $image, callable $file, callable $page): string
    {
        return (string) preg_replace_callback(self::TAG, static function (array $match) use ($image, $file, $page): string {
            [$tag, $name] = $match;
            if (strtolower($name) === 'img') {
                $attributes = self::attributes($tag, $name);
                $src = $attributes['src'] ?? '';
                return str_starts_with($src, '@') ? self::imageShortcode($attributes, $image($src)) : $tag;
            }
            return self::replaceHref($tag, static function (string $href) use ($file, $page): ?string {
                if (str_starts_with($href, '@')) {
                    [$ref, $suffix] = self::split($href);
                    return '[file_link,id=' . $file($ref) . ']' . $suffix;
                }
                if (str_starts_with($href, 'page:')) {
                    return '[sitetree_link,id=' . $page(self::pageSlug($href)) . ']' . self::split($href)[1];
                }
                return null;
            });
        }, $html);
    }

    /**
     * The reverse, for exporting. A callback returning null leaves that shortcode as it is.
     *
     * @param callable(int): ?string $asset file ID to `@path`
     * @param callable(int): ?string $page page ID to slug
     */
    public static function fromShortcodes(string $html, callable $asset, callable $page): string
    {
        $html = (string) preg_replace_callback(
            '/\[image\b((?:[^\]"\']|"[^"]*"|\'[^\']*\')*)\]/i',
            static function (array $match) use ($asset): string {
                $attributes = self::attributes('<img' . $match[1] . '>', 'img');
                $ref = isset($attributes['id']) ? $asset((int) $attributes['id']) : null;
                if ($ref === null) {
                    return $match[0];
                }
                unset($attributes['id'], $attributes['src']);
                if (($attributes['class'] ?? '') === self::IMAGE_CLASS) {
                    unset($attributes['class']);
                }
                return '<img' . self::render(['src' => $ref] + $attributes) . '>';
            },
            $html
        );
        return (string) preg_replace_callback(
            '/\[(file_link|sitetree_link)[\s,]+id\s*=\s*["\']?(\d+)["\']?\s*\]/i',
            static function (array $match) use ($asset, $page): string {
                if (strtolower($match[1]) === 'file_link') {
                    return $asset((int) $match[2]) ?? $match[0];
                }
                $slug = $page((int) $match[2]);
                return $slug === null ? $match[0] : 'page:' . $slug;
            },
            $html
        );
    }

    /**
     * @param array<string, string> $attributes the authored <img> attributes
     * @param array{id: int, url: string, width: int, height: int} $file
     */
    private static function imageShortcode(array $attributes, array $file): string
    {
        $width = (int) ($attributes['width'] ?? 0);
        $height = (int) ($attributes['height'] ?? 0);
        // Keep the aspect ratio when only one side is given; use the file's own size when neither is.
        if ($width && !$height && $file['width']) {
            $height = (int) round($width * $file['height'] / $file['width']);
        } elseif ($height && !$width && $file['height']) {
            $width = (int) round($height * $file['width'] / $file['height']);
        } elseif (!$width && !$height) {
            [$width, $height] = [$file['width'], $file['height']];
        }
        unset($attributes['src'], $attributes['id'], $attributes['width'], $attributes['height']);

        $out = ['src' => $file['url'], 'id' => (string) $file['id']];
        if ($width && $height) {
            $out += ['width' => (string) $width, 'height' => (string) $height];
        }
        $out += ['class' => $attributes['class'] ?? self::IMAGE_CLASS] + $attributes;
        return '[image' . self::render($out) . ']';
    }

    /** @return array<string, string> attribute values exactly as written (entities are not decoded) */
    private static function attributes(string $tag, string $name): array
    {
        $inner = rtrim(substr($tag, strlen($name) + 1, -1), " \t\r\n/");
        preg_match_all(self::ATTRIBUTE, $inner, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $out = [];
        foreach ($matches as $m) {
            $out[strtolower($m[1])] = $m[2] ?? $m[3] ?? $m[4] ?? '';
        }
        return $out;
    }

    /** @param array<string, string> $attributes */
    private static function render(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $key => $value) {
            $out .= ' ' . $key . '="' . str_replace('"', '&quot;', $value) . '"';
        }
        return $out;
    }

    /** @param callable(string): ?string $replace */
    private static function replaceHref(string $tag, callable $replace): string
    {
        return (string) preg_replace_callback(
            '/(\shref\s*=\s*)(?:"([^"]*)"|\'([^\']*)\')/i',
            static function (array $m) use ($replace): string {
                $new = $replace($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
                return $new === null ? $m[0] : $m[1] . '"' . $new . '"';
            },
            $tag,
            1
        );
    }

    /** @return array{0: string, 1: string} the reference, and any `?query` or `#fragment` after it */
    private static function split(string $value): array
    {
        $at = strcspn($value, '?#');
        return [substr($value, 0, $at), substr($value, $at)];
    }

    private static function pageSlug(string $href): string
    {
        return ContentLoader::normaliseSlug(substr(self::split($href)[0], 5));
    }
}
