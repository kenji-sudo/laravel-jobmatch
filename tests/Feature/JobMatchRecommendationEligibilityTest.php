<?php

use App\Models\JobPosting;
use App\Models\JobSkill;
use App\Models\Skill;
use App\Models\User;
use App\Services\MatchService;
use Illuminate\Support\Carbon;

test('recommendations follow the public job eligibility rules', function (string $deadline, bool $active, bool $employerActive, bool $visible) {
    Carbon::setTestNow(Carbon::parse('2026-11-17 15:00:00'));

    try {
        $student = User::factory()->student()->create(['is_active' => true])->student;
        $employer = User::factory()->employer()->create(['is_active' => $employerActive])->employer;
        $dates = [
            'yesterday' => now()->subDay(),
            'today' => now()->startOfDay(),
            'tomorrow' => now()->addDay(),
            'none' => null,
        ];
        $job = JobPosting::factory()->create([
            'employer_id' => $employer->id,
            'is_active' => $active,
            'deadline' => $dates[$deadline],
        ]);

        $ids = app(MatchService::class)->rankJobsForStudent($student)->pluck('job.id')->all();

        expect(in_array($job->id, $ids, true))->toBe($visible);
        $this->get('/jobs/'.$job->id)->assertStatus($visible ? 200 : 404);
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'future deadline' => ['tomorrow', true, true, true],
    'deadline today stays open' => ['today', true, true, true],
    'no deadline stays open' => ['none', true, true, true],
    'expired active posting is excluded' => ['yesterday', true, true, false],
    'closed posting is excluded' => ['tomorrow', false, true, false],
    'inactive employer is excluded' => ['tomorrow', true, false, false],
    'inactive employer without deadline is excluded' => ['none', true, false, false],
]);

test('eligible jobs retain the existing skill scores and descending ranking', function () {
    $student = User::factory()->student()->create(['is_active' => true])->student;
    $employer = User::factory()->employer()->create(['is_active' => true])->employer;
    $skills = Skill::factory()->count(2)->create();
    $student->studentSkills()->create([
        'skill_id' => $skills[0]->id,
        'proficiency_level' => 3,
        'years_of_experience' => 1,
    ]);
    $jobs = JobPosting::factory()->count(2)->create([
        'employer_id' => $employer->id,
        'is_active' => true,
        'deadline' => now()->addDay(),
    ]);
    foreach ($jobs as $job) {
        JobSkill::factory()->create([
            'job_posting_id' => $job->id,
            'skill_id' => $skills[0]->id,
            'is_required' => true,
        ]);
    }
    JobSkill::factory()->create([
        'job_posting_id' => $jobs[0]->id,
        'skill_id' => $skills[1]->id,
        'is_required' => true,
    ]);

    $ranked = app(MatchService::class)->rankJobsForStudent($student);

    expect($ranked->pluck('score')->all())->toBe([100, 50]);
    expect($ranked->pluck('job.id')->all())->toBe([$jobs[1]->id, $jobs[0]->id]);
});
