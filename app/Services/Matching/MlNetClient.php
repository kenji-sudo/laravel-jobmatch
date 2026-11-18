<?php

declare(strict_types=1);

namespace App\Services\Matching;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MlNetClient
{
    private function request(): PendingRequest
    {
        $url = rtrim((string) config('matching.mlnet_url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
            || ! in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || strlen((string) config('matching.integration_key')) < 32) {
            throw new RuntimeException('ML.NET integration is not configured.');
        }
        return Http::baseUrl($url)->acceptJson()->asJson()->withToken(config('matching.integration_key'))
            ->connectTimeout(2)->timeout(20)->withOptions(['allow_redirects' => false, 'proxy' => '']);
    }

    public function health(): bool
    {
        $response = $this->request()->get('/health');
        return $response->successful() && $response->json('schemaVersion') === 'cole-features-v1'
            && $response->json('modelReady') === true;
    }

    /** @param array<int, array{jobId:string, features:array}> $candidates */
    public function rank(array $candidates): array
    {
        $response = $this->request()->post('/v1/rank', ['candidates' => $candidates]);
        if (! $response->successful() || strlen($response->body()) > 1000000
            || $response->json('schemaVersion') !== 'cole-features-v1'
            || ! is_string($response->json('modelVersion')) || ! preg_match('/^[a-f0-9]{64}$/', $response->json('modelVersion'))) {
            throw new RuntimeException('Prediction service unavailable.');
        }
        $rows = $response->json('candidates');
        $expected = array_column($candidates, 'jobId');
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($expected)) {
            throw new RuntimeException('Invalid prediction response.');
        }
        $seen = [];
        foreach ($rows as $row) {
            $id = $row['jobId'] ?? null;
            $score = $row['score'] ?? null;
            $base = $row['baseScore'] ?? null;
            $contributions = $row['contributions'] ?? null;
            if (! is_string($id) || ! in_array($id, $expected, true) || in_array($id, $seen, true)
                || ! $this->finite($score) || $score < 0 || $score > 100
                || ! $this->finite($base) || $base < 0 || $base > 100
                || ! is_array($contributions) || count($contributions) !== 5) {
                throw new RuntimeException('Invalid prediction values.');
            }
            $features = [];
            $sum = 0.0;
            foreach ($contributions as $contribution) {
                $name = $contribution['feature'] ?? null;
                $value = $contribution['value'] ?? null;
                if (! in_array($name, ['RequiredSkillRatio', 'GwaScore', 'ProficiencyDepth', 'CertificationCount', 'InternshipCount'], true)
                    || in_array($name, $features, true) || ! $this->finite($value) || abs($value) > 100) {
                    throw new RuntimeException('Invalid explanation values.');
                }
                $features[] = $name;
                $sum += $value;
            }
            if (abs($score - $base - $sum) > 0.01) {
                throw new RuntimeException('Explanation does not reconcile with the score.');
            }
            $seen[] = $id;
        }
        return ['model' => $response->json('modelVersion'), 'rows' => $rows];
    }

    private function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }
}
