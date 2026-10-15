# Abandoned cart reminder

**Development status:** first-release feature scope frozen; polishing and verification remain before publication.

Install in a Magento project with `composer require commercekings/module-abandoned-cart:^0.1` after the first
release is published. Then enable its modules:

```sh
bin/magento module:enable CommerceKings_Core CommerceKings_Consent CommerceKings_Notification CommerceKings_AbandonedCart
bin/magento setup:upgrade
bin/magento cache:clean
```

Use the shop's normal DI compilation and static-content deployment process for production.
For development/tests use source checkouts (`--prefer-source`); release archives omit `Test/`.
See [compatibility and verification](COMPATIBILITY.md) and [changes](CHANGELOG.md).

Sends **one** reminder email for a cart that was left untouched, **only with consent for purpose `cart_reminder`** from **`CommerceKings_Consent`** – whatever consent source the shop chose there. Guests and logged-in customers follow the same rules. Cron finds the carts; `CommerceKings_Notification`'s shared queue consumer sends the emails through RabbitMQ or Magento's database transport.

Depends on **`CommerceKings_Notification`** for subscriptions (who asked, with which consent) and sending, **`CommerceKings_Consent`** for consent and **`CommerceKings_Core`** for shared admin branding; no dependency on a consent source or banner module.

Admin: **Stores → Configuration → CommerceKings → Abandoned Cart** (feature) and **→ Consent** (where consent comes from).

## Flow

1. **Consent** – a recorded consent from `CommerceKings_Consent` (`ConsentInterface::getCurrent('cart_reminder')` on the server, `CommerceKings_Consent/js/consent/provider` in the browser). Recording, validity (withdrawn, expired, banner/terms revision), browser recognition and withdrawals are that module's job; this module keeps only the **consent id**.
2. **Subscribe** – the cart is subscribed through `commercekings/module-notification`, type `cart_reminder`: this module's `Model\Notification\CartSubscription` decides who may subscribe which cart; Notification does the email rules, the consent check, the guest contact and the storage:
   - guest: typing a valid email in checkout, or entering checkout with an email Magento prefilled → `POST /V1/ck-notification/subscriptions` `{"type":"cart_reminder","reference":"<masked cart id>","email":"…"}` (`js/email-capture-mixin.js`), 1 s after the last change. Requests are serialized and rapid edits coalesce to the latest email. A save is deduplicated only after the server acknowledges it, including an identical successful retry;
   - guest capture retries network failures, HTTP 408/429/5xx and a refused save with exponential delays, up to five attempts. A new email, consent choice or browser `online` event starts another attempt. Withdrawals are sent before a later save; clearing an invalid or blank email also removes the guest's subscription;
   - customer: opening checkout (or switching the consent on there) → `POST /V1/ck-notification/subscriptions/mine` `{"type":"cart_reminder","reference":"<quote id>"}` (`js/customer-consent.js`, Magento's session REST for storefront AJAX); the email comes from the account. A change of consent or browser restarts the delay; re-opening checkout with the same consent does not.
   - Stored by Notification: the cart's subscription (guest contact or customer, the consent id as `permission_ref`, `captured_at`) in one INSERT that also checks the consent is still valid and the cart is still the authorized one (active, same store, same guest or customer) – a request still on its way can't undo a withdrawal. Magento's quote is not written.
3. **Finder** (cron `commercekings_abandoned_cart_find`, every minute) publishes the carts that became due; the queue holds the backlog and the consumers set the pace (see *Flash sales* below). It selects active, nonempty carts with a subscription whose consent is valid, untouched for the configured delay and still within Magento's quote lifetime for their store. `ReminderPolicyInterface` supplies the frequency rule; `OncePerCart` permits only carts never reminded. Each cart is claimed (`ck_abandoned_cart.queued_at`) and published to Notification's shared topic `commercekings.notification.send` as `cart_reminder|<subscription id>`. Recent recipients are skipped; an atomic recipient claim in the consumer also suppresses parallel same-address deliveries.
4. **Consumer** `commerceKingsNotificationSend` (`commercekings/module-notification`, shared by all notifications) hands the subscription to the `cart_reminder` type (`Model\Notification\CartReminder`) only while it still exists with a valid consent. The type re-checks the policy, store, consent, inactivity delay and quote expiry, then claims the cart atomically and sends in store emulation. Selection and claim share the policy's SQL condition. A failed send restores the prior reminder timestamp and clears the queue claim for retry. Duplicate messages cannot claim the same state twice.
5. **Email** – the module ships a **neutral English default** (`view/frontend/email/reminder.html`, "Abandoned Cart Reminder"). Use your own: **Marketing > Email Templates > Add New Template**, load "Abandoned Cart Reminder", change text and design, select it in **Abandoned Cart > Email template**. Variables: `customer_name`, `items_html` (ready-made rows: picture, the store's predefined options only, qty × price), `grand_total`, `restore_url`, `optout_url`, `contact_url`, `store_url`; the footer gets `cart_reminder_email="1"` for a label. Sender defaults to **General Contact** – use a no-reply address.

## Delivery policy and quote lifetime

The default policy sends one reminder per cart. Replace
`CommerceKings\AbandonedCart\Api\ReminderPolicyInterface` through a DI preference to supply another
frequency rule. `getDescription()` supplies its read-only admin frequency preview.
`canSend(ReminderState)` and `dueCondition(ReminderQuery)` must agree;
the finder, stale queue release and atomic delivery claim all use the policy. Consent, website,
quote expiry, abandonment delay, recipient cooldown and cart-state checks remain mandatory. Future recurring policies
also need to consider delivery history and per-email restore-link expiry; no recurrence ships here.

The policy contract is finalized before its first public release. `ReminderState` contains quote id,
store id and the previous reminder timestamp, plus optional attributes (currently the quote's update
time). `ReminderQuery` supplies the store id, `quoteId()` – the cart's id, present in every selection and
claim – and `column()` for the send-history columns `queued_at` and `reminded_at` (`ReminderQuery::COLUMNS`;
NULL while a cart was never queued). Any other column is refused when the condition is built. A future policy
can use the quote id to read its own delivery-history repository in PHP and reference `quoteId()` in a SQL
subquery, without changing this interface. The SQL regressions run the frozen compatibility fixture and such a
history-backed policy through the real finder and claim statements.

After publication, policy method signatures and existing context fields keep their meaning. Additive
optional state is allowed; required constructor parameters, renamed fields or new required interface
methods are breaking changes. Existing policies ignore new attributes. A new policy requiring data
that is unavailable must refuse delivery or read it from its repository. A fundamentally different
contract belongs in a separate interface with an adapter for existing policies.
`CompatibleReminderPolicy` is a frozen third-party test fixture: it must continue to compile and
accept the documented named context arguments when the package evolves.

Magento owns retention through `checkout/cart/delete_quote_after` (normally 30 days). This module
reads the effective value for each quote's store and displays it read-only in its admin section.
There is no separate maximum-age setting; existing `max_age_days` values are ignored. A zero or
negative lifetime permits no reminders. The module conservatively stops at `updated_at + lifetime`;
Magento's daily cleanup uses a calendar-date cutoff, so physical deletion can occur later.
Queue, delivery and withdrawal writes preserve `updated_at` and do not extend quote retention.

Restore access stops at the earlier of seven days after sending and the current configured quote
expiry. A reminder sent near expiry can therefore have a shorter restore window. Changing Magento's
quote lifetime affects pending delivery and restore checks; this module never extends retention.

Each cart can receive one reminder. A separate recipient cooldown also limits sends across
all carts and stores sharing an email address. **Email cooldown (days)** is a global admin
setting, default **7** (**0** turns it off), under CommerceKings > Notifications – it belongs to
`commercekings/module-notification` and is shared with every notification that uses it. Deferred carts
remain unsent and may become eligible after the cooldown if their quote and consent are still valid.

`ck_notification_cooldown` (module-notification) stores Magento-native keyed hashes of normalized email addresses,
claim timestamps and random claim tokens; it contains no raw email. This is suppression data,
not a consent identity. It survives cart deletion and consent withdrawal, so these cannot reset
the send limit; daily cron removes rows older than the current cooldown. Key rotation resets
suppression for addresses hashed with the previous key. Failed sends release only their own
recipient claim; successful or interrupted handoffs retain it conservatively.

## Data

Magento's `quote` table is not changed or written. Who subscribed a cart, with which consent, is its
`commercekings/module-notification` subscription (`ck_notification_subscription`, type `cart_reminder`,
reference = quote id): the guest contact (`ck_notification_contact`) or the customer, the consent id (`permission_ref`)
(the restore link works only in the browser that consent was given in) and `captured_at` (last change; identical retries preserve it).
This module keeps only the send history in `ck_abandoned_cart` (one row per cart, created when it is first queued,
deleted with the quote by Magento's cart cleanup):

| Column | Meaning |
|---|---|
| `quote_id` | the cart (`quote.entity_id`, foreign key, `ON DELETE CASCADE`) |
| `queued_at` | waiting in the queue; cleared when the reminder is sent or skipped, so the cart can be found again |
| `reminded_at` | email sent – one reminder per cart |

Consents and withdrawals live in `CommerceKings_Consent` (`ck_consent`, `ck_consent_withdrawal`) without email addresses. A guest's address is stored once as a Notification contact; the module keeps no email history, and Notification removes contacts nothing uses any more. Magento's `quote.customer_email` and `quote.updated_at` are never touched. The reminder delay uses the later of cart activity (`quote.updated_at`) and subscription activity (`captured_at`, guests and customers); quote expiry still uses Magento's cart timestamp. A withdrawal deletes the subscription but keeps `queued_at`/`reminded_at`, so opting in again never causes a second reminder. Every claim is one UPDATE over `ck_abandoned_cart` joined to `quote` and the subscription (`Model\CartTable::joined()`), so cart, consent and send history are still checked in the same statement.

`ReminderEmailResolverInterface::resolve(int $quoteId): ?string` supplies the current recipient to
both queue eligibility and delivery. The default uses the subscribed email for guest carts and
Magento's `customer_email` for customer carts; absent recipients return `null`. Replace it through
DI to choose another source. Finder scans candidate pages so unresolved recipients do not block
later eligible carts. Delivery compares the subscription it checked in its atomic claim
and resolves again afterward, releasing the claim if the recipient changed. Email updates never
reset `reminded_at`: the default remains one reminder per cart.

Carts subscribed before this version need checkout capture again; nothing is migrated before the first release.

## Consent rules

- A **consent** = one choice in the consent source, recorded by `CommerceKings_Consent` with its own id.
- **Switched off in the consent source** (incl. "reject all", on any page): `CommerceKings_Consent` commits withdrawal and pending cleanup together, then invokes the consent bridge's withdrawal handler (`commercekings/module-consent-notification`, registered for the purpose of every Notification subscription type, `cart_reminder` included). It deletes **every subscription with those consent IDs**, including the abandoned cart of an expired session. Failed cleanup remains pending for Consent's cron retry; reminders are blocked as soon as consent is revoked. On guest checkout the subscribe endpoint is also called with an empty email.
  - the subscription (guest contact link and consent) is removed; the send history stays;
  - Magento checkout email is preserved for guests and customers.
  - retries use the original consent IDs, so a later grant/subscription remains untouched;
  - clearing a checkout email only removes that cart's subscription; it does not withdraw browser consent.
- **Opt-out** ("Unsubscribe") withdraws the cart's consent (`ConsentInterface::withdraw($id, 'email')` – with every other active reminder consent of that browser). That browser's old choice is refused (e.g. opted out on the phone, the old "yes" is still on the laptop – also after re-saving the banner for another category); switching the choice mapped to `cart_reminder` on again is a new consent. The address is never blocked.
- **Source revision raised** (Consent → Source revision) or **purpose terms revision raised** (`etc/di.xml`, `terms_revision`): every consent given before stops being valid – waiting reminders are not sent; the visitor is asked again.

## Abuse protection

Email content restricts visitor-controlled values and uses the shop's catalog data:

- **No visitor text in the email.** Custom options are shown only for the store's predefined choices (`drop_down`, `radio`, `checkbox`, `multiple`); free text (such as an engraved message), file names and other types are left out. Product and configurable option names come from the catalog.
- **Greeting** uses only the first word of the name, letters/`-`/`'` only, max 20 characters. The default template uses "Hi %name," when a valid name is available and omits the greeting otherwise.
- The guest subscribe endpoint has no rate limit in code: add one in nginx or your proxy – see module-notification's README, *Rate limiting*.
- The recorded consent shows what a browser sent; a scripted client can fake it, like with any cookie banner.

## Links in the email

The restore link works for up to 7 days after sending, capped by Magento's configured quote lifetime from the cart's last update, and uses `?q={cart id}&t={signature}`. Its signature (`LinkSignature`) uses Magento's `EncryptorInterface::hash()` over purpose + cart id, retaining the existing 32-hex token format. Magento supplies HMAC-SHA256 and selects/decodes its current deployment key. The opt-out link (by default – see *Custom unsubscribe link* below) comes from Consent's `WithdrawalLinkInterface`, uses `?c={consent id}&t={signature}`, and remains usable while that consent record is retained. Its separate HMAC binds the consent id and website. Neither signature includes the email, so changing a cart or account email does not invalidate it. Magento's masked cart id (the guest cart REST key) never appears in the email. Changing the deployment signing key invalidates existing links.

Generation and verification use only Magento's native signer, with no legacy raw-key fallback.
Older development links can stop working when their raw-key signatures differ from Magento's
current-key signatures. Use a fresh cart to generate a reminder when testing this change. Comparison uses
`hash_equals`; password-hash formats and unkeyed hashes cannot authorize recovery.

**Restore** `/cartreminder/cart/restore` – only when the subscription's permission can identify the visitor
(`identifiesVisitor()`, true with Consent). Otherwise `restore_url` is Magento's cart page (`checkout/cart`): the
visitor's own session or account cart, with no restore or merge.

Restore steps:

- invalid/forged signature or changed quote id → homepage before any quote lookup;
- link generation requires a nonempty string deployment `crypt/key`; missing, blank or invalid keys stop generation and signature validation rejects safely;
- the visitor must be the one who gave the subscription's permission – Notification's `SubscriptionPermissionInterface::isCurrentVisitor()`; with Consent: the browser id (cookie `ck_consent_browser`) must match the browser the consent was given in – and the current store must match the cart's store, else → homepage. Checked before availability, so a stranger cannot tell whether a cart was ordered;
- cart ordered/closed, emptied or expired under Magento's quote lifetime, or the link is older than 7 days (`Config::RESTORE_LINK_DAYS`, from `reminded_at`) → notice "This reminder link is no longer available. You can continue shopping with your current cart." (owner only);
- customer cart → cart page if logged in as that customer, otherwise login page with "Sign in to continue shopping – your cart is waiting for you.";
- guest cart → **GET shows a confirmation page without merging or replacing the session cart**. If it is already the session cart, the visitor goes straight to the cart page;
- confirmation → **POST with a valid Magento form key**. Source and destination quote rows are locked in id order, then freshly loaded. Browser ownership, store, expiry and destination account/active status are checked again;
- guest with no existing cart → the saved cart becomes the session cart. An existing cart, including an empty one or a logged-in customer's cart, keeps its identity and receives the saved products through `Quote::merge`;
- a merge keeps the destination's contact details, account, addresses and coupon. Matching product configurations have their quantities added; distinct configurations remain separate. Totals and shipping rates are recalculated. Like Magento's own merge when a guest signs in, unavailable products and quantities above stock do not refuse the merge: they show as cart errors and checkout refuses the order until the shopper fixes them;
- both carts are saved in one database transaction. Only after it commits does the session switch and the saved cart become closed. A failed save rolls back both carts; a repeated or concurrent restore cannot add the closed source twice. Success: "Your saved cart has been restored.";
- after restore/merge the customer-data `cart` section is marked stale (`section_data_ids` cookie), so the mini cart shows the right count.
- that customer-data cookie uses Magento's configured cookie lifetime and domain (zero lifetime creates a session cookie). Its path stays `/`, matching Magento's customer-data JavaScript even when the session cookie has a narrower path.

**Opt-out** `/ckconsent/optout` – Consent owns the signed link and explicit confirmation:

- valid GET → read-only confirmation form; consent, captured email and banner preferences stay unchanged until the visitor submits its form-key-protected POST. After confirmation, Notification's handler removes the cart's subscription while preserving Magento checkout email, with durable retry if cleanup fails;
- already withdrawn → confirmation again, without withdrawing a later explicit consent. Deleting the original cart does not break the link; deleting the consent record, an invalid signature or another website → homepage;
- confirmed in the owning browser → Consent's `js/withdrawal-banner.js` switches the mapped choice off only if that browser's current choice was withdrawn. GET never synchronizes preferences; an already-withdrawn choice can be applied explicitly with the form. Another browser sees a generic question, then redirects home after submitting. Other categories and later explicit choices stay; another device's cookie is untouched. Purposes sharing the same category follow it together;

**Custom unsubscribe link** – `optout_url` (and the one-click header) comes from
`CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface::getUrl(UnsubscribeContext $context)`. The default,
`Model\ConsentUnsubscribeLink`, returns Consent's signed link above. Replace it with a DI preference to use your
own page or an external preference center:

```xml
<preference for="CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface" type="Vendor\Module\Model\MyUnsubscribeLink"/>
```

`UnsubscribeContext` has `permissionRef` (the subscription's permission reference – the consent id with the consent
bridge, '' without it) and `storeId`, and `getAttribute($name)` for the rest: `quote_id`, `customer_id` (null for
guests), `subscription_id` and `email`; an unknown name returns null, so attributes can be added without breaking
implementations. Your link must be unguessable (signed or a random token, never a plain id or email), accept a
one-click `POST` if Notification's *One-click unsubscribe* is on, and revoke the subscription – with the consent
bridge `ConsentInterface::withdraw($permissionRef, 'email')`, without it Notification's
`SubscriptionRemovalInterface::remove($subscriptionId)` – that is what stops reminders.

## Skips (consumer log: `CommerceKings_AbandonedCart: cart N skipped (…)`)

`ordered / closed`, `empty`, `no email`, `reminder policy` (already reminded), `quote expired`, `cart recently updated`, `consent withdrawn / expired` (also: older banner/terms revision), `consent purpose / website mismatch`, `disabled`; after a send attempt also Notification's `recipient cooldown` and `recipient changed`. A subscription or cart that no longer exists logs `subscription N skipped (subscription or cart gone)`; Notification's consumer already skips withdrawn subscriptions (`CommerceKings_Notification: cart_reminder N skipped (no valid subscription)`). A claim that loses to a withdrawal at the same moment sends nothing and logs nothing.

## Known behaviour / limitations

- **One reminder per cart, ever**, with the default `OncePerCart` policy. Distinct carts using the same email address also respect recipient cooldown. Adding products after the reminder does not send another.
- **The restore link opens the cart as it is now**, not as it was in the email. Products the customer removed are not brought back (no snapshot); an emptied cart shows "This reminder link is no longer available. You can continue shopping with your current cart."
- **Another device / cleared cookie / expired cookie → homepage.** The link only works in the browser that gave the consent (deliberate: nobody else sees the cart, email or address).
- Visitors who answered the banner **before** the category existed are not asked again unless **Source revision** (Consent section) is raised; until then the category stays off for them.
- **Scanner-safe opt-out:** GET/HEAD do not withdraw or change banner preferences; the visitor must explicitly submit the confirmation form. Notification's optional *One-click unsubscribe* (RFC 8058, per website) adds mail-client unsubscribe headers alongside the regular confirmation link. The header and visible link use the same signed URL and withdrawal cleanup. The sending provider must DKIM-sign both headers. Header insertion uses Magento's native Symfony mail API (verified on 2.4.9); an unsupported custom or legacy mail backend fails before sending when this option is enabled.
- **Delivery is not atomic with SMTP.** The reminder timestamp is claimed before handoff. A hard worker interruption after claiming can leave a cart marked reminded even if nothing was sent; it is not automatically retried. Caught send failures release the claim, but an SMTP exception may itself be ambiguous. Review transport/provider evidence before manually retrying an uncertain attempt. Once `sendMessage()` returns successfully, a later emulation cleanup error keeps the claim to prevent a duplicate. The timestamp is not proof of inbox delivery.
- Guest carts are deleted by Magento's own `sales_clean_quotes` after "Quote Lifetime" (default 30 days). Their send history goes with them; Notification's daily cleanup then removes the cart's subscription (`CartSubscription::orphanCondition()`) and, after its grace period, the guest's address. The consent record stays in `CommerceKings_Consent` until its own cleanup.
- An ordered cart keeps its email in `sales_order` (needed for the order, unrelated to this consent).
- The email shows the cart at **send** time. Merging recalculates Magento totals; restoring a guest cart into a session with no existing cart adopts it, and Magento's normal cart/checkout flow handles later recalculation.
- Merge rollback covers database changes. External side effects from third-party quote observers cannot be rolled back by this transaction. Concurrent restore requests are tested; custom cart writers and checkout integrations need their own concurrency checks.

## Endpoint safety

Subscribing authorizes an active guest/customer cart on the current website (`CartSubscription::authorize()`:
masked cart id for guests, own quote id for customers) before Notification reads consent or writes anything. It
uses the cart's store configuration; Notification requires the reminder purpose and the same consent website, and
repeats the authorized cart condition (active, store, guest/customer) and the consent guard in its atomic INSERT,
retry acknowledgement and guest unsubscribe. The consumer rejects a wrong-purpose or wrong-website consent before
claiming a cart.

Storefront POST actions require a valid form key even for AJAX. Malformed form keys are
rejected before Magento's string comparison; malformed purpose/link parameters and source
cookies are refused without server errors. Signed withdrawal HEAD requests have no effects. The customer
subscribe endpoint uses Magento's `self` resource: the storefront session only for AJAX requests, or a customer
token; the customer id is never taken from the request body.

## Endpoints

| Endpoint | Who | Purpose |
|---|---|---|
| `POST /rest/V1/ck-notification/subscriptions` (anonymous, Notification) | guest checkout | subscribe the cart with email + consent, or unsubscribe with `""` |
| `POST /rest/V1/ck-notification/subscriptions/mine` (`self`, Notification) | logged-in customer on checkout | subscribe their own cart |
| `GET /cartreminder/cart/restore?q=&t=` | email | validate ownership and show restore confirmation |
| `POST /cartreminder/cart/restore` (form key, `q`, `t`) | confirmation page | adopt or merge the saved cart |
| `GET /ckconsent/optout?c=&t=` (Consent module) | email | show read-only withdrawal confirmation |
| `POST /ckconsent/optout` (form key, `c`, `t`) | confirmation form | withdraw consent and apply owned-browser preferences |

Guest capture sends `{"type":"cart_reminder","reference":"<masked cart id>","email":"visitor@example.test"}`;
an explicit `"email":""` unsubscribes. Notification validates the email (missing, non-string, invalid format
or over 255 characters → Magento's standard 400 input error, without echoing the address) before the cart is
authorized, so invalid input leaves an existing subscription intact. See module-notification's README.

## Settings (default scope unless noted)

Other settings are hidden while **Enabled** is **No**.

| Field | Default |
|---|---|
| Enabled (store) | No – switch on after setting the sender and the banner category |
| Inactivity delay (minutes) | 180 |
| Maximum reminders per hour (global) | 0 = no limit – set it to your email provider's sending limit |
| Cart lifetime (days, read-only, selected scope) | Inherited from Sales > Checkout > Shopping Cart; normally 30 days |
| Reminders per cart (read-only) | `1 (one-time)`, reported by the delivery policy |
| Email template (store) | `commercekings_abandoned_cart_general_template` |
| Sender (store) | General Contact – set a no-reply address |

## Deploy

```sh
bin/magento module:enable CommerceKings_Core CommerceKings_Consent CommerceKings_ConsentDefaults CommerceKings_ConsentVanilla CommerceKings_Notification CommerceKings_AbandonedCart
bin/magento setup:upgrade   # feature tables and consent schema
```

- Define the chosen adapter's categories/services in your site module and map one optional choice to `cart_reminder`. Select that source in Consent settings. The ConsentDefaults preset maps `cart_reminder` to Personalization automatically; sites with explicit categories must add that mapping themselves.
- Check the selected sender in **Stores → Configuration → General → Store Email Addresses**.
- Verify the sender domain's **SPF and DKIM** (and DMARC) for the no-reply address before launch – otherwise reminders land in spam.
- After deploying new code, **restart the resident notification consumer** (see Queue transport).
- A custom theme email footer can use `cart_reminder_email` to identify reminder messages.

## Flash sales

The queue is the queue: the finder runs every minute and publishes every cart that became due, the message broker
(RabbitMQ or Magento's MySQL queue) holds the backlog, and the consumers send at their pace. A run reads carts in
pages of 200 for at most 30 seconds (checked for every candidate and before every claim) and publishes as it goes;
the next run continues. Pages use the cart's activity time and id as a cursor, so claiming carts never skips one.

`queued_at` is the only bookkeeping: set in the same statement that checks the cart is still due, so no run – also
not two at once – publishes a cart twice. It means "waiting in the queue": cleared when the reminder is sent (so a
custom `ReminderPolicyInterface` can schedule the next one), when the reminder skips the cart, and when the publish
fails, so the cart can be found again. Each cart has at most one message in flight. A cart marked for more than 24 hours without a
reminder (a consumer crashed holding its message) is found again. A duplicate message is harmless: the reminder's
own claim, checked against the cart and its consent at send time, lets one delivery send.

- **More consumers send faster with no other change.** Measured in the sandbox (developer mode): ~190 emails a
  minute per consumer, ~1,230 with eight; production renders faster. Add consumers before a sale.
- **Messages wait in the broker, not in our table.** Each is a few bytes (`cart_reminder|<subscription id>`).
  A shopper who orders, unsubscribes or changes the cart while its message waits is re-checked at send time and
  skipped; switching the feature off makes waiting messages skip.
- **Maximum reminders per hour** is the brake for your email provider's rate limit and sender reputation. A finder
  run publishes only what still fits in the hour (sent, plus queued carts whose subscription is still valid), and every reminder checks it again when
  sending, under a lock (`commercekings_abandoned_cart_hourly`, held only for the count and the claim), so it holds
  even when a delayed backlog drains after a consumer outage. Carts over it wait for a later hour; none are lost.
- Run the consumer with `--max-messages` (e.g. 1,000): Magento's email template rendering grows a worker by about
  0.17 MB per email.

## Queue transport

Reminders go through `CommerceKings_Notification`'s shared consumer `commerceKingsNotificationSend`.
RabbitMQ (`amqp`) and Magento's database queue (`db`) both work; setup, consumer restarts and the cron
allowlist are in [module-notification's README](https://github.com/commercekings/module-notification#queue-transport).
Keep Magento cron running for cart discovery.

A failed send releases the cart claim. Magento rejects that message; a later finder run
can publish a new one if the cart is still eligible. This is the same policy on both
transports; switching to SQL does not add a per-cart retry limit or delivery backoff.

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
node --test vendor/commercekings/module-abandoned-cart/Test/JavaScript/*.test.cjs
```

The reminder policy SQL regressions (no demo products or actual email delivery required):

```sh
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  vendor/commercekings/module-abandoned-cart/Test/Integration/ReminderPolicyTest.php
```

They verify recipient cooldown, same-address concurrency, expiry, owned release, failed-send retry, duplicate claims, store-specific lifetime,
late queue delivery, read-only admin scope and restore expiry before physical quote deletion.

`Test/Unit` uses `DbTestTrait`, a recording fake of the DB adapter (every update/insert/delete with its WHERE conditions). Covered: link signatures, cart subscription authorization (guest masked id, customer's own quote, the repeated cart condition), reminder policy, quote lifetime, finder (subscription ids, guarded queue claim), consumer skips/claim (incl. the guarded claim losing to a withdrawal)/send/release, email data (signed links, options, escaping), restore (every branch incl. other browser, merge, mini cart cookie). Email rules, consent, subscription storage and withdrawal are tested in Notification; shared withdrawal links, confirmation and preference sync in Consent.

JavaScript tests exercise the guest capture coordinator and Magento email-field mixin: acknowledgements, retries, rapid edits, withdrawal during a pending request, consent changes, offline recovery and component cleanup. `customer-consent.js` does not have an isolated unit test. Consent tests its shared withdrawal banner sync separately.

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

The development sandbox also has `tools/abandoned-cart-browser-check.cjs` for native checkout, actual REST capture, consent withdrawal, an injected HTTP 503, local reminder delivery, confirmed restore/merge and unsubscribe. It runs the production finder, Magento's native publisher and consumer, and a run-specific queue using either `amqp` or `db`. The 30 browser scenarios also cover dedicated recipient updates, authenticated checkout, same-address cooldown and deferred carts, variant images, expiry, queued withdrawal and a failed send followed by successful retry. Cron scheduling is checked separately.

From the sandbox root:

```sh
bash tools/verify-abandoned-cart-e2e.sh --transport=db
bash tools/verify-abandoned-cart-e2e.sh --browser-only --transport=amqp
```

The fixture selects transport only inside its CLI processes; it keeps the shop's deployment
default and shared queues unchanged. SQL runs verify native acknowledgement/rejection states
and remove their own queue, message and status rows during cleanup.

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
