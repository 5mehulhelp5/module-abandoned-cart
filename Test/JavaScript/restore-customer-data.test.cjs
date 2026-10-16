'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const test = require('node:test');
const vm = require('node:vm');

// Exercise Magento's real XML converter, section URL matching, and customer-data invalidation.
const root = process.env.MAGENTO_ROOT;
assert.ok(root, 'Set MAGENTO_ROOT to the Magento installation used for these integration checks.');
const _ = require(path.join(root, 'lib/web/underscore.js'));
const converted = spawnSync('php', ['-r', `
    require getenv('MAGENTO_ROOT') . '/vendor/autoload.php';
    $xml = new DOMDocument();
    $xml->load($argv[1]);
    $schema = dirname((new ReflectionClass(Magento\\Customer\\CustomerData\\SectionConfigConverter::class))->getFileName(), 2)
        . '/etc/sections.xsd';
    if (!$xml->schemaValidate($schema)) { throw new RuntimeException('Invalid customer-data configuration.'); }
    echo json_encode((new Magento\\Customer\\CustomerData\\SectionConfigConverter())->convert($xml));
`, path.resolve(__dirname, '../../etc/frontend/sections.xml')], {encoding: 'utf8'});
assert.equal(converted.status, 0, converted.stderr || converted.stdout);
const sectionSettings = JSON.parse(converted.stdout);
const nativeJs = path.join(root, 'vendor/magento/module-customer/view/frontend/web/js');

function storage(values = {}) {
    return {
        get: key => values[key],
        set: (key, value) => { values[key] = value; },
        isSet: key => Object.hasOwn(values, key),
        remove: key => { delete values[key]; },
        keys: () => Object.keys(values),
        removeAll: () => { Object.keys(values).forEach(key => { delete values[key]; }); }
    };
}

function state() {
    return {
        cookies: storage({section_data_ids: {cart: 100, customer: 200}, 'mage-cache-sessid': true}),
        sections: storage({cart: {data_id: 100, summary_count: 1}, customer: {data_id: 200, firstname: 'Jane'}}),
        invalidation: storage(),
        local: storage({'mage-cache-timeout': new Date(Date.now() + 3600000).toISOString()})
    };
}

function browser(saved = state(), baseUrl = 'https://shop.test/') {
    const document = {}, handlers = {}, requests = [];
    let sections, customerData;
    const $ = () => ({
        on: (name, callback) => { handlers[name] = callback; },
        trigger: () => {}
    });
    $.Deferred = () => ({resolve: () => {}, promise: () => ({})});
    $.cookieStorage = {...saved.cookies, setConf: () => {}};
    $.localStorage = saved.local;
    $.initNamespaceStorage = name => ({localStorage: name === 'mage-cache-storage' ? saved.sections : saved.invalidation});
    $.getJSON = (url, parameters) => {
        requests.push({url, sections: parameters.sections});
        return {
            fail() { return this; },
            done(callback) { callback({cart: {data_id: 999, summary_count: 3}}); return this; }
        };
    };
    const ko = {
        extenders: {},
        observable: initial => {
            let value = initial;
            return function (next) { if (arguments.length) { value = next; } return value; };
        }
    };
    vm.runInNewContext(fs.readFileSync(path.join(nativeJs, 'section-config.js'), 'utf8'), {
        define: (dependencies, factory) => { sections = factory(_); }
    });
    sections['Magento_Customer/js/section-config']({
        ...sectionSettings, baseUrls: [baseUrl], clientSideSections: [], sectionNames: ['cart', 'customer']
    });
    vm.runInNewContext(fs.readFileSync(path.join(nativeJs, 'customer-data.js'), 'utf8'), {
        document, window: {BASE_URL: baseUrl}, setTimeout,
        define: (dependencies, factory) => {
            customerData = factory($, _, ko, sections, {setBaseUrl: () => {}, build: route => baseUrl + route});
        }
    });
    customerData['Magento_Customer/js/customer-data']({
        cookieLifeTime: 7200, cookieDomain: '.shop.test', isLoggedIn: '0', customerShare: '1',
        expirableSectionLifetime: 60, expirableSectionNames: [], sectionLoadUrl: baseUrl + 'customer/section/load/'
    });
    return {
        saved, requests, customerData,
        submit(method = 'post', route = 'cartreminder/cart/restore') {
            handlers.submit({target: {
                action: baseUrl + route, hasAttribute: name => name === 'method', getAttribute: () => method
            }});
        },
        ajax(method, response = {}) {
            handlers.ajaxComplete({}, {responseJSON: response}, {type: method, url: baseUrl + 'cartreminder/cart/restore'});
        }
    };
}

test('restore form POST invalidates only cart and the redirect reloads its new item count', () => {
    const page = browser();
    page.submit();
    assert.equal(page.saved.sections.get('cart'), undefined);
    assert.equal(page.saved.sections.get('customer').firstname, 'Jane');
    assert.deepEqual(page.saved.cookies.get('section_data_ids'), {cart: 1100, customer: 200});
    assert.equal(page.requests.length, 0, 'the form navigates instead of issuing an extra request');
    const cartPage = browser(page.saved);
    assert.deepEqual(cartPage.requests.map(request => request.sections), ['cart']);
    assert.equal(cartPage.customerData.get('cart')().summary_count, 3);
    assert.equal(cartPage.customerData.get('customer')().firstname, 'Jane');
});

test('GET confirmation leaves stored cart data and cookie versions unchanged', () => {
    const page = browser();
    page.submit('get');
    page.ajax('GET');
    assert.equal(page.saved.sections.get('cart').summary_count, 1);
    assert.deepEqual(page.saved.cookies.get('section_data_ids'), {cart: 100, customer: 200});
    assert.equal(page.requests.length, 0);
});

test('an AJAX restore POST reloads the cart through Magento customer-data', () => {
    const page = browser();
    page.ajax('POST');
    assert.deepEqual(page.requests.map(request => request.sections), ['cart']);
    assert.equal(page.customerData.get('cart')().summary_count, 3);
    assert.equal(page.saved.sections.get('customer').firstname, 'Jane');
});

test('a POST redirect defers its reload until the destination page', () => {
    const page = browser();
    page.ajax('POST', {redirect: 'https://shop.test/checkout/cart'});
    assert.equal(page.requests.length, 0);
    assert.equal(browser(page.saved).customerData.get('cart')().summary_count, 3);
});

test('native matching handles a store base path and index.php without invalidating unrelated POSTs', () => {
    const page = browser(state(), 'https://shop.test/shop/en/');
    page.submit('post', 'unrelated/action');
    assert.equal(page.saved.sections.get('cart').summary_count, 1);
    page.submit('post', 'index.php/cartreminder/cart/restore');
    assert.equal(browser(page.saved, 'https://shop.test/shop/en/').customerData.get('cart')().summary_count, 3);
});
