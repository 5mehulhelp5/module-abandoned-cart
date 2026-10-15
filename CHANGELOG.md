# Changelog

## Unreleased

First release. Features:

- Cover failed and uncertain queue publishes through stale-claim recovery, and customer checkout request
  failures/refusals and dependency-loading errors. Recheck the cart, login and consent after asynchronous
  checkout dependencies load; a failed load leaves capture unstored without interrupting checkout.

- **Consent-based reminders:** one reminder email per abandoned cart, only with a valid `cart_reminder`
  consent from `commercekings/module-consent` (not withdrawn, expired or from an older revision). Consent is
  checked in PHP and repeated inside every atomic SQL claim, so a withdrawal at the same moment wins.
- **Subscribe:** carts are `commercekings/module-notification` subscriptions (type `cart_reminder`). Guests
  subscribe from checkout with the masked cart id and their email, customers their own cart through the session;
  `CartSubscription` authorizes the cart against the website and store, Notification handles email rules,
  consent and storage. Magento's quote and its checkout email are never written.
- **Data:** send history and the `queued_at` mark in `ck_abandoned_cart` (deleted with the quote).
  Who subscribed, with which consent and browser, is the cart's Notification subscription; guest
  addresses are shared Notification contacts, customers use their account email.
- **Sending** through `commercekings/module-notification`: shared queue consumer
  `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue), optional recipient cooldown
  (default seven days), store-emulated rendering and optional RFC 8058 one-click unsubscribe headers. A
  failure after the mail server accepted the email never causes a second reminder.
- **Flash sales:** the finder runs every minute and publishes every due cart; the message broker holds the backlog,
  so throughput follows the number of consumers. One `queued_at` mark per cart (no reservation layer on top of the
  queue): cleared on send, skip or failed publish (so custom policies can recur), found again after 24 hours stuck. *Maximum reminders per hour* is
  enforced when publishing and when sending, so it also holds when a backlog drains after an outage. Cursor
  pagination and a 30-second budget per run.
- **Delivery policy:** replaceable `ReminderPolicyInterface` (default one reminder per cart) with typed state
  and SQL contexts (`ReminderQuery::quoteId()` and the send-history columns `queued_at`/`reminded_at`); Magento's quote lifetime per store; finder and delivery recheck delay, expiry, cart
  state and consent.
- **Withdrawal:** Notification's handler, registered for `cart_reminder`, removes the cart's subscription
  while the send history stays, so opting in again cannot cause a second reminder.
- **Unsubscribe link:** replaceable `UnsubscribeLinkInterface` with an `UnsubscribeContext` (consent id, store
  and named attributes: cart, customer, subscription, recipient); the default is Consent's signed withdrawal link.
- **Restore without a browser in Notification:** the restore link asks Notification's permission whether the
  current visitor gave the subscription's permission (Consent: the consent's browser); a permission that cannot tell
  makes `restore_url` the cart page.
- **Restore links:** signed with Magento's encryptor, confirmed by a form-key POST, bound to the browser
  and store, with locked carts and atomic merges that behave like Magento's sign-in merge (stock is enforced at checkout).
- **Admin:** CommerceKings > Abandoned Cart, with read-only previews of quote lifetime and reminders per cart.
- English defaults, PHP 8.1–8.5, PHPStan level 5, unit, SQL integration and JavaScript tests, CI and
  source/distribution packaging.

No public release has been tagged or published yet.
