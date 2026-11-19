<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The only login on the site is the admin one: send visitors there, and send an
        // already logged-in admin who opens the login page on to the dashboard.
        $middleware->redirectGuestsTo(fn () => route('admin.login'));
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));

        // Behind a tunnel or reverse proxy (VS Code port forwarding, ngrok, Nginx...) the browser talks
        // https to the proxy while PHP only sees plain http. Without this, every generated URL (CSS, JS,
        // links, form actions) is "http://..." and the browser blocks it as mixed content, so the page
        // shows up without styling. Trust the forwarded scheme, host and port so URLs match what the
        // visitor typed.
        //
        // X-Forwarded-For is deliberately NOT trusted: anyone could send a fake one, and the admin
        // login throttle is keyed on the client IP.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
