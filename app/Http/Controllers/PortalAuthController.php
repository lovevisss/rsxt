<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PortalAuthController extends Controller
{
    public function login(Request $request): RedirectResponse|Response
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $loginUrl = config('personnel.cas.login_url');
        if (! $loginUrl || ! config('personnel.cas.validate_url')) {
            return Inertia::render('Personnel/Login', [
                'previewUrl' => $this->canPreview($request)
                    ? URL::temporarySignedRoute('preview.login', now()->addMinutes(5), absolute: false)
                    : null,
            ]);
        }

        $service = $this->serviceUrl();

        return redirect()->away($loginUrl.(str_contains($loginUrl, '?') ? '&' : '?').http_build_query(['service' => $service]));
    }

    public function preview(Request $request): RedirectResponse
    {
        abort_unless($this->canPreview($request), 404);

        $user = User::query()->whereKey(config('personnel.preview_user_id'))->where('isUse', 1)->first();
        abort_unless($user !== null, 404);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('personnel_preview', true);

        return redirect()->route('dashboard');
    }

    public function callback(Request $request): RedirectResponse
    {
        $ticket = $request->query('ticket');
        abort_unless(is_string($ticket) && Str::length($ticket) <= 1024 && preg_match('/^ST-[A-Za-z0-9._-]+$/', $ticket), 400);

        $validateUrl = config('personnel.cas.validate_url');
        abort_unless($validateUrl, 503, '信息门户单点登录尚未配置。');

        $response = Http::timeout(8)->get($validateUrl, [
            'service' => $this->serviceUrl(),
            'ticket' => $ticket,
        ]);

        abort_unless($response->successful() && strlen($response->body()) < 65536, 502, '信息门户认证服务不可用。');

        $username = $this->validatedPrincipal($response->body());
        abort_unless($username !== null, 403, '信息门户认证失败。');

        $user = User::query()->where('username', $username)->where('isUse', 1)->first();
        abort_unless($user !== null, 403, '该门户账号没有可用的人事系统身份。');

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('personnel_preview');

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $logoutUrl = config('personnel.cas.logout_url');

        return $logoutUrl ? redirect()->away($logoutUrl) : redirect()->route('home');
    }

    private function serviceUrl(): string
    {
        return config('personnel.cas.service_url') ?: route('sso.callback');
    }

    private function canPreview(Request $request): bool
    {
        return app()->environment('local')
            && (bool) config('personnel.local_preview')
            && filled(config('personnel.preview_user_id'))
            && in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }

    private function validatedPrincipal(string $xml): ?string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
            if ($document === false) {
                return null;
            }

            $matches = $document->xpath('//*[local-name()="authenticationSuccess"]/*[local-name()="user"]');
            $principal = trim((string) ($matches[0] ?? ''));

            return $principal !== '' && strlen($principal) <= 100 ? $principal : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
