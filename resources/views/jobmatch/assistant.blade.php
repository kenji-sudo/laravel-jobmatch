<x-jobmatch-layout :portal="true" title="AI Assistant">
    <section class="assistant-heading">
        <div><p class="assistant-eyebrow">YOUR NEXT STEP, MADE CLEARER</p><h1>Meet your career assistant.</h1><p>Small questions. Practical answers. A little help moving forward.</p></div>
        <span class="assistant-provider">✦ Powered by Gemini</span>
    </section>

    <div class="assistant-workspace" data-assistant data-send-url="{{ route('portal.assistant.send') }}" data-clear-url="{{ route('portal.assistant.clear') }}">
        <aside class="assistant-guide">
            <div class="assistant-symbol" aria-hidden="true">✦</div>
            <h2>A good place to start</h2>
            <p>Explore your skills, prepare for interviews, or find your way around JobMatch.</p>
            <div class="assistant-prompts">
                <button type="button" data-prompt="Help me prepare a short introduction for an entry-level interview." @disabled(!$configured)>Prepare for an interview <span aria-hidden="true">↗</span></button>
                <button type="button" data-prompt="How can I describe a school project on my resume honestly and clearly?" @disabled(!$configured)>Improve my resume <span aria-hidden="true">↗</span></button>
                <button type="button" data-prompt="Explain skill coverage and an ML.NET model score. How are they different?" @disabled(!$configured)>Understand matching scores <span aria-hidden="true">↗</span></button>
                <button type="button" data-prompt="Which JobMatch pages can I use with my account?" @disabled(!$configured)>Find my way around <span aria-hidden="true">↗</span></button>
            </div>
            <div class="assistant-note"><strong>You stay in control.</strong><p>I can offer guidance, but I cannot edit your profile, apply for jobs, or make hiring decisions.</p></div>
        </aside>

        <section class="assistant-conversation" aria-labelledby="assistant-chat-title">
            <header class="assistant-chat-header">
                <div><h2 id="assistant-chat-title">JobMatch Assistant</h2><span>{{ $configured ? 'Ready for your questions' : 'Setup needed' }}</span></div>
                <form action="{{ route('portal.assistant.clear') }}" method="POST" data-clear-form>@csrf @method('DELETE')<button type="submit" class="assistant-reset">New chat</button></form>
            </header>

            <div class="assistant-messages" data-messages role="log" aria-label="Conversation" aria-live="polite" aria-relevant="additions text" tabindex="0">
                <div class="assistant-welcome" data-welcome @if(count($messages)) hidden @endif>
                    <span aria-hidden="true">✦</span><h3>What would you like to work on?</h3><p>Ask in English, Tagalog, or Taglish.<br>We can take it one step at a time.</p>
                </div>
                @foreach($messages as $message)
                    <article class="assistant-message assistant-message--{{ $message['role'] }}"><strong>{{ $message['role'] === 'user' ? 'You' : 'JobMatch Assistant' }}</strong><p>{{ $message['text'] }}</p></article>
                @endforeach
            </div>

            <div class="assistant-status" data-status role="status">{{ !$configured ? 'The assistant needs a Gemini API key and model configured by your administrator. Your message will not be sent until setup is complete.' : '' }}</div>

            <form class="assistant-composer" action="{{ route('portal.assistant.send') }}" method="POST" data-chat-form>
                @csrf
                <label class="assistant-input-label" for="assistant-message">Your message</label>
                <textarea id="assistant-message" name="message" rows="3" maxlength="2000" required placeholder="Ask about your next career step…" aria-describedby="assistant-privacy assistant-counter" @disabled(!$configured)>{{ old('message') }}</textarea>
                <div class="assistant-composer-actions"><span id="assistant-counter" data-counter>0 / 2,000</span><button class="button" type="submit" data-send @disabled(!$configured)>Send message <span aria-hidden="true">↑</span></button></div>
                <label class="assistant-consent"><input type="checkbox" name="consent" value="1" required @disabled(!$configured)> I agree to send my messages and recent chat history to Google Gemini for a response.</label>
                <p id="assistant-privacy">Do not include passwords or personal identifiers. Your resume, profile and matching results are not read automatically. This session keeps the latest 6 exchanges; New chat clears them from this app, not from Google's systems. AI responses may be inaccurate.</p>
                <noscript><p>Choose a starter, type your question, then send. The page will refresh to show the reply.</p></noscript>
            </form>
        </section>
    </div>
    <script type="module" src="{{ asset('js/jobmatch-assistant.js') }}?v=1"></script>
</x-jobmatch-layout>
