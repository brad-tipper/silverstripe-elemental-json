<?php

namespace BradTipper\ElementalJson\Assets;

use BradTipper\ElementalJson\ContentException;
use BradTipper\ElementalJson\ElementalJson;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;

/**
 * Imports files committed under `<content_dir>/assets` into the media library, in a read-only managed folder.
 * Only committed files are supported: a reference is `@images/hero.png`, relative to the assets directory.
 * Re-running is idempotent: a file is matched by its path, replaced only when its content hash changed.
 */
class AssetImporter
{
    /** @var array<string, File> */
    private array $imported = [];

    /** sha1 of the committed file; throws if it does not exist. Does not touch the media library. */
    public function hashOf(string $ref): string
    {
        [$absolute] = $this->resolve($ref);
        return (string) sha1_file($absolute);
    }

    public function import(string $ref): File
    {
        [$absolute, $relative] = $this->resolve($ref);
        if (isset($this->imported[$relative])) {
            return $this->imported[$relative];
        }
        $hash = (string) sha1_file($absolute);

        /** @var File|null $file */
        $file = File::get()->filter('ManagedPath', $relative)->first();
        if ($file && $file->ManagedHash === $hash && $file->exists()) {
            return $this->imported[$relative] = $file;
        }

        $directory = dirname($relative);
        $folderPath = trim((string) ElementalJson::config()->get('asset_folder'), '/')
            . ($directory !== '.' ? '/' . $directory : '');
        $folder = Folder::find_or_make($folderPath);
        $this->markFolders($folder);

        $basename = basename($relative);
        if (!$file) {
            $class = File::get_class_for_file_extension(strtolower(pathinfo($basename, PATHINFO_EXTENSION)));
            /** @var File $file */
            $file = Injector::inst()->create($class);
            $file->ParentID = $folder->ID;
        }
        $file->setFromLocalFile($absolute, $folder->getFilename() . $basename, null, null, [
            'conflict' => AssetStore::CONFLICT_OVERWRITE,
        ]);
        $file->ManagedPath = $relative;
        $file->ManagedHash = $hash;
        $file->write();
        $file->publishSingle();

        return $this->imported[$relative] = $file;
    }

    /** @return array{0: string, 1: string} absolute path and path relative to the assets directory */
    private function resolve(string $ref): array
    {
        $relative = ltrim(substr($ref, 1), '/');
        $root = realpath(ElementalJson::assetsDir());
        $absolute = $root ? realpath($root . '/' . $relative) : false;
        $inside = $absolute && str_starts_with(str_replace('\\', '/', $absolute), rtrim(str_replace('\\', '/', $root), '/') . '/');
        if (!$ref || $ref[0] !== '@' || !$inside || !is_file($absolute)) {
            throw new ContentException("Asset '$ref' not found. Assets must be committed under " . ElementalJson::config()->get('content_dir') . '/assets and referenced as @path/to/file.');
        }
        return [$absolute, str_replace('\\', '/', $relative)];
    }

    /** Mark every folder in the chain as managed, so the CMS leaves them read-only. */
    private function markFolders(Folder $folder): void
    {
        for ($f = $folder; $f && $f->exists(); $f = $f->ParentID ? Folder::get()->byID($f->ParentID) : null) {
            if (!$f->ManagedPath) {
                $f->ManagedPath = '(folder)';
                $f->write();
            }
        }
    }
}
