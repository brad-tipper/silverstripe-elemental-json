<?php

namespace BradTipper\ElementalJson\Tests;

use BradTipper\ElementalJson\Content\HtmlRefs;
use PHPUnit\Framework\TestCase;

/** Pure HTML reference conversion: no database or framework boot needed. */
class HtmlRefsTest extends TestCase
{
    private function convert(string $html): string
    {
        return HtmlRefs::toShortcodes(
            $html,
            static fn (string $ref) => ['id' => 5, 'url' => '/assets/Managed/' . substr($ref, 1), 'width' => 800, 'height' => 400],
            static fn (string $ref) => 6,
            static fn (string $slug) => $slug === 'help/billing' ? 7 : 1,
        );
    }

    public function testImageBecomesAShortcodeAtItsOwnSize(): void
    {
        $this->assertSame(
            '<p>[image src="/assets/Managed/a/b.png" id="5" width="800" height="400" class="leftAlone ss-htmleditorfield-file image" alt="A > B"]</p>',
            $this->convert('<p><img src="@a/b.png" alt="A > B" /></p>')
        );
    }

    public function testOneSideKeepsTheShapeAndClassIsKept(): void
    {
        $this->assertSame(
            '[image src="/assets/Managed/b.png" id="5" width="300" height="150" class="center" alt="Say &quot;hi&quot;"]',
            $this->convert("<img class='center' width='300' src='@b.png' alt='Say \"hi\"'>")
        );
    }

    public function testFileAndPageLinks(): void
    {
        $this->assertSame(
            '<a class="x" href="[file_link,id=6]#page=2">f</a> <a href="[sitetree_link,id=7]#refunds" target="_blank">p</a>',
            $this->convert('<a class="x" href="@g.pdf#page=2">f</a> <a href="page:help/billing#refunds" target="_blank">p</a>')
        );
    }

    public function testOrdinaryHtmlIsUntouched(): void
    {
        $html = '<p>@someone <a href="/help">h</a> <a href="mailto:a@b.nz">m</a> <img src="https://x.test/a.png"></p>'
            . '<abbr data-href="@no">x</abbr> <code>&lt;img src="@no.png"&gt;</code>';
        $this->assertSame($html, $this->convert($html));
        $this->assertSame(['assets' => [], 'pages' => []], HtmlRefs::find($html));
    }

    public function testFindListsEveryReference(): void
    {
        $this->assertSame(
            ['assets' => ['@a.png', '@g.pdf'], 'pages' => ['help']],
            HtmlRefs::find('<img src="@a.png"><a href="@g.pdf#x">g</a><a href="page:help?x=1">h</a>')
        );
    }

    public function testExportReversesTheConversion(): void
    {
        $authored = '<p><img src="@b.png" width="800" height="400" alt="B"> <a href="@g.pdf">g</a> <a href="page:help/billing#r">p</a></p>';
        $stored = $this->convert('<p><img src="@b.png" alt="B"> <a href="@g.pdf">g</a> <a href="page:help/billing#r">p</a></p>');
        $restored = HtmlRefs::fromShortcodes(
            $stored,
            static fn (int $id) => [5 => '@b.png', 6 => '@g.pdf'][$id] ?? null,
            static fn (int $id) => $id === 7 ? 'help/billing' : null,
        );
        $this->assertSame($authored, $restored);
        $this->assertSame($stored, $this->convert($restored));
    }

    public function testExportLeavesUnknownIdsAlone(): void
    {
        $html = '[image id="9" src="x"] <a href="[sitetree_link,id=9]">p</a>';
        $this->assertSame($html, HtmlRefs::fromShortcodes($html, static fn () => null, static fn () => null));
    }
}
