<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LegacyCertificate
{
    /** @return array{content:string,mime:string}|null */
    public function fetch(mixed $reference): ?array
    {
        $local = app(MigratedLegacyFiles::class)->fetch($reference);
        if ($local !== null) {
            $image = @getimagesizefromstring($local['content']);

            return in_array($image['mime'] ?? null, ['image/jpeg', 'image/png', 'image/gif'], true) ? $local : null;
        }
        if (config('legacy_files.enabled') && ! config('legacy_files.remote_fallback')) {
            return null;
        }
        if (! preg_match('~https?://[^\s]+$~u', trim((string) $reference), $match)) {
            return null;
        }
        $url = $match[0];
        $parts = parse_url($url);
        $path = rawurldecode($parts['path'] ?? '');
        if (($parts['scheme'] ?? '') !== 'http'
            || ($parts['host'] ?? '') !== 'rsgl.zufedfc.edu.cn'
            || ($parts['port'] ?? null) !== 8080
            || isset($parts['query']) || isset($parts['fragment'])
            || ! preg_match('~^/+useruploadfiles/[^/\\\\]+\.(?:jpe?g|png|gif)$~iu', $path)
            || str_contains($path, '..')) {
            return null;
        }
        try {
            $response = Http::timeout(8)->withOptions(['allow_redirects' => false])->get($url);
            if (! $response->successful()) {
                return null;
            }
            $content = $response->body();
            if (strlen($content) > 3 * 1024 * 1024) {
                return null;
            }
            $image = @getimagesizefromstring($content);
            $mime = $image === false ? null : $image['mime'];

            return in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)
                ? ['content' => $content, 'mime' => $mime] : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
