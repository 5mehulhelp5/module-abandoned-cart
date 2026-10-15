# Changelog

## Unreleased

First release. Features:

- **Consent-based reminders:** one reminder email per abandoned cart, only with a valid `cart_reminder`
  consent from `commercekings/module-consent` (not withdrawn, expired or from an older revision). Consent is
  checked in PHP and repeated inside every atomic SQL claim, so a withdrawal at the same moment wins.
- **Subscribe:** carts are `commercekings/module-notification` subscriptions (type `cart_reminder`). Guests
  subscribe from checkout with the masked cart id and their email, customers their own cart through the session;
  `CartSubscription` authorizes the cart against the website and store, Notification handles email rules,
  consent and storage. Magento's quote and its checkout email are never written.
- **Data:** only the send history per cart in `ck_abandoned_cart` (queue and reminder times; deleted with the
  quote). Who subscribed, with which consent and browser, is the cart's Notification subscription; guest
  addresses are shared Notification contacts, customers use their account email.
- **Sending** through `commercekings/module-notification`: shared queue consumer
  `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue), optional recipient cooldown
  (default seven days), store-emulated rendering and optional RFC 8058 one-click unsubscribe headers. A
  failure after the mail server accepted the email never causes a second reminder.
- **Delivery policy:** replaceable `ReminderPolicyInterface` (default one reminder per cart) with typed state
  and SQL contexts (`ReminderQuery::quoteId()` and the send-history columns `queued_at`/`reminded_at`); Magento's quote lifetime per store; finder and delivery recheck delay, expiry, cart
  state and consent. Stale queue claims are released and queued again.
- **Withdrawal:** Notification's handler, registered for `cart_reminder`, removes the cart's subscription
  while the send history stays, so opting in again cannot cause a second reminder.
- **Restore links:** signed with Magento's encryptor, confirmed by a form-key POST, bound to the browser
  and store, with locked carts, stock validation and atomic merges.
- **Admin:** CommerceKings > Abandoned Cart, with read-only previews of quote lifetime and reminders per cart.
- English defaults, PHP 8.1–8.5, PHPStan level 5, unit, SQL integration and JavaScript tests, CI and
  source/distribution packaging.

No public release has been tagged or published yet.
