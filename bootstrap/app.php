<?php

use App\Http\Middleware\EmbeddedAppHeaders;
use App\Http\Middleware\EnsureOperator;
use App\Http\Middleware\VerifyAppProxy;
use App\Http\Middleware\VerifyPosRequest;
use App\Http\Middleware\VerifySessionToken;
use App\Http\Middleware\VerifyWebhookHmac;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'shopify.session' => VerifySessionToken::class,
            'shopify.proxy' => VerifyAppProxy::class,
            'shopify.pos' => VerifyPosRequest::class,
            'shopify.webhook' => VerifyWebhookHmac::class,
            'shopify.embedded' => EmbeddedAppHeaders::class,
            'operator' => EnsureOperator::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('op', 'op/*')
            ? route('op.login')
            : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
