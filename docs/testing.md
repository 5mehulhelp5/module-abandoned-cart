# Testing

Manual checks, automated suites and the scenario list of `commercekings/module-abandoned-cart`.
Start with the [README](../README.md).

## Manual testing

- Run the finder now: `bin/magento cron:run` (job `commercekings_abandoned_cart_find`), or set *Inactivity delay (minutes)* to a few minutes on a test shop; send queued reminders with `bin/magento queue:consumers:start commerceKingsNotificationSend --max-messages=10`.
- Watch `var/log/system.log` for `CommerceKings_AbandonedCart:` lines (queued, sent, skipped with the reason).
- Use a **fresh private window** per scenario (private windows of one browser share cookies while any is open) and a second browser as "another device".
- Simulate a lost session: browser dev tools → Cookies → delete the Magento session cookie (its name can vary), keep the banner cookie and `ck_consent_browser`; clear local/session storage.
- If checkout goes blank after clearing `pub/static`, delete `pub/static/frontend/sri-hashes.json` (stale SRI hash of `requirejs-config.js`).

## Automated tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart/Test/Unit
MAGENTO_ROOT="$PWD" node --test vendor/commercekings/module-abandoned-cart/Test/JavaScript/*.test.cjs
```

The reminder policy SQL regressions (no demo products or actual email delivery required):

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  vendor/commercekings/module-abandoned-cart/Test/Integration/ReminderPolicyTest.php
```

They verify recipient cooldown, same-address concurrency, expiry, owned release, failed-send retry, duplicate claims, store-specific lifetime,
late queue delivery, read-only admin scope and restore expiry before physical quote deletion.

`Test/Unit` uses `DbTestTrait`, a recording fake of the DB adapter (every update/insert/delete with its WHERE conditions). Covered: link signatures, cart subscription authorization (guest masked id, customer's own quote, the repeated cart condition), reminder policy, quote lifetime, finder (subscription ids, guarded queue claim), consumer skips/claim (incl. the guarded claim losing to a withdrawal)/send/release, fresh quote loading on retries, email data (signed links, options, escaping), and restore (every branch incl. other browser and merge). Email rules, consent, subscription storage and withdrawal are tested in Notification; shared withdrawal links, confirmation and preference sync in Consent.

JavaScript tests exercise guest capture, the Magento email-field mixin and customer checkout subscription:
acknowledgements, retries, rapid edits, withdrawal during a pending request, consent changes, offline recovery
and component cleanup. Consent tests its shared withdrawal banner sync separately.

Customer-data regressions use Magento's real XML converter and frontend JavaScript to verify form/AJAX
invalidation, reload after redirect, unchanged GET confirmation, preserved customer data and store base paths.
Set `MAGENTO_ROOT` for these tests; no running storefront or database is needed.

The quote integration tests require a **development database with Magento demo data** (SKUs `24-MB01`, `24-MB04`, `24-MB05` and configurable `MH01`). They create committed quote fixtures, test real Magento saves and remove the fixtures afterward. Use a disposable development database; do not run them against a production shop.

```sh
MAGENTO_ROOT="$PWD" vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart/Test/Integration/CartRestoreTest.php
```

`CartSubscriptionTest.php` adds ten real SQL regressions through Notification's subscribe service with this
module's `CartSubscription`, empty quotes and a temporary second website: cross-website subscribe/unsubscribe,
allowed subscribe/retry/unsubscribe, a disabled store, a store change after authorization, wrong consent scope,
refused-write acknowledgements and a customer's own cart. It also checks guest/customer recipient selection,
separate subscription activity, identical retries, preserved Magento checkout data, unchanged send history and the
registered withdrawal handler. It removes its quotes, customers and website/store/group fixtures afterward and needs
no demo products:

```sh
MAGENTO_ROOT="$PWD" vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart/Test/Integration/CartSubscriptionTest.php
```

Covered with real quotes: matching quantities, distinct configurable variants, current contact details, source closure, repeated and concurrent requests, ownership, a merged quantity above stock (merged with Magento's cart error) and rollback after a destination save followed by a source-save failure. The concurrency test launches two PHP workers that begin with cached destination quotes.
Flash-sale scenarios with real stock (MSI), price, status and coupons – on one product the test sets and restores:
the last unit restored by two shoppers (adopt and merge) and then sold once – the second checkout is refused for stock,
no oversell; a product sold out before the click (restored with Magento's cart error, nothing lost); a price change
(today's price, not the email's); a disabled product (restore completes, the item is dropped or flagged); the
current cart's coupon kept. A control places a real guest order with stock and removes it and its reservation.

The development sandbox also has `tools/abandoned-cart-browser-check.cjs` for native checkout, actual REST capture, consent withdrawal, an injected HTTP 503, local reminder delivery, confirmed restore/merge and unsubscribe. It runs the production finder, Magento's native publisher and consumer, and a run-specific queue using either `amqp` or `db`. The 30 browser scenarios also cover dedicated recipient updates, authenticated checkout, same-address cooldown and deferred carts, variant images, expiry, queued withdrawal and a failed send followed by successful retry. Cron scheduling is checked separately.

From the sandbox root:

```sh
bash tools/verify-abandoned-cart-e2e.sh --transport=db
bash tools/verify-abandoned-cart-e2e.sh --browser-only --transport=amqp
```

The fixture selects transport only inside its CLI processes; it keeps the shop's deployment
default and shared queues unchanged. SQL runs verify native acknowledgement/rejection states
and remove their own queue, message and status rows during cleanup.

## Flash sale tests

Local sandbox, Magento 2.4.9 in production mode, 14 CPUs / 8 GB, RabbitMQ: 12 consumers sent 13,333 reminder emails
a minute, every cart exactly once, with no email after a withdrawal and no deadlocks. Checkout subscribe and
unsubscribe held p95 under 80 ms at 50 requests a second, with zero errors.

## Test cases

**Consent capture**
- Banner shows the site-defined optional choice mapped to `cart_reminder`, off by default.
- Guest rejects cookies, types email → nothing stored.
- Guest accepts, types email → email + consent id stored (`/ckconsent/grant` recorded the consent first).
- Customer without consent opens checkout → nothing stored.
- Customer with consent opens checkout → consent stored, account email kept; re-opening checkout changes nothing.
- Customer switches the choice mapped to `cart_reminder` on while on checkout → consent stored.
- Direct API call without the cookie / without the category → nothing stored.

**Withdrawing**
- the choice mapped to `cart_reminder` off on checkout or another page → the cart's subscription (guest reminder email, consent) is removed; Magento checkout email is kept for every cart.
- Only unrelated purpose choices off → nothing changes for the reminder (the cart keeps its consent).
- "Reject all" on checkout and on a product page → cleared (withdraw request 200, not 499).
- On again → a new consent id stored (guest: also when returning to checkout with the prefilled email).
- Source revision raised → banner asks again; the old consent is invalid but not marked withdrawn; waiting reminders are not sent; accepting again → new consent.

**Sending**
- Cart with consent untouched past the delay → `published: 1`; email from no-reply with items, predefined options only, total, footnote, opt-out, footer label; greeted by a sanitised first name.
- Two carts with the same address → one sends during the recipient cooldown; the other waits and may receive its first reminder afterward. Duplicate deliveries do not send again.
- No consent / withdrawn or expired after queuing / older revision → consumer skips.
- Second finder run → `published: 0`; ordered carts, carts touched within the delay and carts expired under their store's Magento quote lifetime are not queued. The consumer repeats the delay and expiry checks after queuing.

**Restore**
- No masked id in the email; forged signature and old `?c=` → homepage.
- Other browser (with or without its own consent) → homepage, nothing added.
- Owner browser: GET shows confirmation and leaves both carts unchanged; confirmation POST restores into a lost session or merges into the current cart; mini cart count correct.
- Matching products add quantities once; distinct configurations remain separate; current email, addresses and coupon survive.
- Repeated/concurrent POSTs do not duplicate products. Invalid form key, wrong store/account and save failures leave the current cart intact; stock problems merge with Magento's cart errors, as on sign-in.
- Owner emptied the cart → "This reminder link is no longer available. You can continue shopping with your current cart."
- Customer cart: logged in → cart page; logged out → login page with notice.

**Opt-out**
- Link → confirmation page; the cart's subscription (guest reminder email + consent) removed, Magento checkout email preserved; `ck_consent` row withdrawn via `email`, no email address stored anywhere in the consent tables.
- Same link again → confirmation, with a later explicit consent preserved. Forged signature → homepage.
- Original cart deleted → the Consent link still withdraws.
- Opted out on phone → laptop's old consent refused, also after changing an unrelated purpose; the choice mapped to `cart_reminder` off+on → stored again; other browser with fresh consent and same email → stored.
- Same browser → banner switches the choice mapped to `cart_reminder` off if its current choice was withdrawn, preserving other categories and later explicit choices.
