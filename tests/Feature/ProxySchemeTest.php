<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProxySchemeTest extends TestCase
{
    public function test_https_proxy_generates_https_assets_and_secure_session_cookies(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('/');
        $response->assertOk()->assertSee('https://localhost/vendor/chartjs/chart.umd.js', false);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_lan_http_remains_usable_without_https(): void
    {
        $this->get('/')->assertOk()->assertSee('http://localhost/vendor/chartjs/chart.umd.js', false);
    }
}
