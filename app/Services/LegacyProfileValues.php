<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LegacyProfileValues
{
    private const DICTIONARIES = [
        'h5003' => ['department', 'name'], 'h5009' => ['d202', 'objjc'],
        'h5011' => ['d204', 'objjc'], 'h5012' => ['d210', 'objjc'],
        'h5017' => ['d483', 'objjc'], 'h5019' => ['d306', 'objjc'],
        'h5020' => ['d402', 'objjc'], 'h5022' => ['gz_yesno', 'name'],
        'h5026' => ['gz_yesno', 'name'], 'h5036' => ['d375', 'objjc'],
        'h5037' => ['d309', 'objjc'], 'h5038' => ['d218', 'objjc'],
        'h5039' => ['d952', 'objjc'], 'h5040' => ['d218', 'objjc'],
        'h5043' => ['d361', 'objjc'], 'h5047' => ['d230', 'objjc'],
        'h5052' => ['d203', 'objjc'], 'h5053' => ['d952', 'objjc'],
        'h5065' => ['d380', 'objjc'], 'h5067' => ['ud06', 'name'],
        'h5071' => ['d307', 'objjc'], 'h5072' => ['d307', 'objjc'],
        'h5077' => ['gz_yesno', 'name'], 'h5078' => ['gz_yesno', 'name'],
        'h5080' => ['ud16', 'name'], 'h5082' => ['d202', 'objjc'],
        'h5092' => ['d218', 'objjc'], 'h5093' => ['d952', 'objjc'],
        'h5094' => ['d306', 'objjc'], 'h5014' => ['d230', 'objjc'],
    ];

    /** @param list<string> $fields
     * @return array<string, string|null>
     */
    public function resolve(object $record, array $fields): array
    {
        $raw = (array) $record;
        $codesByTable = [];
        foreach ($fields as $field) {
            $value = trim((string) ($raw[$field] ?? ''));
            if ($value !== '' && isset(self::DICTIONARIES[$field]) && ! preg_match('/^\d+\).+$/u', $value)) {
                $codesByTable[self::DICTIONARIES[$field][0]][] = $value;
            }
        }

        $labels = [];
        foreach ($codesByTable as $table => $codes) {
            $labelColumn = match ($table) {
                'department', 'gz_yesno', 'ud06', 'ud16' => 'name',
                default => 'objjc',
            };
            $rows = DB::connection('legacy')->table($table)
                ->whereIn('objname', array_values(array_unique($codes)))
                ->get(['objname', $labelColumn]);
            foreach ($rows as $row) {
                $labels[$table][(string) $row->objname] = trim((string) $row->{$labelColumn});
            }
        }

        $resolved = [];
        foreach ($fields as $field) {
            $value = trim((string) ($raw[$field] ?? ''));
            if ($value === '') {
                $resolved[$field] = null;
            } elseif (preg_match('/^\d+\)(.+)$/u', $value, $matches)) {
                $resolved[$field] = trim($matches[1]);
            } elseif (isset(self::DICTIONARIES[$field])) {
                $table = self::DICTIONARIES[$field][0];
                $resolved[$field] = $labels[$table][$value] ?? $value;
            } else {
                $resolved[$field] = $value;
            }
        }

        return $resolved;
    }
}
