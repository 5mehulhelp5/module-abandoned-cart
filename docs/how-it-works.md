# How it works

The detailed reference for `commercekings/module-abandoned-cart`. Start with the [README](../README.md).
Sections describe the consent setup unless they say otherwise.

## Flow

1. **Consent** – with the consent bridge, a recorded consent from `CommerceKings_Consent` (Notification's `SubscriptionPermissionInterface` on the server, `CommerceKings_Notification/js/permission` in the browser – both answered by Consent through the bridge). Recording, validity (withdrawn, expired, banner/terms revision), browser recognition and withdrawals are that module's job; this module keeps only the **consent id**.
2. **Subscribe** – the cart is subscribed through `commercekings/module-notification`, type `cart_reminder`: this module's `Model\Notification\CartSubscription` decides who may subscribe which cart; Notification does the email rules, the consent check, the guest contact and the storage:
   - guest: typing a valid email in checkout, or entering checkout with an email Magento prefilled → `POST /V1/ck-notification/subscriptions` `{"type":"cart_reminder","reference":"<masked cart id>","recipient":"…"}` (`js/email-capture-mixin.js`), 1 s after the last change. Requests are serialized and rapid edits coalesce to the latest email. A save is deduplicated only after the server acknowledges it, including an identical successful retry;
   - guest capture retries network failures, HTTP 408/429/5xx and a refused save with exponential delays, up to five attempts. A new email, consent choice or browser `online` event starts another attempt. Withdrawals are sent before a later save; clearing an invalid or blank email also removes the guest's subscription;
   - customer: opening checkout (or switching the consent on there) → `POST /V1/ck-notification/subscriptions/mine` `{"type":"cart_reminder","reference":"<quote id>"}` (`js/customer-consent.js`, Magento's session REST for storefront AJAX); the email comes from the account. A change of consent or browser restarts the delay; re-opening checkout with the same consent does not.
   - Stored by Notification: the cart's subscription (guest contact or customer, the consent id as `permission_ref`, `captured_at`) in one INSERT that also checks the consent is still valid and the cart is still the authorized one (active, same store, same guest or customer) – a request still on its way can't undo a withdrawal. Magento's quote is not written.
3. **Finder** (cron `commercekings_abandoned_cart_find`, every minute) publishes the carts that became due; the queue holds the backlog and the consumers set the pace (see *Flash sales* below). It selects active, nonempty carts with a subscription whose consent is valid, untouched for the configured delay and still within Magento's quote lifetime for their store. `ReminderPolicyInterface` supplies the frequency rule; `OncePerCart` permits only carts never reminded. Each cart is claimed (`ck_abandoned_cart.queued_at`) and published to Notification's shared topic `commercekings.notification.send` as `cart_reminder|<subscription id>`. Recent recipients are skipped, and such a cart is left out of the selection for an hour (`skip_until`), so carts that cannot be sent yet do not take every run's time ahead of newer ones; an atomic recipient claim in the consumer also suppresses parallel same-address deliveries.
4. **Consumer** `commerceKingsNotificationSend` (`commercekings/module-notification`, shared by all notifications) hands the subscription to the `cart_reminder` type (`Model\Notification\CartReminder`) only while it still exists with a valid consent. The type re-checks the policy, store, consent, inactivity delay and quote expiry, then claims the cart atomically and sends in store emulation. Selection and claim share the policy's SQL condition. A failed send restores its prior reminder timestamp only while its delivery version still matches, preserving newer work; the cart is tried again after 1 hour, 4 hours, 1 day and 1 day (`skip_until`, `failed_sends`), then given up. Duplicate messages cannot claim the same state twice.
5. **Email** – the module ships a **neutral English default** (`view/frontend/email/reminder.html`, "Abandoned Cart Reminder"). Use your own: **Marketing > Email Templates > Add New Template**, load "Abandoned Cart Reminder", change text and design, select it in **Abandoned Cart > Email template**. Variables: `customer_name`, `items_html` (ready-made rows: picture, the store's predefined options only, qty × price), `grand_total`, `restore_url`, `optout_url`, `stop_url` (Do not contact link), `contact_url`, `store_url`; the footer gets `cart_reminder_email="1"` for a label. Sender defaults to **General Contact** – use a no-reply address.

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
| `reminded_at` | last reminder sent (`OncePerCart` sends one per cart) |
| `delivery_version` | increasing delivery claim number; prevents an older failure from undoing a newer delivery |
| `skip_until` | the finder skips the cart until then (recipient cooldown, failed send) |
| `failed_sends` | failed send attempts in a row; the cart is given up after four retries |

Each atomic delivery claim increments `delivery_version`, starting from zero. Rollback checks that version before
restoring `reminded_at`, keeps the version and leaves any newer `queued_at` mark untouched.

Consents and withdrawals live in `CommerceKings_Consent` (`ck_consent`, `ck_consent_withdrawal`) without email addresses. A guest's address is stored once as a Notification contact; the module keeps no email history, and Notification removes contacts nothing uses any more. Magento's `quote.customer_email` and `quote.updated_at` are never touched. The reminder delay uses the later of cart activity (`quote.updated_at`) and subscription activity (`captured_at`, guests and customers); quote expiry still uses Magento's cart timestamp. A withdrawal deletes the subscription but keeps `queued_at`/`reminded_at`, so opting in again never causes a second reminder. Every claim is one UPDATE over `ck_abandoned_cart` joined to `quote` and the subscription (`Model\CartTable::joined()`), so cart, consent and send history are still checked in the same statement.

`ReminderEmailResolverInterface::resolve(int $quoteId): ?string` supplies the current recipient to
both queue eligibility and delivery. The default uses the subscribed email for guest carts and
Magento's `customer_email` for customer carts; absent recipients return `null`. Replace it through
DI to choose another source. Finder scans candidate pages so unresolved recipients do not stop
later eligible carts. Delivery compares the subscription it checked in its atomic claim
and resolves again afterward, releasing the claim if the recipient changed. Email updates never
reset `reminded_at`: the default remains one reminder per cart.

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
all carts and stores sharing an email address. **Cooldown (days)** is a global admin
setting, default **7** (**0** turns it off), under CommerceKings > Notifications – it belongs to
`commercekings/module-notification` and is shared with every notification that uses it. Deferred carts
remain unsent and may become eligible after the cooldown if their quote and consent are still valid.

`ck_notification_cooldown` (module-notification) stores Magento-native keyed hashes of normalized email addresses,
claim timestamps and random claim tokens; it contains no raw email. This is suppression data,
not a consent identity. It survives cart deletion and consent withdrawal, so these cannot reset
the send limit; daily cron removes rows older than the current cooldown. Key rotation resets
suppression for addresses hashed with the previous key. Failed sends release only their own
recipient claim; successful or interrupted handoffs retain it conservatively.

## Consent rules

- A **consent** = one choice in the consent source, recorded by `CommerceKings_Consent` with its own id.
- **Switched off in the consent source** (incl. "reject all", on any page): `CommerceKings_Consent` commits withdrawal and pending cleanup together, then invokes the consent bridge's withdrawal handler (`commercekings/module-consent-notification`, registered for the purpose of every Notification subscription type, `cart_reminder` included). It deletes **every subscription with those consent IDs**, including the abandoned cart of an expired session. Failed cleanup remains pending for Consent's cron retry; reminders are stopped as soon as consent is revoked. On guest checkout the subscribe endpoint is also called with an empty email.
  - the subscription (guest contact link and consent) is removed; the send history stays;
  - Magento checkout email is preserved for guests and customers.
  - retries use the original consent IDs, so a later grant/subscription remains untouched;
  - clearing a checkout email only removes that cart's subscription; it does not withdraw browser consent.
- **Unsubscribe link** (opt-in setup) withdraws the cart's consent (`ConsentInterface::withdraw($id, 'email')` – with every other active reminder consent of that browser). That browser's old choice is refused (e.g. opted out on the phone, the old "yes" is still on the laptop – also after re-saving the banner for another category); switching the choice mapped to `cart_reminder` on again is a new consent. The address is never stopped.
- **Source revision raised** (Consent → Source revision) or **purpose terms revision raised** (`etc/di.xml`, `terms_revision`): every consent given before stops being valid – waiting reminders are not sent; the visitor is asked again.

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

- **More consumers send faster with no other change.** Measured in the sandbox (production mode, 14 CPUs, 8 GB):
  ~1,700 emails a minute with one consumer, ~5,800 with four, ~10,600 with eight. Each consumer holds about
  200–250 MB, so size their number by memory. Add consumers before a sale.
- **Messages wait in the broker, not in our table.** Each is a few bytes (`cart_reminder|<subscription id>`).
  A shopper who orders, unsubscribes or changes the cart while its message waits is re-checked at send time and
  skipped; switching the feature off makes waiting messages skip.
- **Maximum reminders per hour** (default 100, 0 = no limit) is the brake for your email provider's rate limit and
  sender reputation, and caps how many addresses a script could make the shop email. A finder
  run publishes only what still fits in the hour (sent, plus queued carts whose subscription is still valid), and every reminder checks it again when
  sending, under a lock (`commercekings_abandoned_cart_hourly`, held only for the count and the claim), so it holds
  even when a delayed backlog drains after a consumer outage. Carts over it wait for a later hour; none are lost.
- Run the consumer with `--max-messages` (e.g. 1,000): Magento's email template rendering grows a worker by about
  0.17 MB per email.

## Queue transport

Reminders go through `CommerceKings_Notification`'s shared consumer `commerceKingsNotificationSend`.
RabbitMQ (`amqp`) and Magento's database queue (`db`) both work; setup, consumer restarts and the cron
allowlist are in [module-notification's operations guide](https://github.com/commercekings/module-notification/blob/main/docs/operations.md#queue-transport).
Keep Magento cron running for cart discovery.

A failed send releases the cart claim. Magento rejects that message; a later finder run
can publish a new one if the cart is still eligible. Failed sends back off and give up the same way on both
transports (see *Consumer* above).

## Skips (consumer log: `CommerceKings_AbandonedCart: cart N skipped (…)`)

`ordered / closed`, `empty`, `no email`, `reminder policy` (already reminded), `quote expired`, `cart recently updated`, `consent withdrawn / expired` (also: older banner/terms revision), `consent purpose / website mismatch`, `disabled`; after a send attempt also Notification's `recipient cooldown` and `recipient changed`. A subscription or cart that no longer exists logs `subscription N skipped (subscription or cart gone)`; Notification's consumer already skips withdrawn subscriptions (`CommerceKings_Notification: cart_reminder N skipped (no valid subscription)`). A claim that loses to a withdrawal at the same moment sends nothing and logs nothing.
