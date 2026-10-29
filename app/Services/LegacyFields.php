<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LegacyFields
{
    /** @param list<string> $columns
     * @return list<array{key:string,label:string}>
     */
    public function labels(string $table, array $columns): array
    {
        $names = DB::connection('legacy')->table('gzsys')
            ->where('tableName', $table)
            ->whereIn('FieldName', $columns)
            ->orderBy('Sequence')
            ->get(['FieldName', 'FieldShowName'])
            ->unique(fn ($row) => strtolower($row->FieldName))
            ->keyBy(fn ($row) => strtolower($row->FieldName));

        return array_map(fn (string $column) => [
            'key' => $column,
            'label' => trim((string) ($names[strtolower($column)]->FieldShowName ?? $column)),
        ], $columns);
    }
}
