<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigratedLegacyFiles
{
    /** @return list<string> */
    public function references(string $value): array
    {
        $value = str_replace(["\\'", '\\"', '\\/'], ["'", '"', '/'], $value);
        preg_match_all('~https?://[^\s"\'<>]+|(?:/+|\b)useruploadfiles[/\\\\][^\s"\'<>]+~u', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);

        return array_values(array_filter(array_map(function (string $reference): ?string {
            if (! str_starts_with($reference, 'http')) {
                $reference = config('legacy_files.source_url').'/'.ltrim(str_replace('\\', '/', $reference), '/');
            }

            return $this->canonicalUrl($reference);
        }, $matches[0])));
    }

    public function canonicalUrl(mixed $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }
        $reference = trim($reference);
        if (preg_match('~^/*useruploadfiles[/\\\\]~', $reference)) {
            $reference = config('legacy_files.source_url').'/'.ltrim(str_replace('\\', '/', $reference), '/');
        }
        if (! preg_match('~https?://.+$~us', $reference, $matches)) {
            return null;
        }
        $parts = parse_url(trim($matches[0]));
        if ($parts === false || ! in_array(strtolower($parts['host'] ?? ''), config('legacy_files.aliases'), true)
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = rawurldecode($parts['path'] ?? '');
        $segments = explode('/', ltrim($path, '/'));
        if ($segments[0] !== 'useruploadfiles' || count($segments) < 2
            || in_array('', $segments, true) || str_contains($path, '..') || str_contains($path, '\\')
            || preg_match('~%2f|%5c~i', $parts['path'] ?? '') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return config('legacy_files.source_url').'/'.implode('/', array_map(rawurlencode(...), $segments));
    }

    /** @return array{content:string,mime:string}|null */
    public function fetch(mixed $reference): ?array
    {
        if (! config('legacy_files.enabled')) {
            return null;
        }
        $url = $this->canonicalUrl($reference);
        if ($url === null && is_string($reference)) {
            $url = $this->references($reference)[0] ?? null;
        }
        if ($url === null) {
            return null;
        }
        $file = DB::table('legacy_file_mappings')->where('source_key', hash('sha256', $url))->where('status', 'copied')->first();
        if ($file === null || ! Storage::disk($file->disk)->exists($file->path)) {
            return null;
        }
        $content = Storage::disk($file->disk)->get($file->path);
        if (strlen($content) !== (int) $file->size || ! hash_equals($file->sha256, hash('sha256', $content))) {
            return null;
        }

        return ['content' => $content, 'mime' => $file->mime];
    }
}
