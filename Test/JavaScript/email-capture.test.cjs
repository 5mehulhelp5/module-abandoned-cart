const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture() {
    const timers = new Map(), requests = [], events = new Map();
    let sequence = 0, exported;
    const context = vm.createContext({
        setTimeout: fn => { const id = ++sequence; timers.set(id, fn); return id; },
        clearTimeout: id => timers.delete(id),
        addEventListener: (name, fn) => events.set(name, fn),
        removeEventListener: (name, fn) => { if (events.get(name) === fn) events.delete(name); }
    });
    context.window = context;
    const load = (name, dependencies = {}) => {
        context.define = (names, factory) => { exported = factory(...names.map(name => dependencies[name])); };
        vm.runInContext(fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js', name), 'utf8'), context);
        return exported;
    };
    const transport = (cartId, email) => {
        let done, fail;
        const promise = {
            done: fn => { done = fn; return promise; },
            fail: fn => { fail = fn; return promise; }
        };
        requests.push({cartId, email, complete: (value = true) => done(value), fail: (status = 503) => fail({status})});
        return promise;
    };
    const capture = load('guest-email-capture.js');
    const queue = capture(transport);
    const state = {cartId: 'cart-1', email: 'first@example.test', allowed: true, loggedIn: false};
    const update = (values = {}, changed = false) => { Object.assign(state, values); queue.update(state, changed); };
    const tick = () => { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(fn => fn()); };
    return {queue, state, requests, update, tick, timers, load, capture, transport, events};
}

for (const [name, values] of Object.entries({denied: {allowed: false}, customer: {loggedIn: true}, 'no cart': {cartId: null}, invalid: {email: 'bad'}, 'oversized email': {email: 'a'.repeat(250) + '@example.test'}})) {
    test(`does not capture for ${name}`, () => {
        const f = fixture(); f.update(values); f.tick();
        assert.equal(f.requests.length, 0);
    });
}

test('acknowledges true before deduplicating the same address', () => {
    const f = fixture(); f.update(); f.update();
    assert.equal(f.requests.length, 1);
    f.requests[0].complete(); f.update();
    assert.equal(f.requests.length, 1);
});

test('a false acknowledgement retries instead of treating the email as saved', () => {
    const f = fixture(); f.update(); f.requests[0].complete(false);
    assert.equal(f.requests.length, 1);
    f.tick(); assert.equal(f.requests.length, 2);
    f.requests[1].complete(); f.update(); assert.equal(f.requests.length, 2);
});

for (const status of [0, 408, 429, 500, 503]) {
    test(`retries transient failure ${status}`, () => {
        const f = fixture(); f.update(); f.requests[0].fail(status); f.tick();
        assert.equal(f.requests.length, 2);
        assert.equal(f.requests[1].email, f.state.email);
    });
}

test('does not loop on a permanent failure but allows a newer email', () => {
    const f = fixture(); f.update(); f.requests[0].fail(400); f.tick(); f.update();
    assert.equal(f.requests.length, 1);
    f.update({email: 'new@example.test'}); assert.equal(f.requests.length, 2);
});

test('bounds automatic attempts and resumes when the browser comes online', () => {
    const f = fixture(); f.update();
    for (let i = 0; i < 5; i++) { f.requests[i].fail(); f.tick(); }
    assert.equal(f.requests.length, 5);
    f.queue.online(); assert.equal(f.requests.length, 6);
});

test('serializes saves and coalesces rapid changes to the newest email', () => {
    const f = fixture(); f.update(); f.update({email: 'second@example.test'}); f.update({email: 'last@example.test'});
    assert.equal(f.requests.length, 1);
    f.requests[0].complete(); assert.equal(f.requests.length, 2);
    assert.equal(f.requests[1].email, 'last@example.test');
    f.requests[1].complete(); f.update(); assert.equal(f.requests.length, 2);
});

test('a failed obsolete save sends the newest address without retrying the old one', () => {
    const f = fixture(); f.update(); f.update({email: 'last@example.test'}); f.requests[0].fail();
    assert.equal(f.requests[1].email, 'last@example.test'); assert.equal(f.timers.size, 0);
});

test('withdrawal while a save is pending clears it after completion and retries the clear', () => {
    const f = fixture(); f.update(); f.update({allowed: false}, true); f.requests[0].complete();
    assert.equal(f.requests[1].email, '');
    f.requests[1].fail(); f.tick(); assert.equal(f.requests[2].email, '');
    f.requests[2].complete(false); f.update(); assert.equal(f.requests.length, 3);
});

test('withdraw then regrant clears the old capture before saving the new choice', () => {
    const f = fixture(); f.update(); f.update({allowed: false}, true); f.update({allowed: true}, true);
    f.requests[0].complete(); assert.equal(f.requests[1].email, '');
    f.requests[1].complete(false); assert.equal(f.requests[2].email, f.state.email);
});

test('changing a captured email to invalid clears the old recipient', () => {
    const f = fixture(); f.update(); f.requests[0].complete(); f.update({email: ''});
    assert.equal(f.requests[1].email, ''); f.requests[1].complete(false);
    f.update({email: 'next@example.test'}); assert.equal(f.requests[2].email, 'next@example.test');
});

test('a stale successful save is still cleared if the newer address fails', () => {
    const f = fixture(); f.update(); f.update({email: 'next@example.test'}); f.requests[0].complete();
    f.requests[1].fail(400); f.update({allowed: false}, true);
    assert.equal(f.requests[2].email, '');
});

test('the same email is captured again for a new consent choice or a new cart', () => {
    const f = fixture(); f.update(); f.requests[0].complete(); f.update({}, true);
    assert.equal(f.requests.length, 2); f.requests[1].complete();
    f.update({cartId: 'cart-2'}); assert.equal(f.requests[2].cartId, 'cart-2');
});

test('destroy cancels retries and ignores late completions', () => {
    const f = fixture(); f.update(); f.requests[0].fail(); f.queue.destroy(); f.tick(); f.queue.online();
    assert.equal(f.requests.length, 1); assert.equal(f.timers.size, 0);
});

test('checkout mixin captures prefilled input, debounces edits and removes its online handler', () => {
    const f = fixture(); let email = 'prefilled@example.test', listener;
    const mixin = f.load('email-capture-mixin.js', {
        'CommerceKings_Notification/js/permission': {hasPermission: () => true, onChange: (purpose, fn) => { listener = fn; }},
        'mage/storage': {post: (url, body) => {
            const data = JSON.parse(body);
            assert.equal(url, '/ck-notification/subscriptions');
            assert.deepEqual(Object.keys(data), ['type', 'reference', 'email']);
            assert.equal(data.type, 'cart_reminder');
            return f.transport(data.reference, data.email);
        }},
        'Magento_Checkout/js/model/quote': {getQuoteId: () => 'cart-1'},
        'Magento_Checkout/js/model/url-builder': {createUrl: url => url},
        'Magento_Customer/js/model/customer': {isLoggedIn: () => false},
        'CommerceKings_AbandonedCart/js/guest-email-capture': f.capture
    });
    const component = mixin({extend: value => value});
    component._super = () => component; component.email = () => email;
    component.initialize(); f.tick(); assert.equal(f.requests[0].email, email); f.requests[0].complete();
    email = 'intermediate@example.test'; component.emailHasChanged();
    email = 'latest@example.test'; component.emailHasChanged(); f.tick();
    assert.equal(f.requests.length, 2); assert.equal(f.requests[1].email, email);
    assert.equal(typeof listener, 'function'); component.destroy();
    assert.equal(f.events.size, 0);
});
