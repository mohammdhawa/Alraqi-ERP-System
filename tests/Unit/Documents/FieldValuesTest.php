<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Modules\Documents\Templates\FieldValues;
use App\Modules\Documents\Templates\TemplateFields;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldValuesTest extends TestCase
{
    private function fields(array ...$list): TemplateFields
    {
        return TemplateFields::fromArray($list);
    }

    private function f(string $type, array $over = []): array
    {
        return array_merge(['key' => 'f', 'label' => 'الحقل', 'type' => $type, 'required' => false], $over);
    }

    private function check(array $field, mixed $value): mixed
    {
        return (new FieldValues)->check($this->fields($field), ['f' => $value])['f'];
    }

    private function message(array $field, mixed $value): string
    {
        try {
            $this->check($field, $value);
        } catch (ValidationException $e) {
            $this->assertSame(['field_values.f'], array_keys($e->errors()));

            return $e->errors()['field_values.f'][0];
        }

        $this->fail('no ValidationException');
    }

    public function test_required(): void
    {
        foreach (['text', 'textarea', 'number', 'date'] as $type) {
            $this->assertSame('حقل «الحقل» مطلوب.', $this->message($this->f($type, ['required' => true]), '  '), $type);
        }
        $this->assertSame('حقل «الحقل» مطلوب.', $this->message($this->f('select', ['required' => true, 'options' => ['a']]), null));
    }

    public function test_text_is_trimmed_and_bounded(): void
    {
        $this->assertSame('x', $this->check($this->f('text'), '  x '));
        $this->assertSame(str_repeat('ا', 500), $this->check($this->f('text'), str_repeat('ا', 500)));
        $this->assertSame('حقل «الحقل» لا يزيد على 500 حرف.', $this->message($this->f('text'), str_repeat('ا', 501)));
    }

    public function test_textarea_keeps_line_breaks_and_is_bounded(): void
    {
        $this->assertSame("a\nb", $this->check($this->f('textarea'), "\n a\nb "));
        $this->assertSame('حقل «الحقل» لا يزيد على 5000 حرف.', $this->message($this->f('textarea'), str_repeat('x', 5001)));
    }

    public function test_number(): void
    {
        $this->assertSame('12.50', $this->check($this->f('number'), ' 12.50 '));
        $this->assertSame('7', $this->check($this->f('number'), 7));
        $this->assertSame('حقل «الحقل» يجب أن يكون رقماً.', $this->message($this->f('number'), '12a'));
    }

    public function test_date(): void
    {
        $this->assertSame('2026-11-24', $this->check($this->f('date'), '2026-11-24'));
        $expected = 'حقل «الحقل» يجب أن يكون تاريخاً بصيغة سنة-شهر-يوم.';
        $this->assertSame($expected, $this->message($this->f('date'), '24/11/2026'));
        $this->assertSame($expected, $this->message($this->f('date'), '2026-02-30'));
    }

    public function test_select(): void
    {
        $field = $this->f('select', ['options' => ['أ', 'ب']]);

        $this->assertSame('ب', $this->check($field, ' ب '));
        $this->assertSame('القيمة المختارة في «الحقل» غير صالحة.', $this->message($field, 'ج'));
        $this->assertSame('القيمة المختارة في «الحقل» غير صالحة.', $this->message($field, ['أ']));
    }

    public function test_table_rows_keep_only_their_columns(): void
    {
        $field = $this->f('table', ['columns' => [['key' => 'a', 'label' => 'أ'], ['key' => 'b', 'label' => 'ب']]]);

        $this->assertSame([['a' => 'x', 'b' => ''], ['a' => '', 'b' => '2']],
            $this->check($field, [['a' => ' x ', 'zzz' => 'drop'], ['b' => 2]]));
    }

    public function test_table_rules(): void
    {
        $field = $this->f('table', ['columns' => [['key' => 'a', 'label' => 'أ']]]);

        $this->assertSame('حقل «الحقل» يجب أن يكون جدولاً.', $this->message($field, 'text'));
        $this->assertSame('حقل «الحقل» يجب أن يكون جدولاً.', $this->message($field, ['a' => 'x']));
        $this->assertSame('حقل «الحقل» يجب أن يكون جدولاً.', $this->message($field, ['x']));
        $this->assertSame('جدول «الحقل» لا يزيد على 100 صف.', $this->message($field, array_fill(0, 101, ['a' => 'x'])));
        $this->assertCount(100, $this->check($field, array_fill(0, 100, ['a' => 'x'])));
        $this->assertSame('حقل «الحقل» لا يزيد على 500 حرف.', $this->message($field, [['a' => str_repeat('x', 501)]]));
    }

    public function test_a_required_table_needs_a_row(): void
    {
        $field = $this->f('table', ['required' => true, 'columns' => [['key' => 'a', 'label' => 'أ']]]);

        $this->assertSame('حقل «الحقل» مطلوب.', $this->message($field, []));
        $this->assertSame('حقل «الحقل» مطلوب.', $this->message($field, null));
    }

    public function test_an_optional_empty_value_is_null(): void
    {
        $table = $this->f('table', ['columns' => [['key' => 'a', 'label' => 'أ']]]);

        foreach (['text', 'textarea', 'number', 'date'] as $type) {
            $this->assertNull($this->check($this->f($type), ''), $type);
        }
        $this->assertNull($this->check($this->f('select', ['options' => ['a']]), null));
        $this->assertNull($this->check($table, []));
    }

    public function test_all_fields_are_reported_and_unknown_values_ignored(): void
    {
        $fields = $this->fields($this->f('text', ['key' => 'a', 'required' => true]), $this->f('number', ['key' => 'b']));

        try {
            (new FieldValues)->check($fields, ['b' => 'x', 'extra' => 1]);
            $this->fail('no ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['field_values.a', 'field_values.b'], array_keys($e->errors()));
        }

        $this->assertSame(['a' => 'x', 'b' => null], (new FieldValues)->check($fields, ['a' => 'x', 'extra' => 1]));
    }
}
