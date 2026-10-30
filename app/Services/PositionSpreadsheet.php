<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PositionSpreadsheet
{
    public const HEADERS = ['类型', '职工编号', '原聘岗位代码', '原聘任时间', '现聘岗位代码', '现聘任时间', '变动原因', '备注'];

    /** @return list<array<string, int|string>> */
    public function parse(UploadedFile $file): array
    {
        $book = IOFactory::load($file->getRealPath());
        $sheet = $book->getSheet(0);
        $last = $sheet->getHighestDataRow();
        if ($last > 501) {
            throw new \InvalidArgumentException('一次最多导入 500 条记录。');
        }
        $header = $sheet->rangeToArray('A1:H1')[0];
        if (array_map('trim', $header) !== self::HEADERS) {
            throw new \InvalidArgumentException('表头与下载模板不一致。');
        }
        $rows = [];
        for ($line = 2; $line <= $last; $line++) {
            $values = $sheet->rangeToArray("A{$line}:H{$line}", null, false, false)[0];
            foreach ([3, 5] as $dateColumn) {
                if (is_numeric($values[$dateColumn]) && (float) $values[$dateColumn] > 20000) {
                    $values[$dateColumn] = Date::excelToDateTimeObject((float) $values[$dateColumn])->format('Y-m-d');
                }
            }
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows[] = ['line' => $line, 'type' => trim((string) $values[0]), 'employee_number' => trim((string) $values[1]),
                'old_position' => trim((string) $values[2]), 'old_date' => trim((string) $values[3]),
                'new_position' => trim((string) $values[4]), 'new_date' => trim((string) $values[5]),
                'reason' => trim((string) $values[6]), 'note' => trim((string) $values[7])];
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows
     * @param  array<int, array{code:string,label:string}>  $positions
     */
    public function download(array $rows, array $positions, bool $template): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($template ? '导入模板' : '岗位变动');
        $sheet->fromArray(self::HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            $sheet->fromArray([$row['type'], $row['employee_number'], $row['old_position'], $row['old_date'],
                $row['new_position'], $row['new_date'], $row['reason'], $row['note']], null, 'A'.($index + 2));
        }
        $sheet->getStyle('A1:H1')->getFont()->setBold(true);
        $sheet->getStyle('B2:B'.max(2, count($rows) + 1))->getNumberFormat()->setFormatCode('@');
        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        if ($template) {
            $reference = $book->createSheet();
            $reference->setTitle('岗位代码');
            $reference->fromArray(['代码', '岗位名称'], null, 'A1');
            foreach ($positions as $index => $position) {
                $reference->fromArray([$position['code'], $position['label']], null, 'A'.($index + 2));
            }
            $reference->getColumnDimension('A')->setWidth(18);
            $reference->getColumnDimension('B')->setWidth(32);
        }
        $name = $template ? 'position-import-template.xlsx' : 'position-changes.xlsx';

        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
