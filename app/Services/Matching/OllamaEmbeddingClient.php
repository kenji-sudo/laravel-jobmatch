<?php

declare(strict_types=1);

namespace App\Services\Matching;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class OllamaEmbeddingClient
{
    /** @param list<string> $texts
     *  @return list<list<float>> Normalized, dimension-checked vectors.
     */
    public function embed(array $texts): array
    {
        $url = rtrim((string) config('matching.ollama_url'), '/');
        $parts = parse_url($url);
        // User text is never sent to an arbitrary or hosted provider URL.
        if (! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http'
            || ! in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Embedding endpoint must use a loopback HTTP address.');
        }

        $response = Http::acceptJson()->asJson()
            ->connectTimeout(2)
            ->timeout(max(1, min(60, (int) config('matching.timeout', 30))))
            ->withOptions(['allow_redirects' => false, 'proxy' => ''])
            ->post($url.'/api/embed', [
                'model' => config('matching.model'),
                'input' => $texts,
                'truncate' => false,
            ]);

        if (! $response->successful() || strlen($response->body()) > 3000000) {
            throw new RuntimeException('Embedding service is unavailable.');
        }
        $vectors = $response->json('embeddings');
        if (! is_array($vectors) || ! array_is_list($vectors) || count($vectors) !== count($texts)) {
            throw new RuntimeException('Invalid embedding response.');
        }

        $dimensions = null;
        return array_map(function ($vector) use (&$dimensions): array {
            if (! is_array($vector) || ! array_is_list($vector)
                || count($vector) < 2 || count($vector) > 4096) {
                throw new RuntimeException('Invalid embedding vector.');
            }
            $dimensions ??= count($vector);
            if (count($vector) !== $dimensions) {
                throw new RuntimeException('Embedding dimensions do not match.');
            }
            $sum = 0.0;
            foreach ($vector as $value) {
                if ((! is_int($value) && ! is_float($value))
                    || ! is_finite((float) $value) || abs($value) > 1000000) {
                    throw new RuntimeException('Invalid embedding value.');
                }
                $sum += $value * $value;
            }
            if ($sum <= 0) {
                throw new RuntimeException('Embedding vector is empty.');
            }
            $norm = sqrt($sum);
            return array_map(fn ($value): float => $value / $norm, $vector);
        }, $vectors);
    }
}
