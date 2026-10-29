<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LegacyHistory
{
    /** @return array{records:array<int, array<string,mixed>>,total:int,page:int,lastPage:int} */
    public function page(User $user, string $tab, int $page): array
    {
        $config = config("personnel_history.$tab");
        $fields = array_keys(array_merge(...array_values($config['groups'])));
        $rows = DB::connection('legacy')->table($config['table'])
            ->where($config['employee'], $user->username)->where('yndelete', '0')
            ->get(['Id', 'updateTime', ...$fields])->all();
        $labels = $this->dictionaryLabels($rows, $config['dictionaries']);
        $items = array_map(function (object $row) use ($config, $fields, $labels): array {
            $raw = (array) $row;
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $this->display($raw[$field] ?? null, $config['dictionaries'][$field] ?? null, $labels);
            }

            $date = null;
            foreach ($config['dates'] as $field) {
                $date = $this->date($raw[$field] ?? null);
                if ($date !== null) {
                    break;
                }
            }
            $date ??= $this->date($raw['updateTime'] ?? null);

            return ['id' => (int) $row->Id, 'date' => $date, 'values' => $values, 'raw' => $raw];
        }, $rows);

        usort($items, fn (array $a, array $b) => strcmp($b['date'] ?? '', $a['date'] ?? '') ?: $b['id'] <=> $a['id']);

        $total = count($items);
        $lastPage = max(1, (int) ceil($total / 20));
        abort_if($page < 1 || $page > $lastPage, 404);
        $records = [];
        foreach (array_slice($items, ($page - 1) * 20, 20, true) as $index => $item) {
            $older = $items[$index + 1] ?? null;
            $groups = [];
            $changedCount = 0;
            foreach ($config['groups'] as $group => $groupFields) {
                $groupItems = [];
                foreach ($groupFields as $field => $label) {
                    $changed = $older !== null && trim((string) ($item['raw'][$field] ?? '')) !== trim((string) ($older['raw'][$field] ?? ''));
                    $changedCount += (int) $changed;
                    $certificate = in_array($field, $config['certificates'], true);
                    $groupItems[] = [
                        'key' => $field, 'label' => $label,
                        'value' => $certificate ? null : $item['values'][$field],
                        'changed' => $changed,
                        'certificate' => $certificate,
                        'hasReference' => $certificate && trim((string) ($item['raw'][$field] ?? '')) !== '',
                    ];
                }
                $groups[] = ['title' => $group, 'fields' => $groupItems];
            }
            $summary = array_values(array_unique(array_filter(array_map(fn (string $field) => $item['values'][$field] ?? null, $config['summary']), fn ($v) => $v !== null)));
            $records[] = [
                'id' => $item['id'], 'date' => $item['date'], 'summary' => $summary,
                'changedCount' => $changedCount, 'baseline' => $older === null, 'groups' => $groups,
            ];
        }

        return compact('records', 'total', 'page', 'lastPage');
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $match)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);

        return $date && $date->format('Y-m-d') === $match[1] ? $match[1] : null;
    }

    /** @param array<int,object> $rows
     * @param  array<string,array{0:string,1:string}>  $dictionaries
     * @return array<string,array<string,string>>
     */
    private function dictionaryLabels(array $rows, array $dictionaries): array
    {
        $codes = [];
        foreach ($rows as $row) {
            foreach ($dictionaries as $field => [$table]) {
                $value = trim((string) ($row->{$field} ?? ''));
                if ($value !== '' && ! preg_match('/^\d+\).+$/u', $value)) {
                    $codes[$table][] = $value;
                }
            }
        }
        $labels = [];
        foreach ($codes as $table => $tableCodes) {
            $column = str_starts_with($table, 'ud') ? 'name' : 'objjc';
            foreach (DB::connection('legacy')->table($table)->whereIn('objname', array_unique($tableCodes))->get(['objname', $column]) as $row) {
                $labels[$table][(string) $row->objname] = trim((string) $row->{$column});
            }
        }

        return $labels;
    }

    /** @param array{0:string,1:string}|null $dictionary
     * @param  array<string,array<string,string>>  $labels
     */
    private function display(mixed $raw, ?array $dictionary, array $labels): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+\)(.+)$/u', $value, $match)) {
            return trim($match[1]);
        }

        return $dictionary ? ($labels[$dictionary[0]][$value] ?? $value) : $value;
    }
}
