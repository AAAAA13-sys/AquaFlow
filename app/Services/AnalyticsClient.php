<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnalyticsClient
{
    // Service Configuration
    public function baseUrl(): string
    {
        return (string) config('analytics.url');
    }

    // High-Level Operations
    /**
     * @return array<string,mixed>
     */
    public function run(int $days, int $horizon): array
    {
        return $this->request('post', '/run', [
            'days' => $days,
            'horizon' => $horizon,
        ], (int) config('analytics.timeout'));
    }

    /**
     * @return array<string,mixed>
     */
    public function health(): array
    {
        return $this->request('get', '/health', [], 10);
    }

    /**
     * @param  list<float|int>  $values
     * @return array<string,mixed>
     */
    public function forecast(array $values, int $horizon, ?int $endWeekday = null): array
    {
        return $this->request('post', '/forecast', [
            'values' => $values,
            'horizon' => $horizon,
            'end_weekday' => $endWeekday,
        ], (int) config('analytics.timeout'));
    }

    // HTTP Transport
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload, int $timeout): array
    {
        $url = $this->baseUrl() . $path;

        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('analytics.connect_timeout'))
                ->timeout($timeout)
                ->{$method}($url, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Cannot reach the analytics service at ' . $this->baseUrl() . '. Is it running?',
                0,
                $exception
            );
        }

        if ($response->failed()) {
            $message = $response->json('error')
                ?? $response->json('message')
                ?? ('Analytics service returned HTTP ' . $response->status());

            throw new RuntimeException(is_string($message) ? $message : 'Analytics request failed.');
        }

        return (array) $response->json();
    }
}
