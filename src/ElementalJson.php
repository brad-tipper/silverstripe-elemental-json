<?php

namespace BradTipper\ElementalJson;

use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;

/** Module settings (see _config/elemental-json.yml). */
final class ElementalJson
{
    use Configurable;

    /** Bump when the sync output changes for the same files, so every page re-syncs once. */
    public const FORMAT_VERSION = '1';

    private static string $content_dir = 'app/content';

    private static string $asset_folder = 'Managed';

    private static string $page_class = 'Page';

    private static bool $publish = true;

    /** @var array<string, class-string> */
    private static array $types = [];

    public static function contentDir(): string
    {
        return rtrim(Director::baseFolder(), '/\\') . '/' . trim((string) self::config()->get('content_dir'), '/\\');
    }

    public static function pagesDir(): string
    {
        return self::contentDir() . '/pages';
    }

    public static function assetsDir(): string
    {
        return self::contentDir() . '/assets';
    }
}
