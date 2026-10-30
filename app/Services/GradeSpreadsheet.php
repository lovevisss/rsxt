<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradeSpreadsheet
{
    public const HEADERS = ['职工编号', '本部等级代码', '本部聘任时间', '本部结束时间', '东方等级代码', '东方聘任时间', '东方结束时间', '备注'];

    /** @return list<array<string, int|string>> */
    public function parse(UploadedFile $file): array
    {
        $book = IOFactory::load($file->getRealPath());
        $sheet = $book->getSheet(0);
        $last = $sheet->getHighestDataRow();
        if ($last > 501) {
            throw new InvalidArgumentException('一次最多导入 500 条记录。');
        }
        if (array_map('trim', $sheet->rangeToArray('A1:H1')[0]) !== self::HEADERS) {
            throw new InvalidArgumentException('表头与下载模板不一致。');
        }
        $rows = [];
        for ($line = 2; $line <= $last; $line++) {
            $values = $sheet->rangeToArray("A{$line}:H{$line}", null, false, false)[0];
            foreach ([2, 3, 5, 6] as $dateColumn) {
                if (is_numeric($values[$dateColumn]) && (float) $values[$dateColumn] > 20000) {
                    $values[$dateColumn] = Date::excelToDateTimeObject((float) $values[$dateColumn])->format('Y-m-d');
                }
            }
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows[] = ['line' => $line, 'employee_number' => trim((string) $values[0]),
                'bb_grade' => trim((string) $values[1]), 'bb_start' => trim((string) $values[2]),
                'bb_end' => trim((string) $values[3]), 'df_grade' => trim((string) $values[4]),
                'df_start' => trim((string) $values[5]), 'df_end' => trim((string) $values[6]),
                'note' => trim((string) $values[7])];
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows
     * @param  array<int, array{code:string,label:string}>  $grades
     */
    public function download(array $rows, array $grades, bool $template): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($template ? '导入模板' : '所聘岗位等级');
        $headers = $template ? self::HEADERS : ['序号', '姓名', '职工编号', '部门', ...array_slice(self::HEADERS, 1)];
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $data = [$row['employee_number'], $row['bb_grade'], $row['bb_start'], $row['bb_end'],
                $row['df_grade'], $row['df_start'], $row['df_end'], $row['note']];
            if (! $template) {
                $data = [$row['sequence'], $row['name'], ...array_slice($data, 0, 1), $row['department'], ...array_slice($data, 1)];
            }
            $sheet->fromArray($data, null, 'A'.($index + 2));
        }
        $lastColumn = $template ? 'H' : 'K';
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $numberColumn = $template ? 'A' : 'C';
        $sheet->getStyle("{$numberColumn}2:{$numberColumn}".max(2, count($rows) + 1))->getNumberFormat()->setFormatCode('@');
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        if ($template) {
            $reference = $book->createSheet();
            $reference->setTitle('等级代码');
            $reference->fromArray(['代码', '等级名称'], null, 'A1');
            foreach ($grades as $index => $grade) {
                $reference->fromArray([$grade['code'], $grade['label']], null, 'A'.($index + 2));
            }
            $reference->getColumnDimension('A')->setWidth(18);
            $reference->getColumnDimension('B')->setWidth(32);
        }

        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $template ? 'grade-import-template.xlsx' : 'grade-appointments.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
