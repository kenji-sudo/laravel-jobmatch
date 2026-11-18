# JobMatch Assistant

Authenticated career and app guidance at `/portal/assistant`, through **AI Assistant** in the portal navigation or **Ask JobMatch** on other signed-in pages.

## Setup

Use `START-ASSISTANT.cmd` inside the configured Windows Laravel project. It reuses `GEMINI_API_KEY` and `GEMINI_MODEL`, asks for missing values locally, backs up `.env`, refreshes Laravel configuration, and opens the assistant. The model must be enabled in your Google AI project. Keys belong only in local environment settings.

For a source checkout, set these and run `php artisan config:clear`:

```dotenv
GEMINI_API_KEY=your-own-key
GEMINI_MODEL=your-enabled-model-id
AI_CHAT_ENABLED=true
```

Chat works independently of `MATCHING_AI_ENABLED`, Ollama, a trained ML.NET model, or the C# prediction server. This Laravel implementation shares Gemini credentials with resume extraction. It uses the Gemini REST chat format directly; it does not call Cole's C# `IChatCompletionService`, which remains part of his separate resume parser.

## Responsibilities

| File | Responsibility |
| --- | --- |
| `AssistantController.php` | Validation, account-scoped session history, send/clear responses |
| `GeminiChatService.php` | Fixed provider endpoint, role-aware instructions, response validation |
| `assistant.blade.php` | Accessible transcript, consent, starter questions, standard form fallback |
| `jobmatch-assistant.js` | Loading/errors, plain-text replies, starters and New chat |
| `jobmatch-assistant.css` | Responsive layout and launcher styles |

The assistant helps with honest resume wording, interviews, skill development, score definitions, and known role-appropriate routes. It has no tools or write access: it cannot submit applications, change profiles or scores, or make hiring decisions. It cannot see profiles, resumes, vacancies, or live recommendation results. Users may paste a short non-sensitive excerpt. Existing Presidio and ranking code in Cole's branch is not represented as connected here.

## Conversation and privacy

- Only active authenticated portal users may access chat. CSRF protection and six requests per minute apply. Send and clear use session locks.
- Users explicitly consent before messages and recent history go to Google Gemini. Profile fields and resume files are not automatically sent.
- The session keeps the latest six successful exchanges (2,000 characters per user message; 8,000 per reply). Client-supplied roles/history are ignored. Different accounts have separate transcripts.
- New chat clears this app's session transcript, not Google's retained data. Logout invalidates the session. Deployments should use private session storage and an appropriate session lifetime.
- Errors omit provider details, keys and prompts. Replies render as escaped/plain text; thought parts are excluded. Prompt instructions are behavioral guidance, not a guarantee of model compliance.
- Provider failures leave the message available to retry. No fabricated response or score is substituted.

## Verification and handoff

Run `php artisan test --filter=Assistant`, the full PHP suite, `npm run test:ui` and `npm run build`. Feature tests fake provider transport and cover authorization, consent, role/history spoofing, history limits, account isolation, provider failures, escaping, throttling and standard forms.

Browser acceptance: sign in; open AI Assistant; select a starter; check consent; send; verify loading/reply; ask a follow-up; refresh; choose New chat; try mobile. Verify logout/session expiry. Send a short non-sensitive prompt with your real Gemini key to confirm live API access and answer quality.

Provider contract: https://ai.google.dev/api/generate-content and https://ai.google.dev/gemini-api/docs/text-generation#multi-turn-conversations
