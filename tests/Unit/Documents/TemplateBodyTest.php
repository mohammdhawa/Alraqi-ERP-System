<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Templates\TemplateBody;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Piece 3, Task 2: the template page's sanitiser (Review Focus 1). */
class TemplateBodyTest extends TestCase
{
    private function clean(string $html): string
    {
        return (new TemplateBody)->sanitize($html);
    }

    public function test_the_whitelist_is_kept(): void
    {
        $html = '<h1>عنوان</h1><h2>ب</h2><p dir="rtl" style="text-align: center; font-size: 14pt"><strong>a</strong><b>b</b><em>c</em><i>d</i><u>e</u><br></p>'
            .'<ul><li>x</li></ul><ol><li>y</li></ol><hr>'
            .'<table style="text-align: right"><thead><tr><th colspan="2">h</th></tr></thead><tbody><tr><td rowspan="2">1</td><td>2</td></tr></tbody></table>'
            .'<div class="page-break"></div><p><span data-placeholder="field.name">الاسم</span></p>';

        $out = $this->clean($html);

        foreach (['<h1>', '<h2>', '<strong>', '<b>', '<em>', '<i>', '<u>', '<br />', '<hr />', '<ul>', '<ol>', '<li>',
            '<thead>', '<tbody>', '<th colspan="2">', '<td rowspan="2">', 'dir="rtl"',
            'style="text-align: center; font-size: 14pt"', '<div class="page-break">',
            '<span data-placeholder="field.name">الاسم</span>'] as $needle) {
            $this->assertStringContainsString($needle, $out, $needle);
        }
    }

    public function test_markup_aimed_at_the_printer_is_removed(): void
    {
        $out = $this->clean('<p style="background:url(file:///etc/passwd)">a<img src="/etc/passwd"></p>'
            .'<script>alert(1)</script><annotation content="x">ann</annotation><barcode code="1" />'
            .'<pagebreak /><a href="http://x">t</a><style>p{background:url(x)}</style><link rel="stylesheet" href="x">'
            .'<table background="/etc/passwd"><tr><td style="background-image:url(x)">c</td></tr></table>');

        foreach (['<img', 'script', 'alert', '<annotation', '<barcode', '<pagebreak', '<a ', 'href', 'url(', 'etc/passwd', '<style', '<link', 'background'] as $bad) {
            $this->assertStringNotContainsString($bad, $out, $bad);
        }

        $this->assertStringContainsString('annt', $out); // the text of the removed tags stays
        $this->assertStringContainsString('<td>c</td>', $out);
    }

    public function test_data_attributes_and_unknown_attributes_are_removed(): void
    {
        $out = $this->clean('<p data-x="1" onclick="x()" id="i" class="c">p</p><span data-placeholder="bad key">s</span><span data-other="1">o</span>');

        $this->assertSame('<p>p</p><span>s</span><span>o</span>', $out);
    }

    public function test_style_keeps_only_alignment_and_a_sane_font_size(): void
    {
        $this->assertSame('<p style="text-align: center">x</p>',
            $this->clean('<p style="font-size: 72pt; color: red; text-align: center">x</p>'));
        $this->assertSame('<p>x</p>', $this->clean('<p style="font-size: 7pt; text-align: diagonal; color: red">x</p>'));
        $this->assertSame('<p style="font-size: 36pt">x</p>', $this->clean('<p style="font-size: 36pt">x</p>'));
        $this->assertSame('<p>x</p>', $this->clean('<p style="font-size: 14pt url(x)">x</p>'));
    }

    public function test_round_and_decimal_font_sizes_are_kept_and_sanitising_is_idempotent(): void
    {
        foreach (['10pt' => '10pt', '20pt' => '20pt', '30pt' => '30pt', '12.50pt' => '12.5pt', '8pt' => '8pt'] as $in => $out) {
            $this->assertSame("<p style=\"font-size: {$out}\">x</p>", $this->clean("<p style=\"font-size: {$in}\">x</p>"), $in);
        }

        $page = '<p style="font-size: 10pt">a</p><p style="font-size: 20pt; text-align: right">b</p><p style="font-size: 12.50pt">c</p><p style="font-size: 30pt">d</p>';
        $once = $this->clean($page);

        $this->assertSame($once, $this->clean($once));
        $this->assertStringContainsString('font-size: 20pt', $once);
    }

    public function test_a_page_break_div_survives_and_other_classes_do_not(): void
    {
        $this->assertSame('<div class="page-break"></div>', $this->clean('<div class="page-break"></div>'));
        $this->assertSame('<div></div>', $this->clean('<div class="page-break other"></div>'));
        $this->assertSame('<div></div>', $this->clean('<div class="x"></div>'));
        $this->assertSame('<p>x</p>', $this->clean('<p class="page-break">x</p>'));
    }

    public function test_spans_and_colspan_values_are_bounded(): void
    {
        $out = $this->clean('<table><tr><td colspan="99" rowspan="x">a</td><td colspan="20">b</td></tr></table><p dir="up">q</p>');

        $this->assertStringContainsString('<td>a</td>', $out);
        $this->assertStringContainsString('<td colspan="20">b</td>', $out);
        $this->assertStringContainsString('<p>q</p>', $out);
    }

    public function test_chips_are_found_in_order_once_each(): void
    {
        $html = '<p><span data-placeholder="system.date">d</span> <span data-placeholder="field.b">b</span></p>'
            .'<p><span data-placeholder="field.a">a</span><span data-placeholder="field.b">b2</span></p><span>none</span>';

        $this->assertSame(['system.date', 'field.b', 'field.a'], (new TemplateBody)->placeholders($html));
        $this->assertSame([], (new TemplateBody)->placeholders(''));
    }

    /**
     * Ruling R18: a cleaning that fails is an error, never an empty page
     * (which a save would store over the administrator's work).
     */
    public function test_a_failed_regex_throws_instead_of_emptying_the_page(): void
    {
        $this->assertStringContainsString('Malformed UTF-8', $this->failureOf("<p>نص \xFF</p>"));

        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');

        try {
            $failure = $this->failureOf('<p>نص</p><style>'.str_repeat('a', 5000));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        $this->assertStringContainsString('Backtrack limit', $failure);
        // The same page, under the usual limit, is cleaned.
        $this->assertSame('<p>نص</p>', $this->clean('<p>نص</p><style>'.str_repeat('a', 5000)));
    }

    /** The message of the RuntimeException cleaning the page throws; what it returned when it did not. */
    private function failureOf(string $html): string
    {
        try {
            return 'cleaned to «'.$this->clean($html).'»';
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }
}
