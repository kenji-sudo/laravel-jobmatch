<?php

use App\Models\Certification;
use App\Models\Internship;
use App\Models\JobPosting;
use App\Models\JobSkill;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['matching.driver' => 'gemini_mlnet', 'matching.enabled' => true,
        'matching.integration_key' => str_repeat('test-only-key-', 3), 'matching.mlnet_url' => 'http://127.0.0.1:5291',
        'matching.gemini_key' => 'fake-test-key', 'matching.gemini_model' => 'fixture-model']);
    Http::preventStrayRequests();
});

function alignedStudent(): User
{
    $user = User::factory()->student()->create(['is_active' => true]);
    $user->student->update(['gwa' => 1.5]);
    return $user;
}

function alignedJob(): JobPosting
{
    $company = User::factory()->employer()->create(['is_active' => true])->employer;
    return JobPosting::factory()->create(['employer_id' => $company->id, 'is_active' => true, 'deadline' => now()->addDay()]);
}

function alignedPrediction(int $id): array
{
    return ['schemaVersion' => 'cole-features-v1', 'modelVersion' => str_repeat('a', 64), 'candidates' => [[
        'jobId' => (string) $id, 'score' => 70, 'baseScore' => 20,
        'contributions' => array_map(fn ($name) => ['feature' => $name, 'value' => 10],
            ['RequiredSkillRatio', 'GwaScore', 'ProficiencyDepth', 'CertificationCount', 'InternshipCount']),
    ]]];
}

function alignedExtraction(array $replace = []): array
{
    $data = array_replace(['skills' => [['name' => 'PHP', 'proficiency' => null]], 'gwa' => null,
        'graduationYear' => null, 'degreeProgram' => 'BSIT', 'certificationCount' => 1, 'internshipCount' => 1], $replace);
    return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($data)]]]]]];
}

test('ML.NET sends actual certificate and completed internship counts', function () {
    $user = alignedStudent();
    $student = $user->student;
    $job = alignedJob();
    $skill = Skill::factory()->create();
    $student->studentSkills()->create(['skill_id' => $skill->id, 'proficiency_level' => 4, 'years_of_experience' => 1]);
    JobSkill::factory()->create(['job_posting_id' => $job->id, 'skill_id' => $skill->id, 'is_required' => true]);
    Certification::factory()->count(2)->create(['student_id' => $student->id, 'expiry_date' => null]);
    Certification::factory()->create(['student_id' => $student->id, 'expiry_date' => now()->subDay()]);
    Internship::factory()->create(['student_id' => $student->id, 'start_date' => now()->subMonths(3), 'end_date' => now()->subDay()]);
    Internship::factory()->create(['student_id' => $student->id, 'start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);
    Http::fake(['127.0.0.1:5291/v1/rank' => Http::response(alignedPrediction($job->id))]);
    $this->actingAs($user)->post('/portal/recommendations')->assertOk()->assertSee('Model-ranked matches')
        ->assertSee('70.00/100')->assertDontSee(config('matching.integration_key'));
    Http::assertSent(fn ($r) => $r['candidates'][0]['features']['certificationCount'] === 2
        && $r['candidates'][0]['features']['internshipCount'] === 1
        && $r['candidates'][0]['features']['requiredSkillRatio'] == 1
        && $r['candidates'][0]['features']['gwaScore'] == .75
        && $r['candidates'][0]['features']['proficiencyDepth'] == 4
        && !str_contains($r->body(), $user->email));
});

test('missing GWA is never silently invented for prediction', function () {
    $user = alignedStudent(); $user->student->update(['gwa' => 0]); alignedJob();
    $this->actingAs($user)->post('/portal/recommendations')->assertOk()->assertSee('Complete your academic standing');
    Http::assertNothingSent();
});

test('unconfigured integration preserves the baseline', function () {
    config(['matching.integration_key' => '']);
    $user = alignedStudent(); alignedJob();
    $this->actingAs($user)->post('/portal/recommendations')->assertOk()->assertSee('trained matching model is unavailable');
    Http::assertNothingSent();
});

test('prediction response IDs and explanations are validated', function (string $failure) {
    $user = alignedStudent(); $job = alignedJob(); $data = alignedPrediction($job->id);
    if ($failure === 'unknown_id') { $data['candidates'][0]['jobId'] = 'unrequested-job'; }
    if ($failure === 'inconsistent') { $data['candidates'][0]['score'] = 90; }
    if ($failure === 'string_score') { $data['candidates'][0]['score'] = '70'; }
    if ($failure === 'duplicate_feature') { $data['candidates'][0]['contributions'][0]['feature'] = 'GwaScore'; }
    Http::fake(['127.0.0.1:5291/v1/rank' => Http::response($data)]);
    $this->actingAs($user)->post('/portal/recommendations')->assertOk()->assertViewHas('result', fn ($r) => $r['mode'] === 'baseline');
})->with(['unknown_id', 'inconsistent', 'string_score', 'duplicate_feature']);

test('resume extraction requires explicit consent before calling Gemini', function () {
    $user = alignedStudent();
    $file = UploadedFile::fake()->createWithContent('resume.pdf', "%PDF-1.7\nTest resume\n%%EOF");
    $this->actingAs($user)->post('/portal/resume/extract', ['resume' => $file])->assertSessionHasErrors('consent');
    Http::assertNothingSent();
});

test('Gemini draft is displayed without changing confirmed records or leaking the key', function () {
    $user = alignedStudent(); Skill::factory()->create(['name' => 'PHP']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(alignedExtraction())]);
    $file = UploadedFile::fake()->createWithContent('resume.pdf', "%PDF-1.7\nTest resume\n%%EOF");
    $this->actingAs($user)->post('/portal/resume/extract', ['resume' => $file, 'consent' => 1])
        ->assertOk()->assertSee('Draft skills')->assertSee('Confirm and Save Selected Skills')->assertDontSee('fake-test-key')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($user->student->fresh()->gwa)->toBe('1.50');
    expect($user->student->studentSkills()->count())->toBe(0);
    Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', 'fake-test-key')
        && !str_contains($r->url(), 'fake-test-key')
        && $r['contents'][0]['parts'][0]['inlineData']['mimeType'] === 'application/pdf');
});

test('invalid or unavailable Gemini results cannot become a successful empty draft', function (string $failure) {
    $user = alignedStudent();
    $body = alignedExtraction();
    if ($failure === 'json') { $body['candidates'][0]['content']['parts'][0]['text'] = 'broken json'; }
    if ($failure === 'truncated') { $body['candidates'][0]['finishReason'] = 'MAX_TOKENS'; }
    if ($failure === 'range') { $body = alignedExtraction(['gwa' => 99]); }
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body, $failure === 'outage' ? 503 : 200)]);
    $file = UploadedFile::fake()->createWithContent('resume.pdf', "%PDF-1.7\nTest resume\n%%EOF");
    $this->actingAs($user)->post('/portal/resume/extract', ['resume' => $file, 'consent' => 1])
        ->assertOk()->assertSee('Extraction is unavailable')->assertViewHas('draft', null);
})->with(['json', 'truncated', 'range', 'outage']);

test('extraction and confirmation reject other roles', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $this->actingAs($user)->get('/portal/resume/extract')->assertForbidden();
    $this->post('/portal/resume/extract')->assertForbidden();
    $this->post('/portal/resume/confirm')->assertForbidden();
    Http::assertNothingSent();
})->with(['employer', 'alumni', 'admin', 'placement_officer']);

test('confirmation saves selected approved skills only to the signed in student', function () {
    $user = alignedStudent(); $other = alignedStudent(); $skill = Skill::factory()->create();
    $this->actingAs($user)->post('/portal/resume/confirm', [
        'selected' => [$skill->id], 'levels' => [$skill->id => 3], 'student_id' => $other->student->id, 'gwa' => 5,
    ])->assertRedirect('/portal/skills')->assertSessionHasNoErrors();
    expect($user->student->studentSkills()->count())->toBe(1);
    expect($other->student->studentSkills()->count())->toBe(0);
    expect($user->student->fresh()->gwa)->toBe('1.50');
});

test('invalid selected levels cannot partially save confirmed skills', function () {
    $user = alignedStudent(); $skills = Skill::factory()->count(2)->create();
    $this->actingAs($user)->post('/portal/resume/confirm', ['selected' => $skills->pluck('id')->all(), 'levels' => [$skills[0]->id => 3]])
        ->assertSessionHasErrors('levels.'.$skills[1]->id);
    expect($user->student->studentSkills()->count())->toBe(0);
});
