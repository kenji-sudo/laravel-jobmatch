<?php

return [
    'enabled' => env('MATCHING_AI_ENABLED', false),
    'ollama_url' => env('MATCHING_OLLAMA_URL', 'http://127.0.0.1:11434'),
    'model' => env('MATCHING_EMBEDDING_MODEL', 'all-minilm:22m-l6-v2-fp16'),
    'timeout' => 30,
    'candidate_limit' => 50,
    'text_limit' => 1600,
];
