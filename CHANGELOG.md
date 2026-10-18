# Changelog

## Unreleased (0.1.0)

First release. Requires Magento 2.4.4 or later (`magento/framework ^103.0.4`); PHP 8.1–8.5.

- **One reminder email per abandoned cart**, in two setups: alone it runs opt-out, with an optional checkout notice
  (*Show notice* Automatic / Yes / No, *Notice text*); with `commercekings/module-consent-notification` only with a
  valid `cart_reminder` consent, checked in PHP and repeated inside every atomic claim, so a withdrawal at the same
  moment wins. One neutral email template for both.
- **Subscribe:** carts are Notification subscriptions (type `cart_reminder`). Guests subscribe from checkout with the
  masked cart id and their email (`recipient`), customers their own cart with the account email; `CartSubscription`
  authorizes the cart against the website and store. Magento's quote and its checkout email are never written.
- **Send history** in Notification's delivery state (per cart and reminder type, surviving a withdrawal and a new
  sign-up), removed by Notification's daily cleanup once the cart is gone.
- **Flash sales:** the finder runs every minute and publishes every due cart; the broker holds the backlog, so
  throughput follows the number of consumers. *Maximum reminders per hour* (default 100) holds when publishing and
  when sending. A cart stuck in the queue is found again after 24 hours.
- **Respectful sending** through Notification: recipient cooldown (a cart whose recipient is in cooldown waits an
  hour), quiet hours (a held reminder waits until they end), Do not contact (`stop_url`: "Didn't start this cart? Stop
  these emails to this address"), and a send that keeps failing is retried after 1 hour, 4 hours, 1 day and 1 day,
  then given up. Prices in the cart's own currency. Deliveries name their type (`cart_reminder`).
- **Delivery policy:** replaceable `ReminderPolicyInterface` (default one reminder per cart and reminder type) with
  typed state and SQL contexts; Magento's quote lifetime per store.
- **Restore links:** signed (prefixed keyed hash), confirmed by a form-key POST, bound to the browser and store
  through the cart reminder's subscriber, with locked carts and atomic merges like Magento's sign-in merge; no
  Referer. Without a recognizable subscriber the email links to the cart page.
- **Unsubscribe:** replaceable `UnsubscribeLinkInterface` (default: the subscriber's link – Consent's signed
  withdrawal page); the one-click header uses it, or in opt-out mode the "Stop these emails" link.
- **Reminders on other channels:** a channel module (e.g. `module-abandoned-cart-sms`) adds a reminder type next to
  the built-in email reminder (`Model\Reminder\EmailReminder`), with di.xml and `@api` only: `Api\ReminderTypeInterface`
  (code, channel, purpose, own permission and subscriber, enabled / delay / per hour, policy, recipient, delivery) in
  `Model\ReminderTypePool` ("types"), and virtual types of `Model\Notification\CartReminder` and
  `Model\Notification\CartSubscription` with its type as argument `type`. The finder queues every registered type;
  each has its own settings, policy, hourly limit and lock, and send history per cart, and the recipient cooldown is
  checked per channel (Notification's `CooldownInterface::isRecentFor()`). `Api\CartDataInterface` (`Data\CartFacts`:
  the cart's plain facts) and `Api\RestoreLinkInterface` (the email's signed link; the cart page for other channels)
  for its text. Guide: [docs/extending.md](docs/extending.md).
- **Admin:** CommerceKings > Abandoned Cart, with read-only previews of quote lifetime and reminders per cart.

No public release has been tagged or published yet.
