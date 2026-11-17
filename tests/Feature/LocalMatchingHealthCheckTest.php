<?php

use Illuminate\Support\Facades\Http;

test('matching health check uses synthetic text and reports successful vectors', function () {
    Http::preventStrayRequests();
    Http::fake(['127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[1, 0], [1, 1]]])]);
    $this->artisan('matching:check')->expectsOutputToContain('valid vectors')->assertExitCode(0);
});

test('matching health check returns failure for a missing model', function () {
    Http::preventStrayRequests();
    Http::fake(['127.0.0.1:11434/api/embed' => Http::response([], 404)]);
    $this->artisan('matching:check')->expectsOutputToContain('check failed')->assertExitCode(1);
});
