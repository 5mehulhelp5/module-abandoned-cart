# Changelog

## Unreleased

First release. Features:

- **Consent-based reminders:** one reminder email per abandoned cart, only with a valid `cart_reminder`
  consent from `commercekings/module-consent` (not withdrawn, expired or from an older revision). Consent is
  checked in PHP and repeated inside every atomic SQL claim, so a withdrawal at the same moment wins.
- **Capture:** guests through `POST /V1/guest-carts/:cartId/ck-abandoned-email`
  (`GuestEmailCaptureInterface`), validated by a global before plugin with Magento's native filters and
  validators; logged-in customers on checkout through a form-key POST. Capture is authorized against the
  cart's website and store. Magento's quote and its checkout email are never written.
- **Data:** reminder state per cart in `ck_abandoned_cart` (consent id, browser id hash, capture, queue and
  reminder times; deleted with the quote). Guest addresses are shared `commercekings/module-notification`
  contacts; customers use their account email.
- **Sending** through `commercekings/module-notification`: shared queue consumer
  `commerceKingsNotificationSend` (RabbitMQ or Magento's database queue), optional recipient cooldown
  (default seven days), store-emulated rendering and optional RFC 8058 one-click unsubscribe headers. A
  failure after the mail server accepted the email never causes a second reminder.
- **Delivery policy:** replaceable `ReminderPolicyInterface` (default one reminder per cart) with typed state
  and SQL contexts; Magento's quote lifetime per store; finder and delivery recheck delay, expiry, cart
  state and consent. Stale queue claims are released and queued again.
- **Withdrawal:** registered with Consent's withdrawal pool; capture is cleared from open carts while
  delivery history stays, so opting in again cannot cause a second reminder.
- **Restore links:** signed with Magento's encryptor, confirmed by a form-key POST, bound to the browser
  and store, with locked carts, stock validation and atomic merges.
- **Admin:** CommerceKings > Abandoned Cart, with read-only previews of quote lifetime and reminders per cart.
- English defaults, PHP 8.1–8.5, PHPStan level 5, unit, SQL integration and JavaScript tests, CI and
  source/distribution packaging.

No public release has been tagged or published yet.
