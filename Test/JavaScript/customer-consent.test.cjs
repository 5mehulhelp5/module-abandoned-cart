const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture({config = {isCustomerLoggedIn: true, quoteData: {entity_id: 42}}, allowed = true, defer = false} = {}) {
    const requests = [], loads = [];
    let ready, changed;
    const consent = {
        hasConsent: purpose => purpose === 'cart_reminder' && allowed,
        onChange: (purpose, callback) => { assert.equal(purpose, 'cart_reminder'); changed = callback; }
    };
    const storage = {
        post: (url, body, global) => {
            let done, fail;
            const promise = {
                done: callback => { done = callback; return promise; },
                fail: callback => { fail = callback; return promise; }
            };
            requests.push({url, body: JSON.parse(body), global,
                complete: stored => done && done(stored), fail: status => fail && fail({status})});
            return promise;
        }
    };
    const urlBuilder = {createUrl: (route, parameters) => {
        assert.deepEqual(Object.keys(parameters), []);
        return '/rest/store-view/V1' + route;
    }};
    const context = vm.createContext({
        checkoutConfig: config,
        define: (names, factory) => factory(callback => { ready = callback; }, consent),
        require: (names, callback, failed) => {
            assert.deepEqual(Array.from(names), ['mage/storage', 'Magento_Checkout/js/model/url-builder']);
            const resolve = () => callback(storage, urlBuilder);
            resolve.reject = failed;
            loads.push(resolve);
            if (!defer) loads.shift()();
        }
    });
    context.window = context;
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../view/frontend/web/js/customer-consent.js'), 'utf8'), context);
    return {
        context, requests, loads, ready: () => ready(), flush: () => loads.shift()(),
        failLoad: () => {
            const pending = loads.shift();
            assert.equal(typeof pending.reject, 'function', 'RequireJS failures need an explicit error callback');
            pending.reject({requireType: 'scripterror'});
        },
        change: value => { allowed = value; changed(value); }
    };
}

test('customer checkout subscribes through the store-scoped self endpoint without an email or customer id', () => {
    const f = fixture();
    f.ready();
    assert.equal(f.requests.length, 1);
    assert.deepEqual(f.requests[0].body, {type: 'cart_reminder', reference: '42'});
    assert.equal(f.requests[0].url, '/rest/store-view/V1/ck-notification/subscriptions/mine');
    assert.equal(f.requests[0].global, false);
});

for (const [name, config, allowed] of [
    ['outside checkout', undefined, true],
    ['guest checkout', {isCustomerLoggedIn: false, quoteData: {entity_id: 'masked'}}, true],
    ['without consent', {isCustomerLoggedIn: true, quoteData: {entity_id: 42}}, false],
    ['without quote data', {isCustomerLoggedIn: true}, true],
    ['without a quote id', {isCustomerLoggedIn: true, quoteData: {}}, true],
    ['without a persisted quote', {isCustomerLoggedIn: true, quoteData: {entity_id: 0}}, true]
]) {
    test('does not subscribe ' + name, () => {
        const f = fixture({allowed});
        f.context.checkoutConfig = config;
        f.ready();
        if (allowed) f.change(true);
        assert.equal(f.requests.length, 0);
        assert.equal(f.loads.length, 0);
    });
}

test('granting consent on checkout subscribes and withdrawal makes no subscribe request', () => {
    const f = fixture({allowed: false});
    f.ready();
    f.change(true);
    assert.equal(f.requests.length, 1);
    f.change(false);
    assert.equal(f.requests.length, 1);
});

test('a refused subscription does not loop or prevent a later explicit attempt', () => {
    const f = fixture();
    f.ready();
    f.requests[0].complete(false);
    assert.equal(f.requests.length, 1);
    f.change(false);
    f.change(true);
    assert.equal(f.requests.length, 2);
    f.requests[1].complete(true);
});

for (const status of [0, 400, 401, 403, 408, 429, 500, 503]) {
    test('failed customer request ' + status + ' does not loop or block a later consent-triggered attempt', () => {
        const f = fixture();
        f.ready();
        assert.doesNotThrow(() => f.requests[0].fail(status));
        assert.equal(f.requests.length, 1);
        f.change(false);
        f.change(true);
        assert.equal(f.requests.length, 2);
        assert.equal(f.requests[1].body.reference, '42');
    });
}

for (const [name, cancel] of [
    ['consent was withdrawn', f => f.change(false)],
    ['the customer logged out', f => { f.context.checkoutConfig.isCustomerLoggedIn = false; }],
    ['checkout was removed', f => { f.context.checkoutConfig = undefined; }],
    ['the quote disappeared', f => { f.context.checkoutConfig.quoteData = {}; }]
]) {
    test('dependency loading cannot send after ' + name, () => {
        const f = fixture({defer: true});
        f.ready();
        assert.equal(f.loads.length, 1);
        cancel(f);
        assert.doesNotThrow(() => f.flush());
        assert.equal(f.requests.length, 0);
    });
}

test('dependency loading uses the current cart rather than a cart replaced while waiting', () => {
    const f = fixture({defer: true});
    f.ready();
    f.context.checkoutConfig.quoteData.entity_id = 43;
    f.flush();
    assert.equal(f.requests[0].body.reference, '43');
});

test('failed dependency loading sends no partial request and permits a later successful load attempt', () => {
    const f = fixture({defer: true});
    f.ready();
    assert.doesNotThrow(() => f.failLoad());
    assert.equal(f.requests.length, 0);
    assert.equal(f.loads.length, 0, 'no automatic loading loop');
    f.change(false);
    f.change(true);
    f.flush();
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].body.reference, '42');
});

test('a dependency load error followed by withdrawal never submits a subscription', () => {
    const f = fixture({defer: true});
    f.ready();
    f.change(false);
    assert.doesNotThrow(() => f.failLoad());
    f.ready();
    assert.equal(f.requests.length, 0);
    assert.equal(f.loads.length, 0);
});
