<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers for every response: no framing (clickjacking), no MIME sniffing, a strict referrer policy,
 * HSTS over HTTPS, and a Content Security Policy that only allows this app's own scripts.
 */
class SecurityHeaders
{
    /**
     * Where the layout loads its web fonts from (stylesheet and font files).
     */
    public const FONT_ORIGIN = 'https://fonts.bunny.net';

    private const HSTS_MAX_AGE = 31536000;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.self::HSTS_MAX_AGE);
        }

        return $response;
    }

    /**
     * Scripts are limited to this app. Inline styles stay allowed, because Laravel's own error pages use them.
     * In debug mode, inline scripts are allowed for the exception page and Boost; while `npm run dev` runs,
     * the Vite dev server is allowed too.
     */
    private function contentSecurityPolicy(): string
    {
        $viteOrigins = $this->viteDevServerOrigins();
        $debugScripts = config('app.debug') ? ["'unsafe-inline'", "'unsafe-eval'"] : [];

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", ...$debugScripts, ...$viteOrigins],
            'style-src' => ["'self'", "'unsafe-inline'", self::FONT_ORIGIN, ...$viteOrigins],
            'font-src' => ["'self'", self::FONT_ORIGIN, ...$viteOrigins],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", ...$viteOrigins],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $directive): string => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /**
     * The Vite dev server's HTTP and WebSocket origins while `npm run dev` runs, for hot reloading.
     *
     * @return list<string>
     */
    private function viteDevServerOrigins(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }

        $origin = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');

        return [$origin, (string) preg_replace('#^http#', 'ws', $origin)];
    }
}
