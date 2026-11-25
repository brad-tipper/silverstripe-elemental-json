<?php

namespace BradTipper\ElementalJson\Tests;

use BradTipper\ElementalJson\Content\ContentLoader;
use BradTipper\ElementalJson\ContentException;
use PHPUnit\Framework\TestCase;

/** Pure file parsing and validation: no database or framework boot needed. */
class ContentLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ej-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/pages/help', 0777, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private function write(string $path, string $body): void
    {
        file_put_contents($this->dir . '/pages/' . $path, $body);
    }

    public function testPathIsTheSlugAndBothFormatsLoad(): void
    {
        $this->write('index.yml', "blocks:\n  - {id: hero, type: hero, title: Hi}\n");
        $this->write('help/billing.json', '{"blocks":[{"id":"a","type":"content"}]}');
        $this->write('help/index.yaml', "blocks: []\n");

        $slugs = array_map(static fn ($p) => $p->slug, (new ContentLoader())->load($this->dir . '/pages'));

        $this->assertEqualsCanonicalizing(['home', 'help', 'help/billing'], $slugs);
    }

    public function testExplicitSlugOverridesThePath(): void
    {
        $this->write('whatever.yml', "slug: /About/Team/\nblocks: []\n");

        $page = (new ContentLoader())->load($this->dir . '/pages')[0];

        $this->assertSame('about/team', $page->slug);
    }

    public function testAssetRefsAreCollectedFromNestedValues(): void
    {
        $this->write('a.yml', "blocks:\n  - id: h\n    type: hero\n    media: '@img/a.png'\n    buttons:\n      - {text: x, icon: '@img/b.svg'}\n");

        $page = (new ContentLoader())->load($this->dir . '/pages')[0];

        $this->assertSame(['@img/a.png', '@img/b.svg'], $page->assetRefs());
    }

    /** @dataProvider badFiles */
    public function testInvalidFilesAreRejectedWithTheFileNamed(string $body, string $expected): void
    {
        $this->write('bad.yml', $body);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches($expected);
        (new ContentLoader())->load($this->dir . '/pages');
    }

    public static function badFiles(): array
    {
        return [
            'no blocks' => ["title: x\n", '/bad\.yml.*blocks/'],
            'unknown top key' => ["blocks: []\nnope: 1\n", '/unknown top-level key.*nope/'],
            'block without id' => ["blocks:\n  - {type: hero}\n", '/needs an .id./'],
            'block without type' => ["blocks:\n  - {id: a}\n", '/needs a .type./'],
            'duplicate id' => ["blocks:\n  - {id: a, type: x}\n  - {id: a, type: y}\n", '/used twice/'],
            'bad slug' => ["slug: ../etc\nblocks: []\n", '/invalid slug/'],
            'not a mapping' => ["- a\n- b\n", '/mapping/'],
        ];
    }

    public function testTwoFilesForOneSlugAreRejected(): void
    {
        $this->write('a.yml', "slug: x\nblocks: []\n");
        $this->write('b.json', '{"slug":"x","blocks":[]}');

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches("/Two files configure the page 'x'/");
        (new ContentLoader())->load($this->dir . '/pages');
    }
}
