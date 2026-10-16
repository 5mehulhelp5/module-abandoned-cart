# Abandoned cart reminder

**Development status:** first-release feature scope frozen; polishing and verification remain before publication.

Sends **one** reminder email for a cart a shopper left in checkout, after a delay you set – for guests and
customers alike. Cron finds the carts; `CommerceKings_Notification`'s queue consumer sends the emails.

## With or without consent

This module needs only `commercekings/module-notification`. Whether a shopper must consent first is decided by
what you install next to it:

- **With consent** – add the bridge `commercekings/module-consent-notification` and a banner (e.g.
  `commercekings/module-consent-vanilla`). The bridge connects Notification to the consent core: a cart is
  subscribed only with a consent for `cart_reminder`, withdrawing it removes the subscription, the email's button
  restores the saved cart (only in the browser that consented) and the email has Consent's unsubscribe link.
- **Without consent (opt-out)** – add `commercekings/module-abandoned-cart-optout` instead. Every shopper who enters
  an email in checkout is subscribed; it adds a checkout notice and a neutral email template. The button opens the
  cart page, and there is no unsubscribe link until you add your own page. No consent tables are installed.

```sh
# with consent
composer require commercekings/module-abandoned-cart:^0.1 commercekings/module-consent-notification:^0.1 commercekings/module-consent-vanilla:^0.1
# without consent
composer require commercekings/module-abandoned-cart:^0.1 commercekings/module-abandoned-cart-optout:^0.1

bin/magento setup:upgrade
```

The two cannot be installed together. To switch a shop from consent to opt-out, `bin/magento module:uninstall` the
bridge, the banner and `CommerceKings_Consent`, then require the opt-out module.

## Settings

**Stores > Configuration > CommerceKings > Abandoned Cart**:

| Field | Default |
|---|---|
| Enabled (store) | No |
| Inactivity delay (minutes) | 180 |
| Maximum reminders per hour | 0 = no limit – set your email provider's limit |
| Email template (store) | Abandoned Cart Reminder (opt-out module: its neutral template) |
| Sender (store) | General Contact – use a no-reply address |

Carts expire with Magento's *Quote Lifetime* (normally 30 days). The recipient cooldown (7 days per address) and
one-click unsubscribe headers are under **CommerceKings > Notifications**.

To change the email, load the template in **Marketing > Email Templates**. Variables: `customer_name`, `items_html`,
`grand_total`, `restore_url`, `optout_url`, `contact_url`, `store_url`; keep the unsubscribe line inside
`{{depend optout_url}}`.

## Before going live

- A no-reply sender with **SPF, DKIM and DMARC**, and *Maximum reminders per hour* at your provider's limit.
- Run the consumer `commerceKingsNotificationSend` with `--max-messages=1000` and restart it after each deployment
  ([queue setup](https://github.com/commercekings/module-notification/blob/main/docs/operations.md#queue-transport)).
- Rate-limit the guest subscribe endpoint in nginx or your proxy
  ([how](https://github.com/commercekings/module-notification/blob/main/docs/operations.md#rate-limiting)).

## Extending

DI preferences: `Api\ReminderPolicyInterface` (when to send; default once per cart), `Api\UnsubscribeLinkInterface`
(your own unsubscribe page) and `Api\ReminderEmailResolverInterface` (where the recipient comes from).

## Limitations

- One reminder per cart, ever. The restore link shows the cart as it is now and works for 7 days.
- A worker killed mid-send can leave a cart marked as reminded without an email – it is never sent twice.

## More

[How it works](docs/how-it-works.md) · [Security and links](docs/security.md) · [Testing](docs/testing.md) ·
[Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)
