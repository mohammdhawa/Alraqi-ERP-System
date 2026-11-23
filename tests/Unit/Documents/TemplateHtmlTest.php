<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Models\DocumentTemplate;
use App\Modules\Documents\Pdf\TemplateHtml;
use App\Modules\Documents\Support\RecipientValues;
use App\Modules\Documents\Support\SystemValues;
use App\Modules\Documents\Templates\Placeholders;
use App\Modules\Documents\Templates\TemplateBody;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The HTML a template prints as (documents design §11.2): the stored page
 * with every chip replaced by its value, or a hand-made layout, in the frame.
 */
class TemplateHtmlTest extends TestCase
{
    /** Characters that mean something in HTML or Blade, and Arabic mixed with Latin and digits (Review Focus 2). */
    private const TYPED = '<b>x</b> a & b {{ $x }} @php «مبلغ 1,250 USD»';

    private const TYPED_ESCAPED = '&lt;b&gt;x&lt;/b&gt; a &amp; b {{ $x }} @php «مبلغ 1,250 USD»';

    private const ITEMS = [
        'key'      => 'items',
        'label'    => 'البنود',
        'type'     => 'table',
        'required' => false,
        'columns'  => [['key' => 'name', 'label' => 'الصنف'], ['key' => 'qty', 'label' => 'الكمية']],
    ];

    private const SUBJECT = ['key' => 'subject', 'label' => 'الموضوع', 'type' => 'text', 'required' => false];

    /** A page as it is stored: through the sanitiser. */
    private function page(string $html, array $fields = [], bool $forEmployees = false): DocumentTemplate
    {
        return new DocumentTemplate([
            'name'             => 'قالب تجريبي',
            'reference_prefix' => 'GEN',
            'fields'           => $fields,
            'body'             => (new TemplateBody)->sanitize($html),
            'for_employees'    => $forEmployees,
            'show_letterhead'  => true,
        ]);
    }

    private function chip(string $key): string
    {
        return "<span data-placeholder=\"{$key}\">تسمية المحرر</span>";
    }

    private function system(): SystemValues
    {
        // 22:30 UTC on 31 December is already 1 January in Damascus.
        return new SystemValues(123, 45, 'GEN-2026-0001', CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC'), 'محمد الأحمد', 'إدارة الموارد البشرية');
    }

    private function render(DocumentTemplate $template, array $values = [], ?RecipientValues $recipient = null): string
    {
        return app(TemplateHtml::class)->render($template, $values, $this->system(), $recipient);
    }

    public function test_each_chip_prints_its_value(): void
    {
        $fields = [
            self::SUBJECT,
            ['key' => 'due', 'label' => 'الموعد', 'type' => 'date', 'required' => false],
            ['key' => 'notes', 'label' => 'ملاحظات', 'type' => 'textarea', 'required' => false],
        ];
        $keys = ['field.subject', 'field.due', 'field.notes', ...Placeholders::SYSTEM, ...Placeholders::RECIPIENT];
        $template = $this->page('<p>'.implode(' | ', array_map($this->chip(...), $keys)).'</p>', $fields, true);
        $values = ['subject' => 'طلب صيانة', 'due' => '2026-11-24', 'notes' => "سطر أول\nسطر ثانٍ"];

        $html = $this->render($template, $values, new RecipientValues('سارة الخطيب', 'محاسبة', 'الإدارة المالية', 'E-77'));

        foreach ([
            'طلب صيانة', '2026/11/24', 'سطر أول<br>سطر ثانٍ',
            '| 123 |', '| 45 |', 'GEN-2026-0001', '2027/01/01', 'محمد الأحمد', 'إدارة الموارد البشرية',
            'سارة الخطيب', 'محاسبة', 'الإدارة المالية', 'E-77',
        ] as $value) {
            $this->assertStringContainsString($value, $html);
        }
        $this->assertStringNotContainsString('data-placeholder', $html);
        $this->assertStringNotContainsString('تسمية المحرر', $html);

        $withoutRecipient = $this->render($template, $values);
        $this->assertStringNotContainsString('سارة الخطيب', $withoutRecipient);
        $this->assertStringNotContainsString('data-placeholder', $withoutRecipient);
        $this->assertStringContainsString('طلب صيانة', $withoutRecipient);
    }

    public function test_a_chips_own_style_is_kept(): void
    {
        $template = $this->page('<p><span data-placeholder="field.subject" style="font-size: 14pt">تسمية المحرر</span></p>', [self::SUBJECT]);

        $html = $this->render($template, ['subject' => 'عنوان']);

        $this->assertStringContainsString('<span style="font-size: 14pt">عنوان</span>', $html);
    }

    public function test_values_print_as_typed_never_as_markup(): void
    {
        $template = $this->page('<p>'.$this->chip('field.subject').'</p><p>'.$this->chip('field.items').'</p>', [self::SUBJECT, self::ITEMS]);

        $html = $this->render($template, ['subject' => self::TYPED, 'items' => [['name' => self::TYPED, 'qty' => '1,250']]]);

        $this->assertStringContainsString('<p>'.self::TYPED_ESCAPED.'</p>', $html);
        $this->assertStringContainsString('<td>'.self::TYPED_ESCAPED.'</td>', $html);
        $this->assertStringNotContainsString('<b>', $html);

        $layout = new DocumentTemplate([
            'name'             => 'طلب شراء '.self::TYPED,
            'reference_prefix' => 'PR',
            'layout_key'       => 'purchase_request',
            'show_letterhead'  => true,
            'fields'           => [
                ['key' => 'vendor_name', 'label' => 'اسم المورد', 'type' => 'text', 'required' => true],
                ['key' => 'total_amount', 'label' => 'المبلغ الإجمالي', 'type' => 'number', 'required' => true],
                ['key' => 'currency', 'label' => 'العملة', 'type' => 'select', 'required' => true, 'options' => ['USD', 'SYP']],
                ['key' => 'delivery_date', 'label' => 'تاريخ التسليم المتوقع', 'type' => 'date', 'required' => false],
                self::ITEMS,
                ['key' => 'justification', 'label' => 'مبررات الشراء', 'type' => 'textarea', 'required' => false],
            ],
        ]);

        $printed = $this->render($layout, [
            'vendor_name'   => self::TYPED,
            'total_amount'  => '1250',
            'currency'      => 'USD',
            'delivery_date' => '2026-12-01',
            'items'         => [['name' => self::TYPED, 'qty' => '2']],
            'justification' => self::TYPED."\n".'سطر ثانٍ',
        ]);

        $this->assertSame(4, substr_count($printed, self::TYPED_ESCAPED), 'the name, the vendor, a cell and the justification');
        $this->assertStringNotContainsString('<b>', $printed);
        $this->assertStringContainsString(self::TYPED_ESCAPED.'<br>سطر ثانٍ', $printed);
        foreach (['رقم الصادر', '123', 'رقم الوارد', '45', 'المرجع', 'GEN-2026-0001', 'التاريخ', '2027/01/01', 'إدارة الموارد البشرية', '1250', 'USD', '2026/12/01', '<th>الصنف</th>', '<th>الكمية</th>'] as $text) {
            $this->assertStringContainsString($text, $printed);
        }
    }

    public function test_the_layout_leaves_out_an_import_number_it_does_not_have(): void
    {
        $layout = new DocumentTemplate(['name' => 'طلب شراء', 'reference_prefix' => 'PR', 'layout_key' => 'purchase_request', 'fields' => []]);
        $system = new SystemValues(7, null, 'PR-2026-0001', CarbonImmutable::now(), 'محمد', 'المشتريات');

        $printed = app(TemplateHtml::class)->render($layout, [], $system, null);

        $this->assertStringContainsString('رقم الصادر', $printed);
        $this->assertStringNotContainsString('رقم الوارد', $printed);
    }

    public function test_a_table_chip_alone_in_a_paragraph_replaces_it(): void
    {
        $template = $this->page('<p>قبل</p><p><strong>'.$this->chip('field.items').'</strong></p><p>بعد</p>', [self::ITEMS]);

        $html = $this->render($template, ['items' => [['name' => 'قلم', 'qty' => '3'], ['name' => 'دفتر', 'qty' => '5']]]);

        $this->assertMatchesRegularExpression(
            '~<p>قبل</p><table><thead><tr><th>الصنف</th><th>الكمية</th></tr></thead>'
            .'<tbody><tr><td>قلم</td><td>3</td></tr><tr><td>دفتر</td><td>5</td></tr></tbody></table><p>بعد</p>~u',
            $html,
        );
    }

    public function test_a_table_chip_inside_text_follows_the_paragraph(): void
    {
        $more = ['key' => 'more', 'label' => 'إضافات', 'type' => 'table', 'required' => false, 'columns' => [['key' => 'what', 'label' => 'البيان']]];
        $template = $this->page('<p>البنود: '.$this->chip('field.items').' ثم '.$this->chip('field.more').' كما يلي</p><p>بعد</p>', [self::ITEMS, $more]);

        $html = $this->render($template, ['items' => [['name' => 'قلم', 'qty' => '3']], 'more' => [['what' => 'تغليف']]]);

        $this->assertMatchesRegularExpression(
            '~<p>البنود:\s+ثم\s+كما يلي</p><table>.*?<td>قلم</td>.*?</table><table>.*?<td>تغليف</td>.*?</table><p>بعد</p>~u',
            $html,
        );
    }

    public function test_a_page_break_becomes_mpdf_pagebreak(): void
    {
        $html = $this->render($this->page('<p>أ</p><div class="page-break"></div><p>ب</p>'));

        $this->assertMatchesRegularExpression('~<p>أ</p><pagebreak\b[^>]*>(</pagebreak>)?<p>ب</p>~u', $html);
        $this->assertStringNotContainsString('page-break', $html);
    }

    public function test_an_empty_optional_value_prints_nothing(): void
    {
        $template = $this->page('<p>الاسم: '.$this->chip('field.subject').'</p><p>'.$this->chip('field.items').'</p><p>بعد</p>', [self::SUBJECT, self::ITEMS]);

        foreach ([['subject' => null, 'items' => null], ['subject' => '', 'items' => []], []] as $values) {
            $html = $this->render($template, $values);

            $this->assertMatchesRegularExpression('~<p>الاسم: </p><p>بعد</p>~u', $html);
            $this->assertStringNotContainsString('<table', $html);
            $this->assertStringNotContainsString('تسمية المحرر', $html);
        }
    }

    public function test_the_page_sits_in_the_rtl_frame(): void
    {
        $html = $this->render($this->page('<p>نص</p>'));

        $this->assertMatchesRegularExpression('~^\s*<div class="template-page" dir="rtl"><p>نص</p></div>\s*$~u', $html);
        $this->assertStringNotContainsString('<style', $html);
    }
}
