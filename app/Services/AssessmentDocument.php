<?php

namespace App\Services;

use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;

class AssessmentDocument
{
    /** @param array<string, string|null> $values */
    public function generate(array $values, string $summary): string
    {
        $word = new PhpWord;
        $word->setDefaultFontName('SimSun');
        $word->setDefaultFontSize(11);
        $word->addTableStyle('assessment', [
            'borderSize' => 5, 'borderColor' => '333333', 'cellMargin' => 100,
        ]);
        $section = $word->addSection([
            'pageSizeW' => 11906, 'pageSizeH' => 16838,
            'marginTop' => 1440, 'marginBottom' => 1440,
            'marginLeft' => 1800, 'marginRight' => 1800,
        ]);
        $section->addText('浙江财经大学东方学院职工年度考核表', ['bold' => true, 'size' => 16], ['alignment' => 'center', 'spaceAfter' => 100]);
        $section->addText(($values['h2029'] ?? '').'年度', ['size' => 11], ['alignment' => 'center', 'spaceAfter' => 100]);
        $section->addText('部门  '.($values['h2003'] ?? ''), ['size' => 11], ['spaceAfter' => 100]);

        $table = $section->addTable('assessment');
        $this->triple($table, ['姓名', $values['h2001'] ?? '', '性别', $values['h2004'] ?? '', '出生年月', $values['h2005'] ?? '']);
        $this->triple($table, ['政治面貌', $values['h2006'] ?? '', '文化程度', $values['h2007'] ?? '', '行政职务', $values['h2008'] ?? '']);
        $table->addRow();
        $table->addCell(1500)->addText('所聘岗位类型');
        $table->addCell(2600, ['gridSpan' => 2])->addText($values['h2017'] ?? '');
        $table->addCell(1500)->addText('专业技术职务');
        $table->addCell(2600, ['gridSpan' => 2])->addText($values['h2009'] ?? '');

        $table->addRow();
        $table->addCell(1500)->addText('个人总结');
        $summaryCell = $table->addCell(6700, ['gridSpan' => 5]);
        if (trim(strip_tags($summary)) !== '') {
            Html::addHtml($summaryCell, $summary, false, false);
        } else {
            $summaryCell->addText('');
        }

        $table->addRow();
        $table->addCell(1500)->addText('部门考核意见');
        $departmentCell = $table->addCell(6700, ['gridSpan' => 5]);
        $departmentCell->addText('经研究决定，该同志考核结果为：'.($values['h2013'] ?? ''));
        $departmentCell->addText('（盖章）                 年     月     日', [], ['alignment' => 'right', 'spaceBefore' => 300]);

        $table->addRow();
        $table->addCell(1500)->addText('审批意见');
        $schoolCell = $table->addCell(6700, ['gridSpan' => 5]);
        $schoolCell->addText('经研究决定，该同志考核结果为：'.($values['h2014'] ?? ''));
        $schoolCell->addText('（盖章）                 年     月     日', [], ['alignment' => 'right', 'spaceBefore' => 300]);

        $table->addRow();
        $table->addCell(1500)->addText('个人确认');
        $confirmCell = $table->addCell(6700, ['gridSpan' => 5]);
        $confirmCell->addText('考核结果反馈情况：'.($values['h2014'] ?? ''));
        $confirmCell->addText('本人同意上述考核结果。');
        $confirmCell->addText('本人签字：                         年     月     日', [], ['spaceBefore' => 300]);

        $section->addText('注：此表用A4纸正反面打印。', ['size' => 9], ['spaceBefore' => 120]);
        $path = tempnam(sys_get_temp_dir(), 'assessment-');
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return $path;
    }

    /** @param array{string, string, string, string, string, string} $cells */
    private function triple(Table $table, array $cells): void
    {
        $table->addRow();
        foreach ($cells as $index => $value) {
            $table->addCell($index % 2 === 0 ? 1250 : 1480)->addText($value);
        }
    }
}
