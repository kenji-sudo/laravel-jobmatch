<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Services\Matching\OllamaEmbeddingClient;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('matching:check', function (OllamaEmbeddingClient $client) {
    try {
        if (config('matching.driver') !== 'ollama') {
            if (! app(\App\Services\Matching\MlNetClient::class)->health()) {
                $this->error('ML.NET is unavailable or a trained model has not been loaded.');
                return 1;
            }
            $this->info('ML.NET is ready with a trained model. This does not measure relevance accuracy.');
            return 0;
        }
        $vectors = $client->embed(['PHP software development', 'Build PHP applications']);
        $this->info('Local embedding service responded with valid vectors.');
        $this->line('Model: '.config('matching.model'));
        $this->line('Dimensions: '.count($vectors[0]));
        $this->warn('This is a connectivity check, not a job-matching accuracy measurement.');
        return 0;
    } catch (\Illuminate\Http\Client\ConnectionException|\RuntimeException $error) {
        $this->error('Local embedding check failed. Start Ollama and install the configured model.');
        return 1;
    }
})->purpose('Check local embeddings using synthetic text only');
