<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Assistant\GeminiChatService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

final class AssistantController extends Controller
{
    public function index(Request $request, GeminiChatService $chat): Response
    {
        return response()->view('jobmatch.assistant', [
            'messages' => $request->session()->get($this->historyKey($request), []),
            'configured' => $chat->configured(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $request, GeminiChatService $chat): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'consent' => ['accepted'],
        ]);

        $key = $this->historyKey($request);
        $history = $request->session()->get($key, []);
        // Only server-owned turns are sent. Client-supplied roles/history are never used.
        $turn = ['role' => 'user', 'text' => trim($data['message'])];
        try {
            $reply = $chat->reply([...$history, $turn], $request->user()->role->value);
        } catch (ConnectionException|RuntimeException) {
            $error = 'JobMatch Assistant is unavailable right now. Please try again later or ask your administrator to check the Gemini configuration.';

            return $request->expectsJson()
                ? response()->json(['message' => $error], 503)->header('Cache-Control', 'no-store, private')
                : redirect()->route('portal.assistant')->withErrors(['assistant' => $error])->withInput();
        }

        $history = [...$history, $turn, ['role' => 'model', 'text' => $reply]];
        $request->session()->put($key, array_slice($history, -2 * (int) config('assistant.history_pairs')));

        return $request->expectsJson()
            ? response()->json(['reply' => $reply])->header('Cache-Control', 'no-store, private')
            : redirect()->route('portal.assistant');
    }

    public function clear(Request $request): JsonResponse|RedirectResponse
    {
        $request->session()->forget($this->historyKey($request));

        return $request->expectsJson()
            ? response()->json(['cleared' => true])->header('Cache-Control', 'no-store, private')
            : redirect()->route('portal.assistant');
    }

    private function historyKey(Request $request): string
    {
        return 'assistant.history.'.$request->user()->getAuthIdentifier();
    }
}
