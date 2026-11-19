<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Failed attempts allowed per username + IP, and per IP alone, within DECAY_SECONDS. */
    private const MAX_ATTEMPTS_PER_USER = 5;

    private const MAX_ATTEMPTS_PER_IP = 20;

    private const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $userKey = 'admin-login|'.Str::lower($credentials['username']).'|'.$request->ip();
        $ipKey = 'admin-login-ip|'.$request->ip();

        $this->ensureNotThrottled($userKey, self::MAX_ATTEMPTS_PER_USER);
        $this->ensureNotThrottled($ipKey, self::MAX_ATTEMPTS_PER_IP);

        if (! Auth::guard('admin')->attempt($credentials)) {
            RateLimiter::hit($userKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::DECAY_SECONDS);

            // The same message for an unknown username and a wrong password.
            throw ValidationException::withMessages(['username' => 'Username atau password salah.']);
        }

        RateLimiter::clear($userKey);

        // A fresh session id after login prevents session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('leaderboard');
    }

    private function ensureNotThrottled(string $key, int $maxAttempts): void
    {
        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'username' => "Terlalu banyak percobaan login. Coba lagi dalam {$seconds} detik.",
        ]);
    }
}
