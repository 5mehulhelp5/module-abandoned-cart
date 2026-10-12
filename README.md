# Abandoned cart reminder

Install in a Magento project with `composer require commercekings/module-abandoned-cart:^0.1` after the first
release is published. Then enable its modules:

```sh
bin/magento module:enable CommerceKings_Consent CommerceKings_AbandonedCart
bin/magento setup:upgrade
bin/magento cache:clean
```

Use the shop's normal DI compilation and static-content deployment process for production.
For development/tests use source checkouts (`--prefer-source`); release archives omit `Test/`.
See [compatibility and verification](COMPATIBILITY.md) and [changes](CHANGELOG.md).

Sends **one** reminder email for a cart that was left untouched, **only with consent for purpose `cart_reminder`** from **`CommerceKings_Consent`** – whatever consent source the shop chose there. Guests and logged-in customers follow the same rules. Cron finds the carts, a RabbitMQ consumer sends the emails.

Depends on **`CommerceKings_Consent` only** – not on any consent source or banner module.

Admin: **Stores → Configuration → CommerceKings → Abandoned Cart** (feature) and **→ Consent** (where consent comes from).

## Flow

1. **Consent** – a recorded consent from `CommerceKings_Consent` (`ConsentInterface::getCurrent('cart_reminder')` on the server, `CommerceKings_Consent/js/consent/provider` in the browser). Recording, validity (withdrawn, expired, banner/terms revision), browser recognition and withdrawals are that module's job; this module keeps only the **consent id**.
2. **Capture** – the consent (and for guests the email) is stored on the cart:
   - guest: typing a valid email in checkout, or entering checkout with an email Magento prefilled → `POST /V1/guest-carts/:cartId/ck-abandoned-email` (`GuestEmailCapture`), 1 s after the last change. Requests are serialized and rapid edits coalesce to the latest email. A save is deduplicated only after the server acknowledges it, including an identical successful retry;
   - guest capture retries network failures, HTTP 408/429/5xx and a refused save with exponential delays, up to five attempts. A new email, consent choice or browser `online` event starts another attempt. Withdrawals are sent before a later save; clearing an invalid or blank email also clears a previously captured guest email;
   - customer: opening checkout (or switching the consent on there) → `POST /cartreminder/consent/capture` (`CustomerConsentCapture`, `js/customer-consent.js`); the email comes from the account. Stored only when the consent changes, so re-opening checkout does not restart the delay.
   - Stored: the consent id and the browser id hash, in one UPDATE that also checks the consent is still valid (`ConsentGuardInterface`) – a capture request still on its way can't undo a withdrawal.
3. **Finder** (cron `commercekings_abandoned_cart_find`, every 15 min) – carts that are active, have items, an email and a **valid** consent (guard condition – raised banner/terms revision or expiry cancels waiting reminders), untouched for **Delay**, not older than **Max age**, not yet queued/reminded, and whose **address got no reminder in the last 7 days** for any cart (`Config::RECIPIENT_COOLDOWN_DAYS`). Each cart is claimed (`ck_abandoned_queued_at`) and its id published to `commercekings.abandoned_cart.remind`.
4. **Consumer** `commerceKingsAbandonedCartRemind` – re-checks everything against the current cart (see *Skips*), claims the cart in one UPDATE that repeats the cart and consent checks (a withdrawal stored before the claim stops the send; one after it finds the reminder already out), then claims the address, sends the email in store emulation. On an error both claims are released and the exception is rethrown (logged); the next finder run queues it again.
5. **Email** – the module ships a **neutral English default** (`view/frontend/email/reminder.html`, "Abandoned Cart Reminder"). Use your own: **Marketing > Email Templates > Add New Template**, load "Abandoned Cart Reminder", change text and design, select it in **Abandoned Cart > Email template**. Variables: `customer_name`, `items_html` (ready-made rows: picture, the store's predefined options only, qty × price), `grand_total`, `restore_url`, `optout_url`, `contact_url`, `store_url`; the footer gets `cart_reminder_email="1"` for a label. Sender defaults to **General Contact** – use a no-reply address.

## Data

| Where | Column | Meaning |
|---|---|---|
| `quote` | `ck_abandoned_queued_at` | queued by the finder (claim) |
| `quote` | `ck_abandoned_reminded_at` | email sent – one reminder per cart |
| `quote` | `ck_abandoned_consent_id` | the consent (`CommerceKings_Consent`, `ck_consent`) – indexed; the evidence lives there |
| `quote` | `ck_abandoned_browser_hash` | the browser id hash – the restore link works only in this browser |
| `ck_abandoned_cart_recipient` | `recipient_hash` (PK), `reminded_at` | who got a reminder (keyed hash of the lowercased address: HMAC-SHA256 with a key derived from `crypt/key` – a copy of the database alone can't be matched against a list of emails; still personal data, the shop holds the key) – one row per address, deleted after the 7-day cooldown. Kept apart from the carts: Magento deletes carts (guest cart merged on login, old-cart cleanup) and a cart's email can be changed or cleared by an opt-out |

Consents and withdrawals live in `CommerceKings_Consent` (`ck_consent`, `ck_consent_withdrawal`). Guests' email goes into Magento's own `quote.customer_email`. All module updates keep `quote.updated_at` unchanged (`updated_at = updated_at`), except the two captures (guest email, customer consent) – storing a consent restarts the delay.

## Consent rules

- A **consent** = one choice in the consent source, recorded by `CommerceKings_Consent` with its own id.
- **Switched off in the consent source** (incl. "reject all", on any page): `CommerceKings_Consent` stores the withdrawal, then fires `commercekings_consent_withdrawn` with the withdrawn consent ids → `Observer\ClearCartsOnConsentWithdrawn` clears **every cart captured with them** – also the abandoned cart of an expired session. On guest checkout the email endpoint is also called with an empty email.
  - guest cart: email **and** consent are cleared;
  - customer cart: only the consent – the email belongs to the account.
- **Opt-out** ("Unsubscribe") withdraws the cart's consent (`ConsentInterface::withdraw($id, 'email')` – with every other active reminder consent of that browser). That browser's old choice is refused (e.g. opted out on the phone, the old "yes" is still on the laptop – also after re-saving the banner for another category); switching the choice mapped to `cart_reminder` on again is a new consent. The address is never blocked.
- **Banner revision raised** (Consent → Banner revision) or **purpose terms revision raised** (`etc/di.xml`, `terms_revision`): every consent given before stops being valid – waiting reminders are not sent; the visitor is asked again.
- Cron `commercekings_abandoned_cart_cleanup` (daily 03:30) deletes recipient history older than 7 days; withdrawals are cleaned by `CommerceKings_Consent`.

## Abuse protection

Anyone can create a guest cart through Magento's REST API, fake the consent cookie and enter any recipient – so the reminder must not be usable for spam or phishing from the shop's domain:

- **No visitor text in the email.** Custom options are shown only for the store's predefined choices (`drop_down`, `radio`, `checkbox`, `multiple`); free text (such as an engraved message), file names and other types are left out. Product and configurable option names come from the catalog.
- **Greeting** uses only the first word of the name, letters/`-`/`'` only, max 20 characters. The default template uses "Hi %name," when a valid name is available and omits the greeting otherwise.
- **One reminder per address per 7 days**, across all carts, kept in `ck_abandoned_cart_recipient` (not on the cart – it survives login merges, cart cleanup, email changes and opt-outs). The finder skips the address (looked up in PHP in one query per page – the key never goes into SQL or query logs; it reads page by page, so carts waiting out a cooldown don't hold up the batch, and queues one cart per address per run); the consumer claims the address atomically before sending (`INSERT … ON DUPLICATE KEY UPDATE`, only one of two parallel consumers wins), releases it if sending fails, and logs "address reminded recently" otherwise.
- Not done (yet): a per-IP rate limit on the guest email endpoint.
- The recorded consent shows what a browser sent; a scripted client can fake it, like with any cookie banner.

## Links in the email

The restore link works 7 days and uses `?q={cart id}&t={signature}`. Its signature (`LinkSignature`) is an HMAC with the store's `crypt/key` over purpose + cart id. The opt-out link comes from Consent's `WithdrawalLinkInterface`, uses `?c={consent id}&t={signature}`, and remains usable while that consent record is retained. Its separate HMAC binds the consent id and website. Neither signature includes the email, so changing a cart or account email does not invalidate it. Magento's masked cart id (the guest cart REST key) never appears in the email. Changing the deployment signing key invalidates existing links.

**Restore** `/cartreminder/cart/restore`:

- invalid/forged signature, old `?c=` links → homepage;
- the browser id (`CommerceKings_Consent`, cookie `ck_consent_browser`) must match the cart's (`ck_abandoned_browser_hash`), and the current store must match the cart's store, else → homepage. Checked before availability, so a stranger cannot tell whether a cart was ordered;
- cart ordered/closed or emptied, or the link is older than 7 days (`Config::RESTORE_LINK_DAYS`, from `ck_abandoned_reminded_at`) → notice "This reminder link is no longer available. You can continue shopping with your current cart." (owner only);
- customer cart → cart page if logged in as that customer, otherwise login page with "Sign in to continue shopping – your cart is waiting for you.";
- guest cart → **GET shows a confirmation page without merging or replacing the session cart**. If it is already the session cart, the visitor goes straight to the cart page;
- confirmation → **POST with a valid Magento form key**. Source and destination quote rows are locked in id order, then freshly loaded. Browser ownership, store, expiry and destination account/active status are checked again;
- guest with no existing cart → the saved cart becomes the session cart. An existing cart, including an empty one or a logged-in customer's cart, keeps its identity and receives the saved products through `Quote::merge`;
- a merge keeps the destination's contact details, account, addresses and coupon. Matching product configurations have their quantities added; distinct configurations remain separate. Totals and shipping rates are recalculated; unavailable products or quantity errors refuse the merge;
- both carts are saved in one database transaction. Only after it commits does the session switch and the saved cart become closed. A failed save rolls back both carts; a repeated or concurrent restore cannot add the closed source twice. Success: "Your saved cart has been restored.";
- after restore/merge the customer-data `cart` section is marked stale (`section_data_ids` cookie), so the mini cart shows the right count.

**Opt-out** `/ckconsent/optout` – Consent owns the one-click link and confirmation page (no confirm button):

- valid link → consent withdrawn, page "Consent withdrawn" (`noindex`) with the purpose-specific confirmation, translated per store locale; the cart withdrawal observer clears active guest email/consent or customer consent;
- already withdrawn → confirmation again, without withdrawing a later explicit consent. Deleting the original cart does not break the link; deleting the consent record, an invalid signature or another website → homepage;
- opened in the owning browser → Consent's `js/withdrawal-banner.js` switches the mapped choice off only if that browser's current choice was withdrawn. Other categories and later explicit choices stay; another device's cookie is untouched. Purposes sharing the same category follow it together;
- old `/cartreminder/optout?q=&t=` links authenticate the legacy signature and redirect to the shared Consent URL using the cart's retained consent id. If that cart or binding has gone, they redirect to the homepage.

## Skips (consumer log: `CommerceKings_AbandonedCart: cart N skipped (…)`)

`cart gone`, `ordered / closed`, `empty`, `no email`, `no consent`, `already reminded`, `consent withdrawn / expired` (also: older banner/terms revision), `address reminded recently`, `disabled`. A claim that loses to a withdrawal at the same moment sends nothing and logs nothing.

## Known behaviour / limitations

- **One reminder per cart, ever**, and at most one per address per 7 days. Adding products after the reminder does not send another.
- **The restore link opens the cart as it is now**, not as it was in the email. Products the customer removed are not brought back (no snapshot); an emptied cart shows "This reminder link is no longer available. You can continue shopping with your current cart."
- **Another device / cleared cookie / expired cookie → homepage.** The link only works in the browser that gave the consent (deliberate: nobody else sees the cart, email or address).
- Visitors who answered the banner **before** the category existed are not asked again unless **Banner revision** (Consent section) is raised; until then the category stays off for them.
- **One-click opt-out:** mail scanners that open every link (mostly Microsoft 365) can opt a recipient out unknowingly. Harmless – they just miss the one reminder.
- Guest carts are deleted by Magento's own `sales_clean_quotes` after "Quote Lifetime" (default 30 days) with their email; the consent record stays in `CommerceKings_Consent` until its own cleanup.
- An ordered cart keeps its email in `sales_order` (needed for the order, unrelated to this consent).
- The email shows the cart at **send** time. Merging recalculates Magento totals; restoring a guest cart into a session with no existing cart adopts it, and Magento's normal cart/checkout flow handles later recalculation.
- Merge rollback covers database changes. External side effects from third-party quote observers cannot be rolled back by this transaction. Concurrent restore requests are tested; custom cart writers and checkout integrations need their own concurrency checks.

## Endpoints

| Endpoint | Who | Purpose |
|---|---|---|
| `POST /rest/V1/guest-carts/:cartId/ck-abandoned-email` (anonymous) | guest checkout | store email + consent, or clear with `""` |
| `POST /cartreminder/consent/capture` (form key) | logged-in customer on checkout | store consent on the session cart |
| `GET /cartreminder/cart/restore?q=&t=` | email | validate ownership and show restore confirmation |
| `POST /cartreminder/cart/restore` (form key, `q`, `t`) | confirmation page | adopt or merge the saved cart |
| `GET /ckconsent/optout?c=&t=` (Consent module) | email | withdraw the consent and show confirmation |
| `GET /cartreminder/optout?q=&t=` | legacy email | redirect to the shared consent link |

## Settings (default scope unless noted)

| Field | Default |
|---|---|
| Enabled (store) | No – switch on after setting the sender and the banner category |
| Send after (minutes untouched) | 180 |
| Ignore carts older than (days) | 7 |
| Email template (store) | `commercekings_abandoned_cart_general_template` |
| Sender (store) | General Contact – set a no-reply address |

## Deploy

```sh
bin/magento module:enable CommerceKings_Consent CommerceKings_ConsentDefaults CommerceKings_ConsentVanilla CommerceKings_AbandonedCart
bin/magento setup:upgrade   # feature tables and consent schema
```

- Define the chosen adapter's categories/services in your site module and map one optional choice to `cart_reminder`. Select that source in Consent settings. The ConsentDefaults preset maps `cart_reminder` to Personalization automatically; sites with explicit categories must add that mapping themselves.
- Check the selected sender in **Stores → Configuration → General → Store Email Addresses**.
- Verify the sender domain's **SPF and DKIM** (and DMARC) for the no-reply address before launch – otherwise reminders land in spam.
- After deploying new code, **restart the resident consumer** (it keeps the old code in memory): stop the running `queue:consumers:start commerceKingsAbandonedCartRemind` process; `cron_consumers_runner` starts it again on the next cron run.
- Consumers run from cron (`cron_consumers_runner`) – no extra process needed. The consumer stays resident waiting for messages (`queue/consumers_wait_for_messages`).
- A custom theme email footer can use `cart_reminder_email` to identify reminder messages.

## Manual testing

- Run the finder now: `bin/magento cron:run` (job `commercekings_abandoned_cart_find`), or set *Send after* to a few minutes on a test shop; send queued reminders with `bin/magento queue:consumers:start commerceKingsAbandonedCartRemind --max-messages=10`.
- Watch `var/log/system.log` for `CommerceKings_AbandonedCart:` lines (queued, sent, skipped with the reason).
- Use a **fresh private window** per scenario (private windows of one browser share cookies while any is open) and a second browser as "another device".
- Simulate a lost session: browser dev tools → Cookies → delete the Magento session cookie (its name can vary), keep the banner cookie and `ck_consent_browser`; clear local/session storage.
- If checkout goes blank after clearing `pub/static`, delete `pub/static/frontend/sri-hashes.json` (stale SRI hash of `requirejs-config.js`).

## Automated tests

From the Magento root, with the package installed:

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart/Test/Unit
node --test vendor/commercekings/module-abandoned-cart/Test/JavaScript/*.test.cjs
```

`Test/Unit` – one test class per PHP class; `DbTestTrait` is a recording fake of the DB adapter (every update/insert/delete with its WHERE conditions). Covered: link signatures, guest + customer capture (incl. the guard condition), withdrawal (guest vs customer, incl. the numeric-key `+` regression that wiped customer emails), legacy opt-out redirects, finder, consumer skips/claim (incl. the guarded claim losing to a withdrawal)/send/release, cleanup cron, email data (signed links, options, escaping), restore (every branch incl. other browser, merge, mini cart cookie), consent endpoints. Shared withdrawal links, confirmation and preference sync are tested in Consent.

JavaScript tests exercise the guest capture coordinator and Magento email-field mixin: acknowledgements, retries, rapid edits, withdrawal during a pending request, consent changes, offline recovery and component cleanup. `customer-consent.js` does not have an isolated unit test. Consent tests its shared withdrawal banner sync separately.

The quote integration tests require a **development database with Magento demo data** (SKUs `24-MB01`, `24-MB04`, `24-MB05` and configurable `MH01`). They create committed quote fixtures, test real Magento saves and remove the fixtures afterward. Use a disposable development database; do not run them against a production shop.

```sh
MAGENTO_ROOT="$PWD" vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php vendor/commercekings/module-abandoned-cart/Test/Integration/CartRestoreTest.php
```

Covered with real quotes: matching quantities, distinct configurable variants, current contact details, source closure, repeated and concurrent requests, ownership, stock limits and rollback after a destination save followed by a source-save failure. The concurrency test launches two PHP workers that begin with cached destination quotes.

The development sandbox also has `tools/abandoned-cart-browser-check.cjs` for native checkout, actual REST capture, consent withdrawal, an injected HTTP 503, local reminder delivery, confirmed restore/merge and unsubscribe. It checks finder eligibility and calls the real consumer for fixture carts; RabbitMQ transport and cron scheduling are separate checks.

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
- the choice mapped to `cart_reminder` off on checkout or another page → guest: email + consent cleared; customer: consent cleared, email kept.
- Only unrelated purpose choices off → nothing changes for the reminder (the cart keeps its consent).
- "Reject all" on checkout and on a product page → cleared (withdraw request 200, not 499).
- On again → a new consent id stored (guest: also when returning to checkout with the prefilled email).
- Banner revision raised → banner asks again; the old consent is invalid but not marked withdrawn; waiting reminders are not sent; accepting again → new consent.

**Sending**
- Cart with consent untouched past the delay → `published: 1`; email from no-reply with items, predefined options only, total, footnote, opt-out, footer label; greeted by a sanitised first name.
- Two carts with the same address → only the first is reminded ("address reminded recently"); no reminder to that address for 7 days – also after an opt-out, an email change, or a guest logging in (Magento deletes the guest cart on that merge).
- No consent / withdrawn or expired after queuing / older revision → consumer skips.
- Second finder run → `published: 0`; ordered carts, carts touched within the delay, carts older than max age → not queued.

**Restore**
- No masked id in the email; forged signature and old `?c=` → homepage.
- Other browser (with or without its own consent) → homepage, nothing added.
- Owner browser: GET shows confirmation and leaves both carts unchanged; confirmation POST restores into a lost session or merges into the current cart; mini cart count correct.
- Matching products add quantities once; distinct configurations remain separate; current email, addresses and coupon survive.
- Repeated/concurrent POSTs do not duplicate products. Invalid form key, wrong store/account, stock errors and save failures leave the current cart intact.
- Owner emptied the cart → "This reminder link is no longer available. You can continue shopping with your current cart."
- Customer cart: logged in → cart page; logged out → login page with notice.

**Opt-out**
- Link → confirmation page; guest cart email + consent cleared, customer cart consent cleared; `ck_consent` row withdrawn via `email`, no email address stored anywhere in the consent tables.
- Same link again → confirmation, with a later explicit consent preserved. Forged signature → homepage.
- Original cart deleted → the new Consent link still withdraws; a legacy quote-based link needs its original cart and consent binding.
- Opted out on phone → laptop's old consent refused, also after changing an unrelated purpose; the choice mapped to `cart_reminder` off+on → stored again; other browser with fresh consent and same email → stored.
- Same browser → banner switches the choice mapped to `cart_reminder` off if its current choice was withdrawn, preserving other categories and later explicit choices.

**Cleanup cron**
- Recipient history older than 7 days deleted, recent kept; job `commercekings_abandoned_cart_cleanup` daily 03:30.
