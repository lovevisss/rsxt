<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepartmentSpreadsheet
{
    public const HEADERS = ['职工编号', '原部门代码', '目标部门代码', '变动日期', '变动原因', '备注二'];

    /** @return list<array<string, int|string>> */
    public function parse(UploadedFile $file): array
    {
        $book = IOFactory::load($file->getRealPath());
        $sheet = $book->getSheet(0);
        $last = $sheet->getHighestDataRow();
        if ($last > 501) {
            throw new InvalidArgumentException('一次最多导入 500 条记录。');
        }
        if (array_map('trim', $sheet->rangeToArray('A1:F1')[0]) !== self::HEADERS) {
            throw new InvalidArgumentException('表头与下载模板不一致。');
        }
        $rows = [];
        for ($line = 2; $line <= $last; $line++) {
            $values = $sheet->rangeToArray("A{$line}:F{$line}", null, false, false)[0];
            if (is_numeric($values[3]) && (float) $values[3] > 20000) {
                $values[3] = Date::excelToDateTimeObject((float) $values[3])->format('Y-m-d');
            }
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows[] = ['line' => $line, 'employee_number' => trim((string) $values[0]),
                'source_department' => trim((string) $values[1]),
                'target_department' => trim((string) $values[2]),
                'changed_at' => trim((string) $values[3]), 'reason' => trim((string) $values[4]),
                'note' => trim((string) $values[5])];
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows
     * @param  array<int, array{code:string,label:string}>  $departments
     */
    public function download(array $rows, array $departments, bool $template): StreamedResponse
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($template ? '导入模板' : '人员部门变动');
        $headers = $template ? self::HEADERS : ['姓名', ...self::HEADERS];
        $sheet->fromArray($headers, null, 'A1');
        foreach ($rows as $index => $row) {
            $data = [$row['employee_number'], $row['source_department'], $row['target_department'],
                $row['changed_at'], $row['reason'], $row['note']];
            $sheet->fromArray($template ? $data : [$row['name'], ...$data], null, 'A'.($index + 2));
        }
        $lastColumn = $template ? 'F' : 'G';
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $numberColumn = $template ? 'A' : 'B';
        $sheet->getStyle("{$numberColumn}2:{$numberColumn}".max(2, count($rows) + 1))->getNumberFormat()->setFormatCode('@');
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        if ($template) {
            $reference = $book->createSheet();
            $reference->setTitle('部门代码');
            $reference->fromArray(['代码', '部门名称'], null, 'A1');
            foreach ($departments as $index => $department) {
                $reference->fromArray([$department['code'], $department['label']], null, 'A'.($index + 2));
            }
            $reference->getColumnDimension('A')->setWidth(18);
            $reference->getColumnDimension('B')->setWidth(38);
        }

        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $template ? 'department-import-template.xlsx' : 'department-changes.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
