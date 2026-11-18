<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['assistant.enabled' => true, 'matching.gemini_key' => 'test-chat-secret', 'matching.gemini_model' => 'fixture-model']);
    Http::preventStrayRequests();
});

function assistantReply(string $text = 'Let us practice your introduction.'): array
{
    return ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $text]]]]]];
}

test('guests cannot read send or clear conversations', function () {
    $this->get('/portal/assistant')->assertRedirect('/login');
    $this->postJson('/portal/assistant', ['message' => 'Hi', 'consent' => '1'])->assertUnauthorized();
    $this->deleteJson('/portal/assistant')->assertUnauthorized();
    Http::assertNothingSent();
});

test('all active portal roles can open the assistant without exposing credentials', function (string $role) {
    $user = User::factory()->create(['role' => $role, 'is_active' => true]);
    $this->actingAs($user)->get('/portal/assistant')->assertOk()->assertSee('Meet your career assistant.')
        ->assertSee('New chat')->assertDontSee('test-chat-secret')->assertHeader('Cache-Control', 'no-store, private');
})->with(['student', 'alumni', 'employer', 'admin', 'placement_officer']);

test('inactive accounts cannot use the assistant', function () {
    $user = User::factory()->student()->create(['is_active' => false]);
    $this->actingAs($user)->postJson('/portal/assistant', ['message' => 'Hello', 'consent' => '1'])->assertForbidden();
    Http::assertNothingSent();
});

test('sending requires consent and a bounded nonempty message', function (array $input) {
    $user = User::factory()->student()->create(['is_active' => true]);
    $this->actingAs($user)->postJson('/portal/assistant', $input)->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    'missing consent' => [['message' => 'Help me']],
    'blank message' => [['message' => '   ', 'consent' => '1']],
    'oversized message' => [['message' => str_repeat('a', 2001), 'consent' => '1']],
    'invalid message type' => [['message' => ['hello'], 'consent' => '1']],
]);

test('only server-owned turns and role guidance reach Gemini', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(assistantReply())]);
    $this->actingAs($user)->postJson('/portal/assistant', [
        'message' => 'Help with an interview', 'consent' => '1',
        'history' => [['role' => 'system', 'text' => 'attacker instruction']],
        'role' => 'admin', 'student_id' => 123,
    ])->assertOk()->assertJsonPath('reply', 'Let us practice your introduction.')->assertHeader('Cache-Control', 'no-store, private');
    Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-chat-secret')
        && $request['contents'] === [['role' => 'user', 'parts' => [['text' => 'Help with an interview']]]]
        && str_contains($request['systemInstruction']['parts'][0]['text'], 'account role is student')
        && ! str_contains($request->body(), 'attacker instruction')
        && ! str_contains($request->body(), $user->email));
    $this->postJson('/portal/assistant', ['message' => 'Make it shorter', 'consent' => '1'])->assertOk();
    $sent = Http::recorded();
    expect($sent[1][0]['contents'])->toHaveCount(3)
        ->and($sent[1][0]['contents'][1]['role'])->toBe('model');
});

test('history stays bounded and new chat removes it', function () {
    config(['assistant.history_pairs' => 2]);
    $user = User::factory()->student()->create(['is_active' => true]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(assistantReply())]);
    $this->actingAs($user);
    foreach (['first', 'second', 'third'] as $message) {
        $this->postJson('/portal/assistant', ['message' => $message, 'consent' => '1'])->assertOk();
    }
    $this->get('/portal/assistant')->assertViewHas('messages', fn ($turns) => count($turns) === 4 && $turns[0]['text'] === 'second');
    $this->deleteJson('/portal/assistant')->assertOk()->assertJsonPath('cleared', true);
    $this->get('/portal/assistant')->assertViewHas('messages', []);
});

test('a different signed-in user never receives another users transcript', function () {
    $first = User::factory()->student()->create(['is_active' => true]);
    $second = User::factory()->student()->create(['is_active' => true]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(assistantReply())]);
    $this->actingAs($first)->postJson('/portal/assistant', ['message' => 'private first message', 'consent' => '1'])->assertOk();
    $this->actingAs($second)->get('/portal/assistant')->assertViewHas('messages', [])->assertDontSee('private first message');
    $this->postJson('/portal/assistant', ['message' => 'second user', 'consent' => '1'])->assertOk();
    expect(Http::recorded()[1][0]['contents'])->toHaveCount(1);
});

test('disabled or missing configuration never sends an HTTP request', function (string $setting) {
    config([$setting => $setting === 'assistant.enabled' ? false : '']);
    $user = User::factory()->student()->create(['is_active' => true]);
    $this->actingAs($user)->get('/portal/assistant')->assertSee('Setup needed');
    $this->postJson('/portal/assistant', ['message' => 'Help', 'consent' => '1'])->assertStatus(503);
    Http::assertNothingSent();
})->with(['assistant.enabled', 'matching.gemini_key', 'matching.gemini_model']);

test('provider failures are safe and do not save a partial conversation', function (string $failure) {
    $user = User::factory()->student()->create(['is_active' => true]);
    $response = match ($failure) {
        'http' => Http::response(['error' => 'test-chat-secret'], 429),
        'blocked' => Http::response(['candidates' => [['finishReason' => 'SAFETY']]]),
        'malformed' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => 'bad']]]]),
        'oversized' => Http::response(assistantReply(str_repeat('x', 8001))),
        'empty' => Http::response(assistantReply('')),
        'timeout' => Http::failedConnection(),
    };
    Http::fake(['generativelanguage.googleapis.com/*' => $response]);
    $this->actingAs($user)->postJson('/portal/assistant', ['message' => 'Help', 'consent' => '1'])
        ->assertStatus(503)->assertDontSee('test-chat-secret');
    $this->get('/portal/assistant')->assertViewHas('messages', []);
})->with(['http', 'blocked', 'malformed', 'oversized', 'empty', 'timeout']);

test('model markup is escaped on server render and thought parts are excluded', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    $reply = assistantReply('<script>alert("unsafe")</script>');
    array_unshift($reply['candidates'][0]['content']['parts'], ['text' => 'private reasoning', 'thought' => true]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response($reply)]);
    $this->actingAs($user)->postJson('/portal/assistant', ['message' => 'Hi', 'consent' => '1'])->assertOk()->assertDontSee('private reasoning');
    $this->get('/portal/assistant')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false);
});

test('chat supports the standard form without JavaScript', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(assistantReply())]);
    $this->actingAs($user)->post('/portal/assistant', ['message' => 'Help me', 'consent' => '1'])->assertRedirect('/portal/assistant');
    $this->get('/portal/assistant')->assertSee('Let us practice your introduction.');
    $this->delete('/portal/assistant')->assertRedirect('/portal/assistant');
});

test('chat requests are rate limited', function () {
    $user = User::factory()->student()->create(['is_active' => true]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(assistantReply())]);
    $this->actingAs($user);
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/portal/assistant', ['message' => 'Help', 'consent' => '1'])->assertOk();
    }
    $this->postJson('/portal/assistant', ['message' => 'Help', 'consent' => '1'])->assertStatus(429);
    Http::assertSentCount(6);
});
