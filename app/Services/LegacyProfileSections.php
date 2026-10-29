<?php

namespace App\Services;

use RuntimeException;

class LegacyProfileSections
{
    /** @return array<string, array{title:string, description:string, fields:array<string,string>}> */
    public function all(): array
    {
        $configured = config('personnel_profile.sections');
        if (! is_array($configured)) {
            throw new RuntimeException('Profile sections are not configured.');
        }

        $sections = [];
        foreach ($configured as $key => $section) {
            if (! is_string($key) || ! is_array($section)
                || ! is_string($section['title'] ?? null)
                || ! is_string($section['description'] ?? null)
                || ! is_array($section['fields'] ?? null)) {
                throw new RuntimeException('Invalid profile section configuration.');
            }
            $fields = [];
            foreach ($section['fields'] as $field => $label) {
                if (! is_string($field) || ! is_string($label)) {
                    throw new RuntimeException('Invalid profile field configuration.');
                }
                $fields[$field] = $label;
            }
            $sections[$key] = [
                'title' => $section['title'],
                'description' => $section['description'],
                'fields' => $fields,
            ];
        }

        return $sections;
    }
}
