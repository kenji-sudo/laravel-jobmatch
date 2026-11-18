const root = document.querySelector('[data-assistant]');

if (root) {
    const form = root.querySelector('[data-chat-form]');
    const clearForm = root.querySelector('[data-clear-form]');
    const input = form.elements.message;
    const send = root.querySelector('[data-send]');
    const log = root.querySelector('[data-messages]');
    const status = root.querySelector('[data-status]');
    const welcome = root.querySelector('[data-welcome]');
    const token = form.elements._token.value;
    const starters = [...root.querySelectorAll('[data-prompt]')];
    let busy = false;

    function updateCounter() {
        root.querySelector('[data-counter]').textContent = `${[...input.value].length} / 2,000`;
    }

    function setBusy(value) {
        busy = value;
        send.disabled = value;
        input.readOnly = value;
        clearForm.querySelector('button').disabled = value;
        starters.forEach(button => { button.disabled = value; });
        send.textContent = value ? 'Thinking…' : 'Send message ↑';
        log.setAttribute('aria-busy', String(value));
    }

    function appendMessage(role, text) {
        welcome.hidden = true;
        const message = document.createElement('article');
        message.className = `assistant-message assistant-message--${role}`;
        const author = document.createElement('strong');
        author.textContent = role === 'user' ? 'You' : 'JobMatch Assistant';
        const body = document.createElement('p');
        body.textContent = text;
        message.append(author, body);
        log.append(message);
        // Keep the visible transcript consistent with the bounded server history.
        const turns = log.querySelectorAll('.assistant-message');
        [...turns].slice(0, Math.max(0, turns.length - 12)).forEach(turn => turn.remove());
        log.scrollTop = log.scrollHeight;
    }

    async function request(method, body) {
        const response = await fetch(root.dataset.sendUrl, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body),
        });
        if (response.status === 401 || response.status === 419) {
            throw new Error('Your session has expired. Refresh this page and sign in again.');
        }
        if (response.status === 429) {
            throw new Error('Please wait a minute before sending another message.');
        }
        if (!response.ok) {
            throw new Error(response.status === 422 ? 'Check your message and consent before sending.' : 'The assistant is unavailable right now. Your message is still here so you can try again.');
        }
        return response.json();
    }

    input.addEventListener('input', updateCounter);
    starters.forEach(button => button.addEventListener('click', () => {
        if (busy || input.disabled) return;
        input.value = button.dataset.prompt;
        updateCounter();
        input.focus();
    }));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || input.disabled || !form.reportValidity()) return;
        const message = input.value.trim();
        if (!message) { input.focus(); return; }
        setBusy(true);
        status.textContent = 'JobMatch Assistant is thinking…';
        try {
            const data = await request('POST', { message, consent: '1' });
            if (typeof data.reply !== 'string') throw new Error('The reply could not be read. Please refresh the conversation.');
            appendMessage('user', message);
            appendMessage('model', data.reply);
            input.value = '';
            updateCounter();
            status.textContent = 'Reply received.';
        } catch (error) {
            status.textContent = error.message || 'Unable to connect. Please try again.';
        } finally {
            setBusy(false);
            input.focus();
        }
    });

    clearForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        const wasDisabled = input.disabled;
        setBusy(true);
        try {
            await request('DELETE', {});
            log.querySelectorAll('.assistant-message').forEach(message => message.remove());
            welcome.hidden = false;
            status.textContent = 'New chat started. Previous messages were cleared from this app.';
            input.value = '';
            form.elements.consent.checked = false;
            updateCounter();
        } catch (error) {
            status.textContent = error.message || 'Unable to clear the conversation. Please try again.';
        } finally {
            setBusy(false);
            if (wasDisabled) {
                send.disabled = true;
                starters.forEach(button => { button.disabled = true; });
            } else input.focus();
        }
    });

    updateCounter();
    log.scrollTop = log.scrollHeight;
}
