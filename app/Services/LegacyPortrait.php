<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class LegacyPortrait
{
    public function hasReference(User $user): bool
    {
        return $this->url($user) !== null;
    }

    /** @return array{content:string, mime:string}|null */
    public function fetch(User $user): ?array
    {
        $url = $this->url($user);
        if ($url === null) {
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
            $mime = $image['mime'] ?? null;

            return in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)
                ? ['content' => $content, 'mime' => $mime]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function url(User $user): ?string
    {
        $references = DB::connection('legacy')->table('h55')
            ->where('h5503', $user->username)
            ->where('yndelete', '0')
            ->orderByDesc('Id')
            ->limit(10)
            ->pluck('h5504');

        foreach ($references as $reference) {
            if (! preg_match('~https?://[^\s]+$~u', (string) $reference, $match)) {
                continue;
            }
            $url = $match[0];
            $parts = parse_url($url);
            $path = rawurldecode($parts['path'] ?? '');
            if (($parts['scheme'] ?? '') !== 'http'
                || ($parts['host'] ?? '') !== 'rsgl.zufedfc.edu.cn'
                || ($parts['port'] ?? null) !== 8080
                || ! preg_match('~^/+useruploadfiles/[^/\\\\]+\.(?:jpe?g|png|gif)$~iu', $path)
                || str_contains($path, '..')) {
                continue;
            }

            return $url;
        }

        return null;
    }
}
