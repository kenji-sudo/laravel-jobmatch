<?php

declare(strict_types=1);

namespace App\Services\Matching;

use App\Models\Certification;
use App\Models\Internship;
use App\Models\Student;
use App\Services\MatchService;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

final class MlNetJobMatcher
{
    public function __construct(private MatchService $baseline, private MlNetClient $client) {}

    public function recommend(Student $student, bool $useAi): array
    {
        $ranked = $this->baseline->rankJobsForStudent($student);
        $items = $ranked->take(50)->values();
        $result = ['items' => $items, 'mode' => 'baseline', 'reason' => 'not_requested',
            'candidate_count' => $items->count(), 'eligible_count' => $ranked->count(), 'shortened' => false, 'model' => null];
        if (! $useAi || $items->isEmpty()) { return $result; }
        if (! config('matching.enabled')) { return array_replace($result, ['reason' => 'disabled']); }
        if (! is_numeric($student->gwa) || $student->gwa < 1 || $student->gwa > 5) {
            return array_replace($result, ['reason' => 'incomplete_profile']);
        }
        $skills = $student->studentSkills()->get();
        $ids = $skills->pluck('skill_id')->all();
        $certificates = Certification::where('student_id', $student->id)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->startOfDay()))->count();
        $internships = Internship::where('student_id', $student->id)->whereNotNull('end_date')->where('end_date', '<=', today())->count();
        $candidates = $items->map(function ($row) use ($student, $skills, $ids, $certificates, $internships) {
            $required = $row['job']->requiredSkills()->where('is_required', true)->pluck('skill_id');
            return ['jobId' => (string) $row['job']->id, 'features' => [
                'requiredSkillRatio' => $required->isEmpty() ? 0 : $required->intersect($ids)->count() / $required->count(),
                'gwaScore' => max(0, min(1, (3 - (float) $student->gwa) / 2)),
                'proficiencyDepth' => (float) ($skills->avg('proficiency_level') ?? 0),
                'certificationCount' => min(100, $certificates), 'internshipCount' => min(100, $internships),
            ]];
        })->all();
        try { $prediction = $this->client->rank($candidates); }
        catch (ConnectionException|RuntimeException $error) { return array_replace($result, ['reason' => 'unavailable']); }
        $byId = collect($prediction['rows'])->keyBy('jobId');
        $items = $items->map(function ($row) use ($byId) {
            $prediction = $byId[(string) $row['job']->id];
            return $row + ['ml_score' => $prediction['score'], 'base_score' => $prediction['baseScore'], 'contributions' => $prediction['contributions']];
        })->sortByDesc('ml_score')->values();
        return array_replace($result, ['items' => $items, 'mode' => 'mlnet', 'reason' => null, 'model' => $prediction['model']]);
    }
}
