<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Models\Student;
use App\Services\MatchService;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

final class SemanticJobMatcher
{
    public function __construct(
        private readonly MatchService $baseline,
        private readonly OllamaEmbeddingClient $embeddings,
    ) {}

    /** The original score remains skill coverage; semantic similarity is a separate value. */
    public function recommend(Student $student, string $summary = '', bool $useAi = false): array
    {
        $ranked = $this->baseline->rankJobsForStudent($student);
        $limit = max(1, min(100, (int) config('matching.candidate_limit', 50)));
        $items = $ranked->take($limit)->values();
        $result = [
            'items' => $items,
            'mode' => 'baseline',
            'reason' => 'not_requested',
            'candidate_count' => $items->count(),
            'eligible_count' => $ranked->count(),
            'shortened' => false,
            'model' => null,
        ];
        if (! $useAi || $items->isEmpty()) {
            return $result;
        }
        if (! config('matching.enabled')) {
            return array_replace($result, ['reason' => 'disabled']);
        }

        $skills = $student->studentSkills()->with('skill')->get()->pluck('skill.name')->filter();
        $program = trim((string) $student->program);
        $program = $program === 'Not specified' ? '' : $program;
        $profile = trim(implode("\n", array_filter([
            $summary,
            $skills->isNotEmpty() ? 'Skills: '.$skills->implode(', ') : '',
            $program ? 'Program: '.$program : '',
        ])));
        if ($profile === '') {
            return array_replace($result, ['reason' => 'empty_profile']);
        }

        $items->each(fn ($row) => $row['job']->loadMissing('requiredSkills.skill'));
        $shortened = false;
        $normalize = function (string $text) use (&$shortened): string {
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
            $limit = max(100, min(2000, (int) config('matching.text_limit', 1600)));
            $shortened = $shortened || mb_strlen($text) > $limit;
            return mb_substr($text, 0, $limit);
        };
        $texts = [$normalize($profile)];
        foreach ($items as $row) {
            $job = $row['job'];
            // Put explicit skills first so long descriptions do not crowd them out.
            $texts[] = $normalize(implode("\n", [
                $job->title,
                'Skills: '.$job->requiredSkills->pluck('skill.name')->filter()->implode(', '),
                $job->requirements ?? '',
                $job->description ?? '',
            ]));
        }

        try {
            $vectors = $this->embeddings->embed($texts);
        } catch (ConnectionException|RuntimeException $error) {
            // Do not log resume text, request payloads or provider error bodies.
            return array_replace($result, ['reason' => 'unavailable', 'shortened' => $shortened]);
        }

        $profileVector = array_shift($vectors);
        $items = $items->map(function ($row, $index) use ($profileVector, $vectors): array {
            $similarity = 0.0;
            foreach ($profileVector as $dimension => $value) {
                $similarity += $value * $vectors[$index][$dimension];
            }
            $row['semantic_similarity'] = round(max(-1.0, min(1.0, $similarity)), 4);
            return $row;
        })->sortByDesc('semantic_similarity')->values();

        return array_replace($result, [
            'items' => $items,
            'mode' => 'semantic',
            'reason' => null,
            'shortened' => $shortened,
            'model' => config('matching.model'),
        ]);
    }
}
