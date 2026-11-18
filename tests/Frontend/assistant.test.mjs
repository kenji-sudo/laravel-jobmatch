import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../../public/js/jobmatch-assistant.js', import.meta.url), 'utf8');

function harness({configured = true, responder = async () => ({ok: true, json: async () => ({reply: 'Practice one example.'})})} = {}) {
    const node = () => ({
        value: '', textContent: '', disabled: false, hidden: false, children: [], handlers: {}, dataset: {},
        addEventListener(name, handler) { this.handlers[name] = handler; },
        append(...children) { this.children.push(...children); },
        focus() { this.focused = true; }, setAttribute() {},
        remove() { this.parent.children.splice(this.parent.children.indexOf(this), 1); },
    });
    const input = node(); input.disabled = !configured;
    const send = node(); send.disabled = !configured;
    const status = node();
    const welcome = node();
    const counter = node();
    const reset = node();
    const consent = {checked: false};
    const form = node();
    form.elements = {message: input, consent, _token: {value: 'csrf-fixture'}};
    form.reportValidity = () => consent.checked && input.value.length <= 2000;
    const clear = node(); clear.querySelector = () => reset;
    const log = node();
    const append = log.append;
    log.append = (...children) => { children.forEach(child => { child.parent = log; }); append.call(log, ...children); };
    log.querySelectorAll = () => [...log.children];
    const starter = node(); starter.dataset.prompt = 'Help me prepare.'; starter.disabled = !configured;
    const selectors = {'[data-chat-form]': form, '[data-clear-form]': clear, '[data-send]': send,
        '[data-messages]': log, '[data-status]': status, '[data-welcome]': welcome, '[data-counter]': counter};
    const root = {dataset: {sendUrl: '/portal/assistant'}, querySelector: key => selectors[key], querySelectorAll: () => [starter]};
    const requests = [];
    vm.runInNewContext(source, {
        document: {querySelector: () => root, createElement: node},
        fetch: async (url, options) => { requests.push({url, ...options}); return responder(); },
        Error, JSON, Math, String,
    });
    const event = {preventDefault() {}};
    return {input, send, status, welcome, log, starter, consent, requests, counter,
        submit: () => form.handlers.submit(event), clear: () => clear.handlers.submit(event),
        choose: () => starter.handlers.click()};
}

test('assistant starters fill the draft without sending and consent is required', async () => {
    const h = harness();
    h.choose();
    assert.equal(h.input.value, 'Help me prepare.');
    await h.submit();
    assert.equal(h.requests.length, 0);
    assert.equal(h.input.focused, true);
});

test('assistant sends same-origin CSRF protected requests and renders provider markup as text', async () => {
    const reply = '<img src=x onerror=alert(1)>';
    const h = harness({responder: async () => ({ok: true, json: async () => ({reply})})});
    h.input.value = 'Hello'; h.consent.checked = true;
    await h.submit();
    assert.equal(h.requests[0].credentials, 'same-origin');
    assert.equal(h.requests[0].headers['X-CSRF-TOKEN'], 'csrf-fixture');
    assert.equal(h.log.children[1].children[1].textContent, reply);
    assert.equal(h.log.children[1].children[1].innerHTML, undefined);
    assert.equal(h.input.value, '');
    assert.equal(h.send.disabled, false);
});

test('assistant errors preserve the draft and show no invented reply', async () => {
    const h = harness({responder: async () => ({ok: false, status: 503})});
    h.input.value = 'My draft'; h.consent.checked = true;
    await h.submit();
    assert.equal(h.input.value, 'My draft');
    assert.equal(h.log.children.length, 0);
    assert.match(h.status.textContent, /unavailable/);
    assert.equal(h.send.disabled, false);
});

test('assistant prevents duplicate submissions while a request is pending', async () => {
    let resolve;
    const h = harness({responder: () => new Promise(done => { resolve = done; })});
    h.input.value = 'Hello'; h.consent.checked = true;
    const pending = h.submit();
    assert.equal(h.send.disabled, true);
    await h.submit();
    assert.equal(h.requests.length, 1);
    resolve({ok: true, json: async () => ({reply: 'Hello'})});
    await pending;
    assert.equal(h.send.disabled, false);
});

test('assistant new chat clears the visible transcript and resets consent', async () => {
    const h = harness();
    h.input.value = 'Hello'; h.consent.checked = true;
    await h.submit();
    await h.clear();
    assert.equal(h.requests[1].method, 'DELETE');
    assert.equal(h.log.children.length, 0);
    assert.equal(h.consent.checked, false);
    assert.equal(h.welcome.hidden, false);
});

test('assistant handles expired sessions and throttling without losing the draft', async () => {
    for (const status of [401, 419, 429]) {
        const h = harness({responder: async () => ({ok: false, status})});
        h.input.value = 'Keep this'; h.consent.checked = true;
        await h.submit();
        assert.match(h.status.textContent, status === 429 ? /wait a minute/ : /session has expired/);
        assert.equal(h.input.value, 'Keep this');
    }
});

test('assistant remains disabled without configuration even after clearing', async () => {
    const h = harness({configured: false});
    h.choose();
    assert.equal(h.input.value, '');
    await h.submit();
    assert.equal(h.requests.length, 0);
    await h.clear();
    assert.equal(h.send.disabled, true);
    assert.equal(h.starter.disabled, true);
});

test('assistant limits the visible history to the latest six exchanges', async () => {
    const h = harness();
    for (let i = 0; i < 8; i++) {
        h.input.value = `Question ${i}`; h.consent.checked = true;
        await h.submit();
    }
    assert.equal(h.log.children.length, 12);
    assert.equal(h.log.children[0].children[1].textContent, 'Question 2');
});
