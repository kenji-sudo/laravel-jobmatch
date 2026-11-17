<?php

use App\Models\JobPosting;
use App\Models\JobSkill;
use App\Models\Skill;
use App\Models\User;
use App\Services\Matching\OllamaEmbeddingClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['matching.enabled' => true, 'matching.ollama_url' => 'http://127.0.0.1:11434']);
    Http::preventStrayRequests();
});

function semanticTestJob(array $attributes = []): JobPosting
{
    $employer = User::factory()->employer()->create(['is_active' => true])->employer;
    return JobPosting::factory()->create(array_merge([
        'employer_id' => $employer->id, 'is_active' => true,
        'deadline' => now()->addDay(),
    ], $attributes));
}

test('opening recommendations never invokes the model', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    semanticTestJob();
    $this->actingAs($user)->get('/portal/recommendations')->assertOk()->assertSee('Skill coverage results');
    Http::assertNothingSent();
});

test('only active students can open or run recommendations', function (string $role, bool $active) {
    $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
    $this->actingAs($user)->get('/portal/recommendations')->assertForbidden();
    $this->post('/portal/recommendations')->assertForbidden();
    Http::assertNothingSent();
})->with([
    ['employer', true], ['alumni', true], ['admin', true],
    ['placement_officer', true], ['student', false],
]);

test('guests cannot access recommendations', function () {
    $this->get('/portal/recommendations')->assertRedirect('/login');
    $this->post('/portal/recommendations')->assertRedirect('/login');
    Http::assertNothingSent();
});

test('semantic ranking keeps skill scores separate and ignores supplied student identity', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    $other = User::factory()->student()->create(['is_active' => true]);
    $skill = Skill::factory()->create(['name' => 'PHP']);
    $user->student->studentSkills()->create(['skill_id' => $skill->id, 'proficiency_level' => 3, 'years_of_experience' => 1]);
    $first = semanticTestJob(['title' => 'First related job']);
    $second = semanticTestJob(['title' => 'Second related job']);
    JobSkill::factory()->create(['job_posting_id' => $first->id, 'skill_id' => $skill->id, 'is_required' => true]);
    $expired = semanticTestJob(['title' => 'Expired excluded', 'deadline' => now()->subDay()]);
    Http::fake(['127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[2, 0], [0, 3], [4, 0]]])]);

    $response = $this->actingAs($user)->post('/portal/recommendations', [
        'summary' => 'Build reliable applications', 'student_id' => $other->student->id,
    ])->assertOk()->assertViewHas('result', function ($result) use ($first, $second) {
        return $result['mode'] === 'semantic'
            && $result['items']->pluck('job.id')->all() === [$second->id, $first->id]
            && $result['items']->pluck('score')->all() === [0, 100]
            && $result['items']->pluck('semantic_similarity')->all() === [1.0, 0.0];
    })->assertDontSee($expired->title);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:11434/api/embed'
        && $request['truncate'] === false
        && str_contains($request['input'][0], 'PHP')
        && ! str_contains(implode(' ', $request['input']), $user->email)
        && ! str_contains(implode(' ', $request['input']), $other->email));
    expect(session()->getOldInput('summary'))->toBeNull();
});

test('disabled AI visibly returns the baseline without calling a provider', function () {
    config(['matching.enabled' => false]);
    $user = User::factory()->student()->create(['is_active' => true]);
    semanticTestJob();
    $this->actingAs($user)->post('/portal/recommendations', ['summary' => 'PHP development'])
        ->assertOk()->assertSee('has not been enabled')->assertViewHas('result', fn ($r) => $r['mode'] === 'baseline');
    Http::assertNothingSent();
});

test('empty profiles and empty job collections do not call the model', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    $this->actingAs($user)->post('/portal/recommendations')->assertOk()->assertSee('No open jobs');
    semanticTestJob();
    $this->post('/portal/recommendations')->assertOk()->assertViewHas('result', fn ($r) => $r['reason'] === 'empty_profile');
    Http::assertNothingSent();
});

test('connection failures return an honest baseline without exposing provider errors', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    semanticTestJob();
    Http::fake(fn () => throw new ConnectionException('PRIVATE PROVIDER ERROR'));
    $this->actingAs($user)->post('/portal/recommendations', ['summary' => 'Application development'])
        ->assertOk()->assertSee('Semantic matching is unavailable')->assertDontSee('PRIVATE PROVIDER ERROR')
        ->assertViewHas('result', fn ($r) => $r['mode'] === 'baseline' && $r['reason'] === 'unavailable');
});

test('career summary validation does not retain rejected text in the session', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    $this->actingAs($user)->post('/portal/recommendations', ['summary' => str_repeat('x', 2001)])
        ->assertRedirect('/portal/recommendations')->assertSessionHasErrors('summary');
    expect(session()->getOldInput('summary'))->toBeNull();
    Http::assertNothingSent();
});

test('candidate and text bounds are enforced and disclosed', function () {
    config(['matching.candidate_limit' => 1, 'matching.text_limit' => 100]);
    $user = User::factory()->student()->create(['is_active' => true]);
    semanticTestJob();
    semanticTestJob();
    Http::fake(['127.0.0.1:11434/api/embed' => Http::response(['embeddings' => [[1, 0], [1, 0]]])]);
    $this->actingAs($user)->post('/portal/recommendations', ['summary' => str_repeat('a ', 150)])
        ->assertOk()->assertSee('Long profile or job text was shortened')->assertSee('from 2 eligible jobs')
        ->assertViewHas('result', fn ($r) => $r['candidate_count'] === 1);
    Http::assertSent(fn ($r) => count($r['input']) === 2 && mb_strlen($r['input'][0]) === 100);
});

test('model requests are rate limited', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    $this->actingAs($user);
    for ($i = 0; $i < 6; $i++) {
        $this->post('/portal/recommendations')->assertOk();
    }
    $this->post('/portal/recommendations')->assertStatus(429);
});

test('embedding client rejects external endpoints before sending text', function (string $url) {
    config(['matching.ollama_url' => $url]);
    expect(fn () => app(OllamaEmbeddingClient::class)->embed(['private text']))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['https://example.com', 'http://127.0.0.1.example.com', 'http://user:pass@127.0.0.1', 'http://127.0.0.1?target=external']);

test('embedding client rejects malformed responses', function (array $body, int $status) {
    Http::fake(['127.0.0.1:11434/api/embed' => Http::response($body, $status)]);
    expect(fn () => app(OllamaEmbeddingClient::class)->embed(['profile', 'job']))->toThrow(RuntimeException::class);
})->with([
    'provider failure' => [[], 503],
    'redirect' => [[], 302],
    'missing field' => [[], 200],
    'wrong count' => [['embeddings' => [[1, 0]]], 200],
    'zero vector' => [['embeddings' => [[0, 0], [1, 0]]], 200],
    'different dimensions' => [['embeddings' => [[1, 0], [1, 0, 0]]], 200],
    'numeric strings' => [['embeddings' => [['1', 0], [1, 0]]], 200],
    'wrong vector shape' => [['embeddings' => ['invalid', [1, 0]]], 200],
    'excessive magnitude' => [['embeddings' => [[1000001, 0], [1, 0]]], 200],
]);
