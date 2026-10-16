# Abandoned cart reminder

Sends **one** reminder email for a cart a shopper left in checkout, after a delay you set – for guests and
customers alike. Cron finds the carts; `CommerceKings_Notification`'s queue consumer sends the emails.

## With or without consent

This module needs only `commercekings/module-notification`. Installed alone it runs **opt-out**; add the consent
bridge for **opt-in**:

- **Opt-out (this module alone)** – every shopper who enters an email in checkout is subscribed, and a short notice
  under the email field tells them. The button opens the cart page; the email has no unsubscribe link until you add
  your own page. No consent tables.
- **Opt-in (with consent)** – add `commercekings/privacy-bundle` (the consent bridge and the cookie banner). A cart is subscribed only with a consent for `cart_reminder`,
  withdrawing it removes the subscription, the button restores the saved cart (only in the browser that consented)
  and the email has Consent's unsubscribe link. The checkout notice is off by default.

Opt-out:

```sh
composer require commercekings/module-abandoned-cart:^0.1
bin/magento setup:upgrade
```

Opt-in:

```sh
composer require commercekings/module-abandoned-cart:^0.1 commercekings/privacy-bundle:^0.1
bin/magento setup:upgrade
```

To move a shop from opt-in to opt-out, `bin/magento module:uninstall` the bridge, the banner and
`CommerceKings_Consent`, then `composer remove commercekings/privacy-bundle`.

## Settings

**Stores > Configuration > CommerceKings > Abandoned Cart**:

| Field | Default |
|---|---|
| Enabled (store) | No |
| Inactivity delay (minutes) | 180 |
| Maximum reminders per hour | 0 = no limit – set your email provider's limit |
| Email template (store) | Abandoned Cart Reminder – neutral, for both setups |
| Sender (store) | General Contact – use a no-reply address |
| Show notice | Automatic – Yes without consent, No with consent |
| Notice text (store) | If you leave your cart, we may email you one reminder about it. |

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

Replace any of these with a DI preference:

`Api\ReminderPolicyInterface` – when to send (default: once per cart)

`Api\UnsubscribeLinkInterface` – your own unsubscribe page

`Api\ReminderEmailResolverInterface` – where the recipient comes from

## Flash sale load test

Where it shines: a flash sale leaves thousands of carts abandoned at once, and every shopper gets one reminder
within minutes. In a load test on a local sandbox (Magento 2.4.9 in production mode, 14 CPUs / 8 GB, RabbitMQ),
12 consumers sent 13,333 reminder emails a minute – every cart exactly once, no email after a withdrawal, no
deadlocks – while checkout subscribe and unsubscribe held p95 under 80 ms at 50 requests a second, with zero errors.

## Limitations

- One reminder per cart; follow-ups need another reminder policy. The restore link shows the cart as it is now and
  works for 7 days.
- A worker killed mid-send can leave a cart marked as reminded without an email – it is never sent twice.
- Checkout capture needs Magento's Luma checkout (also as Hyvä's Luma checkout fallback); Hyvä Checkout is not
  supported yet. Cron, queue and emails work with any theme.

## More

[How it works](docs/how-it-works.md) · [Security and links](docs/security.md) · [Testing](docs/testing.md) ·
[Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)
