<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProfileCorrectionCatalog
{
    /** @return array<string, array{label:string, section:string, evidenceRequired:bool, isDate:bool, dictionary:bool}> */
    public function fields(): array
    {
        $fields = [];
        foreach (config('profile_corrections.fields') as $section => $keys) {
            foreach ($keys as $key) {
                $fields[$key] = [
                    'label' => config("personnel_profile.sections.$section.fields.$key"),
                    'section' => $section,
                    'evidenceRequired' => in_array($key, config('profile_corrections.evidence_required'), true),
                    'isDate' => in_array($key, config('profile_corrections.dates'), true),
                    'dictionary' => isset(config('profile_corrections.dictionaries')[$key]),
                ];
            }
        }

        return $fields;
    }

    /** @return array<string, array<int, array{value:string,label:string}>> */
    public function options(): array
    {
        $options = [];
        foreach (config('profile_corrections.dictionaries') as $field => $definition) {
            $query = DB::connection('legacy')->table($definition['table']);
            if (isset($definition['type'])) {
                $query->where('type', $definition['type']);
            }
            $values = [];
            foreach ($query->get([$definition['code'], $definition['label']]) as $row) {
                $code = trim((string) $row->{$definition['code']});
                $label = trim((string) $row->{$definition['label']});
                if ($code === '' || $label === '') {
                    continue;
                }
                $value = ($definition['format'] ?? '') === 'id_label' ? "$code)$label" : $code;
                $values[$value] = ['value' => $value, 'label' => $label];
            }
            $options[$field] = array_values($values);
        }

        return $options;
    }

    /** @param array<string, array<int, array{value:string,label:string}>> $options */
    public function valid(string $field, ?string $value, array $options): bool
    {
        if ($value === null) {
            return true;
        }
        if (! isset(config('profile_corrections.dictionaries')[$field])) {
            return true;
        }

        return in_array($value, array_column($options[$field] ?? [], 'value'), true);
    }
}
