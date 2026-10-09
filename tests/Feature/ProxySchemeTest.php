<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProxySchemeTest extends TestCase
{
    public function test_https_proxy_generates_https_assets_and_secure_session_cookies(): void
    {
        // Nginx receives HTTP from the tunnel and supplies the original scheme.
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://store.example.test/');
        $response->assertOk()->assertSee('https://store.example.test/vendor/chartjs/chart.umd.js', false);
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
    }

    public function test_lan_http_remains_usable_without_https(): void
    {
        $this->get('http://192.168.1.25:8080/')->assertOk()->assertSee('http://192.168.1.25:8080/vendor/chartjs/chart.umd.js', false);
    }
}
