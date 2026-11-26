<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Templates\Placeholders;
use App\Modules\Documents\Templates\TemplateFields;
use PHPUnit\Framework\TestCase;

class TemplateFieldsTest extends TestCase
{
    private function f(array $over = []): array
    {
        return array_merge(['key' => 'name', 'label' => 'الاسم', 'type' => 'text', 'required' => true], $over);
    }

    public function test_a_valid_schema_has_no_problems(): void
    {
        $this->assertSame([], TemplateFields::problems([]));
        $this->assertSame([], TemplateFields::problems([
            $this->f(),
            $this->f(['key' => 'kind', 'type' => 'select', 'options' => ['a', 'b']]),
            $this->f(['key' => 'items', 'type' => 'table', 'columns' => [['key' => 'a', 'label' => 'أ']]]),
        ]));
    }

    public function test_more_than_fifty_fields(): void
    {
        $fields = [];
        for ($i = 0; $i < 51; $i++) {
            $fields[] = $this->f(['key' => 'k'.$i]);
        }

        $this->assertContains('لا تزيد حقول القالب على 50.', TemplateFields::problems($fields));
    }

    public function test_a_bad_key(): void
    {
        $msg = 'مفتاح الحقل يبدأ بحرف إنجليزي صغير ويتكوّن من أحرف إنجليزية صغيرة وأرقام و _ فقط.';

        foreach (['Name', '1a', 'a-b', '', str_repeat('a', 51)] as $key) {
            $this->assertSame([$msg], TemplateFields::problems([$this->f(['key' => $key])]), (string) $key);
        }

        $this->assertSame([$msg], TemplateFields::problems([$this->f(['key' => null])]));
    }

    public function test_a_duplicate_key(): void
    {
        $this->assertSame(['مفتاح الحقل «name» مكرر.'], TemplateFields::problems([$this->f(), $this->f()]));
    }

    public function test_a_missing_label(): void
    {
        $this->assertSame(['تسمية الحقل مطلوبة.'], TemplateFields::problems([$this->f(['label' => ' '])]));
        $this->assertSame(['تسمية الحقل مطلوبة.'], TemplateFields::problems([$this->f(['label' => str_repeat('ا', 101)])]));
    }

    public function test_an_unsupported_type(): void
    {
        $this->assertSame(['نوع الحقل غير مدعوم.'], TemplateFields::problems([$this->f(['type' => 'file'])]));
    }

    public function test_a_select_needs_options(): void
    {
        $msg = 'حقل القائمة «الاسم» يحتاج إلى خيار واحد على الأقل.';

        $this->assertSame([$msg], TemplateFields::problems([$this->f(['type' => 'select'])]));
        $this->assertSame([$msg], TemplateFields::problems([$this->f(['type' => 'select', 'options' => []])]));
        $this->assertSame([$msg], TemplateFields::problems([$this->f(['type' => 'select', 'options' => [str_repeat('x', 101)]])]));
    }

    public function test_a_table_needs_columns(): void
    {
        $msg = 'حقل الجدول «الاسم» يحتاج إلى عمود واحد على الأقل.';

        $this->assertSame([$msg], TemplateFields::problems([$this->f(['type' => 'table'])]));
        $this->assertSame([$msg], TemplateFields::problems([$this->f(['type' => 'table', 'columns' => []])]));
    }

    public function test_column_keys_follow_the_key_rule(): void
    {
        $problems = TemplateFields::problems([$this->f(['type' => 'table', 'columns' => [
            ['key' => 'A', 'label' => 'x'], ['key' => 'b', 'label' => 'y'], ['key' => 'b', 'label' => 'z'], ['key' => 'c', 'label' => ''],
        ]])]);

        $this->assertContains('مفتاح الحقل «b» مكرر.', $problems);
        $this->assertContains('تسمية الحقل مطلوبة.', $problems);
        $this->assertCount(3, $problems);
    }

    public function test_the_accessors(): void
    {
        $fields = TemplateFields::fromArray([$this->f(), $this->f(['key' => 'other'])]);

        $this->assertSame(['name', 'other'], $fields->keys());
        $this->assertSame('الاسم', $fields->get('name')['label']);
        $this->assertNull($fields->get('nope'));
        $this->assertCount(2, $fields->all());
    }

    public function test_placeholders_unknown_to_the_template(): void
    {
        $fields = TemplateFields::fromArray([$this->f()]);
        $found  = ['field.name', 'field.gone', 'system.date', 'recipient.name', 'bogus'];

        $this->assertSame(['field.gone', 'recipient.name', 'bogus'], Placeholders::unknown($found, $fields, false));
        $this->assertSame(['field.gone', 'bogus'], Placeholders::unknown($found, $fields, true));
        $this->assertContains('recipient.employee_number', Placeholders::allowed($fields, true));
        $this->assertNotContains('recipient.unit', Placeholders::allowed($fields, false));
        $this->assertContains('system.reference', Placeholders::allowed($fields, false));
    }
}
