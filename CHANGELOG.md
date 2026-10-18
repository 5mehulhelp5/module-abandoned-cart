# Changelog

## Unreleased

- Follows Notification's "stop" naming: the template variable is `stop_url`; reminders declare `getChannel()` email.
- A skipped send (cooldown, stop, changed recipient) keeps the failure count, so a bad address is still given up.
- The restore page sends no Referer (the signed link is in its URL); restore links are signed with a
  `commercekings_abandoned_cart_` prefix like the suite's other keyed hashes.
- The one-click unsubscribe header uses the consent withdrawal link, or in opt-out mode the "Stop these emails"
  link.
- Reminders carry Notification's "Didn't start this cart? Stop these emails to this address" link (`stop_url`).
- Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`): Magento 2.4.0–2.4.3 do not run on PHP 8.1.
- `cart_reminder` declares its banner category itself (`category = personalization`).
- A send that keeps failing (e.g. a rejected address) is tried again after 1 hour, 4 hours, 1 day and 1 day, then
  given up (`ck_abandoned_cart.failed_sends`), instead of every minute until the cart expires.
- The finder leaves a cart whose recipient is in cooldown (or has no address) out for an hour (`ck_abandoned_cart.skip_until`)
  instead of scanning it again on every run, oldest first, ahead of newer carts.
- Email prices (item prices, total) are shown in the cart's own currency, as the shopper saw them, without a second
  conversion from the base currency.
- The `cart_reminder` consent purpose is active only with the consent bridge, so a banner in the opt-out setup shows
  no reminder switch.
- **Opt-out without an extra module:** installed alone, abandoned cart runs opt-out. The checkout notice under the
  guest email field has *Show notice* (Automatic / Yes /
  No, website) and *Notice text* (store view) under *Checkout notice*. One neutral email template for both setups.
- Use Magento's native customer-data section invalidation for cart recovery; remove controller-owned cookie writes.
- Load a fresh quote repository for each delivery attempt so retries in a resident consumer use current cart content.
  Cover retry freshness with native SQL and customer-data behavior with Magento's actual frontend modules.
- Cover failed and uncertain queue publishes through stale-claim recovery, and customer checkout request
  failures/refusals and dependency-loading errors. Recheck the cart, login and consent after asynchronous
  checkout dependencies load; a failed load leaves capture unstored without interrupting checkout.
- **Two setups:** one reminder email per abandoned cart. With `commercekings/module-consent-notification`, only
  with a valid `cart_reminder` consent from `commercekings/module-consent` (not withdrawn, expired or from an
  older revision), checked in PHP and repeated inside every atomic SQL claim, so a withdrawal at the same moment
  wins. Without it (opt-out) every subscribed cart, and no consent tables are installed; the module has no
  dependency on `commercekings/module-consent`.
- **Subscribe:** carts are `commercekings/module-notification` subscriptions (type `cart_reminder`). Guests
  subscribe from checkout with the masked cart id and their email, customers their own cart through the session;
  `CartSubscription` authorizes the cart against the website and store, Notification handles email rules,
  consent and storage. Magento's quote and its checkout email are never written.
- **Data:** send history, the `queued_at` mark and a delivery version in `ck_abandoned_cart` (deleted with the quote).
  Who subscribed, with which consent and browser, is the cart's Notification subscription; guest
  addresses are shared Notification contacts, customers use their account email.
- **Sending** through `commercekings/module-notification`: shared queue consumer
  `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue), optional recipient cooldown
  (default seven days), store-emulated rendering and optional RFC 8058 one-click unsubscribe headers. A
  failure after the mail server accepted the email never causes a second reminder. A version guards failed-send
  rollback so an older worker cannot erase a newer successful delivery or clear its queued message.
- **Flash sales:** the finder runs every minute and publishes every due cart; the message broker holds the backlog,
  so throughput follows the number of consumers. One `queued_at` mark per cart (no reservation layer on top of the
  queue): cleared on send, skip or failed publish (so custom policies can recur), found again after 24 hours stuck. *Maximum reminders per hour* (default 100) is
  enforced when publishing and when sending, so it also holds when a backlog drains after an outage. Cursor
  pagination and a 30-second budget per run.
- **Delivery policy:** replaceable `ReminderPolicyInterface` (default one reminder per cart) with typed state
  and SQL contexts (`ReminderQuery::quoteId()` and the send-history columns `queued_at`/`reminded_at`); Magento's quote lifetime per store; finder and delivery recheck delay, expiry, cart
  state and consent.
- **Withdrawal:** Notification's handler, registered for `cart_reminder`, removes the cart's subscription
  while the send history stays, so opting in again cannot cause a second reminder.
- **Unsubscribe link:** replaceable `UnsubscribeLinkInterface` with an `UnsubscribeContext` (permission reference, store
  and named attributes: cart, customer, subscription, recipient); the default is Consent's signed withdrawal link.
- **Only Notification:** consent checks (SQL guard, send-time recheck for purpose and website) and the storefront
  check (`CommerceKings_Notification/js/permission`) go through Notification's permission; the restore link and the
  default unsubscribe link (`SubscriberUnsubscribeLink`) through Notification's `SubscriberInterface`; the consent
  bridge answers both with Consent. The form-key check is the module's own `Controller\RejectsMalformedFormKeys`.
- **Restore without a browser in Notification:** the restore link asks Notification's subscriber whether the
  current visitor gave the subscription's permission (Consent: the consent's browser); a subscriber that cannot be
  recognized makes `restore_url` the cart page.
- **Restore links:** signed with Magento's encryptor, confirmed by a form-key POST, bound to the browser
  and store, with locked carts and atomic merges that behave like Magento's sign-in merge (stock is enforced at checkout).
  The restore controller extends Magento's `Action`, so the shopper keeps their store view and currency in the page
  cache.
- **Admin:** CommerceKings > Abandoned Cart, with read-only previews of quote lifetime and reminders per cart.
- English defaults, PHP 8.1–8.5, PHPStan level 5, unit, SQL integration and JavaScript tests, CI and
  source/distribution packaging.

No public release has been tagged or published yet.
