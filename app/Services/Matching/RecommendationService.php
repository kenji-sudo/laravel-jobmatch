<?php

namespace App\Services\Matching;

use App\Models\Student;

final class RecommendationService
{
    public function __construct(private MlNetJobMatcher $mlnet, private SemanticJobMatcher $legacy) {}

    public function recommend(Student $student, string $summary, bool $useAi): array
    {
        return config('matching.driver') === 'ollama'
            ? $this->legacy->recommend($student, $summary, $useAi)
            : $this->mlnet->recommend($student, $useAi);
    }
}
