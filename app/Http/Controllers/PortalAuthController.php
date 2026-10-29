<?php

namespace App\Http\Controllers;

use App\Auth\CasIdentityResolver;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Lovevisss\LaravelZufedfcCas\CasClient;
use Lovevisss\LaravelZufedfcCas\Exceptions\CasUnavailableException;
use Lovevisss\LaravelZufedfcCas\Exceptions\InvalidTicketException;
use Lovevisss\LaravelZufedfcCas\SessionRegistry;

class PortalAuthController extends Controller
{
    public function login(Request $request, CasClient $cas): RedirectResponse|Response
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (! config('cas.service_url')) {
            return Inertia::render('Personnel/Login', [
                'previewUrl' => $this->canPreview($request)
                    ? URL::temporarySignedRoute('preview.login', now()->addMinutes(5), absolute: false)
                    : null,
            ]);
        }

        return redirect()->away($cas->loginUrl());
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

    public function callback(Request $request, CasClient $cas, CasIdentityResolver $resolver, SessionRegistry $sessions): RedirectResponse
    {
        $ticket = $request->query('ticket');
        abort_unless(is_string($ticket) && Str::length($ticket) <= 1024 && preg_match('/^ST-[A-Za-z0-9._-]+$/', $ticket), 400);

        abort_unless(config('cas.service_url'), 503, '信息门户单点登录尚未配置。');

        try {
            $identity = $cas->validateTicket($ticket);
        } catch (CasUnavailableException) {
            abort(502, '信息门户认证服务不可用。');
        } catch (InvalidTicketException) {
            abort(403, '信息门户认证失败。');
        }

        $user = $resolver->resolve($identity);
        abort_unless($user !== null, 403, '该门户账号没有可用的人事系统身份。');

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('personnel_preview');
        $sessions->remember($ticket, $request->session()->getId());

        return redirect()->route('dashboard');
    }

    public function logout(Request $request, CasClient $cas): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return config('cas.service_url') ? redirect()->away($cas->logoutUrl()) : redirect()->route('home');
    }

    private function canPreview(Request $request): bool
    {
        return app()->environment('local')
            && (bool) config('personnel.local_preview')
            && filled(config('personnel.preview_user_id'))
            && in_array($request->ip(), ['127.0.0.1', '::1'], true);
    }
}
