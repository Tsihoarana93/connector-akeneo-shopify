<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        $this->withoutVite();
    }

    public function test_pages_forbid_framing_and_sniffing_and_only_allow_the_apps_own_scripts(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.bunny.net; font-src 'self' https://fonts.bunny.net; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    }

    public function test_error_responses_get_the_headers_too(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_debug_mode_allows_inline_scripts_for_the_exception_page(): void
    {
        config(['app.debug' => true]);

        $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval';", $policy);
    }

    public function test_the_vite_dev_server_is_allowed_while_it_runs(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot');
        file_put_contents($hotFile, "http://localhost:5173\n");
        $this->withVite();
        Vite::useHotFile($hotFile);

        $policy = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' http://localhost:5173 ws://localhost:5173;", $policy);
        $this->assertStringContainsString("connect-src 'self' http://localhost:5173 ws://localhost:5173;", $policy);

        unlink($hotFile);
    }

    public function test_https_responses_enable_hsts(): void
    {
        $this->get(str_replace('http://', 'https://', route('login')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_plain_http_responses_do_not_send_hsts(): void
    {
        $this->get(str_replace('https://', 'http://', route('login')))
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
