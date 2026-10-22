<?php

declare(strict_types=1);

namespace Tests\Feature\HR\Import;

use App\Modules\HR\Import\CellError;
use App\Modules\HR\Import\OpenSpoutWorkbookReader;
use App\Modules\HR\Import\WorkbookException;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

/**
 * The workbook reader against a real xlsx, written here in the shape of
 * `قاعدة البيانات`: two rows of banner above the header in row 3, an empty
 * column A, computed columns, and a gap.
 *
 * openspout writes a formula without its result, the way Excel never does;
 * the fixture patches the results into the sheet XML so the reader sees what
 * it sees in HR's files.
 */
class OpenSpoutWorkbookReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($this->path);
        $writer->getCurrentSheet()->setName('الواجهة');
        $writer->addRow(Row::fromValues(['not this sheet']));
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('قاعدة البيانات');
        $writer->addRows([
            Row::fromValues(['', 'برنامج الموارد']),
            Row::fromValues(['', 'شهر 10']),
            Row::fromValues(['', ' ID_Sarah ', 'الإسم الثلاثي', 'نوع النشاط', 'تاريخ التعيين', 'السوية التنظيمية', 'قيمة أساس الراتب']),
            new Row([
                Cell::fromValue(''),
                Cell::fromValue(582974643),
                Cell::fromValue('نور أحمد الشامي'),
                new FormulaCell('=XLOOKUP(1,1,1)', null),   // computed below: هندسي
                Cell::fromValue(new DateTimeImmutable('2022-01-01'), (new Style)->setFormat('yyyy-mm-dd')),
                new FormulaCell('=XLOOKUP(2,2,2)', null),   // computed below: #N/A
                Cell::fromValue(500),
            ]),
            Row::fromValues([]),
            Row::fromValues(['', '   ', null]),
            Row::fromValues(['', 671234567, 'سامر خالد الحلبي', '__NA__', null, 4]),
        ]);
        $writer->close();

        $this->patchSheet(2, [
            // A string formula's saved result, as Excel writes it.
            '><f>XLOOKUP(1,1,1)</f></c>'           => ' t="str"><f>XLOOKUP(1,1,1)</f><v>هندسي</v></c>',
            // A formula whose result is an error, and an error cell.
            '><f>XLOOKUP(2,2,2)</f></c>'           => ' t="e"><f>XLOOKUP(2,2,2)</f><v>#N/A</v></c>',
            't="inlineStr"><is><t>__NA__</t></is>' => 't="e"><v>#N/A</v>',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /** @param  array<string, string>  $replacements */
    private function patchSheet(int $index, array $replacements): void
    {
        $zip = new ZipArchive;
        $zip->open($this->path);
        $name = "xl/worksheets/sheet{$index}.xml";
        $zip->addFromString($name, strtr((string) $zip->getFromName($name), $replacements));
        $zip->close();
    }

    /** @return array<int, array<string, mixed>> */
    private function read(?array $columns = null): array
    {
        return (new OpenSpoutWorkbookReader)->rows($this->path, 'قاعدة البيانات', 3, $columns);
    }

    public function test_rows_are_keyed_by_their_sheet_row_number_and_trimmed_header(): void
    {
        $rows = $this->read();

        $this->assertSame([4, 7], array_keys($rows));
        $this->assertSame(582974643, $rows[4]['ID_Sarah']);
        $this->assertSame('سامر خالد الحلبي', $rows[7]['الإسم الثلاثي']);
    }

    public function test_the_headerless_column_a_is_not_read(): void
    {
        $this->assertArrayNotHasKey('', $this->read()[4]);
    }

    public function test_a_formula_reads_as_the_value_excel_computed(): void
    {
        $this->assertSame('هندسي', $this->read()[4]['نوع النشاط']);
    }

    public function test_a_formula_error_and_an_error_cell_are_cell_errors_not_blanks(): void
    {
        $rows = $this->read();

        $this->assertEquals(new CellError(''), $rows[4]['السوية التنظيمية']);
        $this->assertEquals(new CellError('#N/A'), $rows[7]['نوع النشاط']);
    }

    public function test_dates_are_dates_and_blank_cells_are_null(): void
    {
        $rows = $this->read();

        $this->assertInstanceOf(DateTimeImmutable::class, $rows[4]['تاريخ التعيين']);
        $this->assertSame('2022-01-01', $rows[4]['تاريخ التعيين']->format('Y-m-d'));
        $this->assertNull($rows[7]['تاريخ التعيين']);
    }

    public function test_only_the_asked_columns_are_read(): void
    {
        $rows = $this->read(['ID_Sarah', 'الإسم الثلاثي']);

        $this->assertSame(['ID_Sarah', 'الإسم الثلاثي'], array_keys($rows[4]));
    }

    public function test_an_asked_column_the_sheet_lacks_fails_the_read(): void
    {
        $this->expectException(WorkbookException::class);
        $this->expectExceptionMessage('المسمى الوظيفي');

        $this->read(['ID_Sarah', 'المسمى الوظيفي']);
    }

    public function test_a_missing_sheet_names_the_sheets_there_are(): void
    {
        $this->expectException(WorkbookException::class);
        $this->expectExceptionMessage('الواجهة');

        (new OpenSpoutWorkbookReader)->rows($this->path, 'إنهاء عمل', 3);
    }

    public function test_a_missing_file_fails_the_read(): void
    {
        $this->expectException(WorkbookException::class);

        (new OpenSpoutWorkbookReader)->rows($this->path.'.nope', 'قاعدة البيانات', 3);
    }
}
