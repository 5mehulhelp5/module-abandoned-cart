# Flash-Sale-Ready Abandoned Cart<a href="https://commercekings.net"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/commercekings/module-abandoned-cart/main/.github/commercekings-dark.svg"><img align="right" width="152" src="https://raw.githubusercontent.com/commercekings/module-abandoned-cart/main/.github/commercekings-light.svg" alt="Built by CommerceKings. Ready to sell more? Talk to us"></picture></a>

Works with Magento 2.4.4+ · Luma and Hyvä (Luma checkout) · PHP 8.1–8.5 · RabbitMQ or MySQL queue

[![License](https://badgen.net/github/license/commercekings/module-abandoned-cart)](LICENSE) [![Release](https://badgen.net/github/release/commercekings/module-abandoned-cart)](https://github.com/commercekings/module-abandoned-cart/releases) [![Checks](https://github.com/commercekings/module-abandoned-cart/actions/workflows/checks.yml/badge.svg?branch=main)](https://github.com/commercekings/module-abandoned-cart/actions/workflows/checks.yml)

**Everyday reliable, never slows your store.** A well-timed email for every abandoned cart.

- **Built for traffic.** Checkout only saves the email; sending runs in the background – thousands of reminders a
  minute, every cart exactly once (see *Where it shines* below).
- **Your rules.** Opt-in with a cookie banner, or opt-out with an optional checkout notice. Set the delay, hourly limit,
  template, sender and notice text – or plug in your own reminder schedule.
- **Back to the cart in one click.** A signed link restores the saved cart and merges it into the shopper's current
  one.
- **Respectful by design.** A recipient cooldown, optional quiet hours, no email after a shopper says no, and a
  "stop these emails" link that suppresses an address someone else signed up.

**Email reminders are built in** and work out of the box. No extra module or provider needed.

- **Text messages and WhatsApp:** add [module-abandoned-cart-sms](https://github.com/commercekings/module-abandoned-cart-sms) and one provider:
  - [Twilio](https://github.com/commercekings/module-notification-twilio) (text, WhatsApp)
  - [Infobip](https://github.com/commercekings/module-notification-infobip) (text, WhatsApp, Viber)

  Consent for a phone number is a checkbox next to the number at checkout, built in.
- **Recommended:** [module-rate-limit](https://github.com/commercekings/module-rate-limit). Limits the public sign-up endpoints and the restore
  link (Magento 2.4.7+).
- **Optional, consent for email:** send reminders only to shoppers who agree, through a cookie banner. Install
  [privacy-bundle](https://github.com/commercekings/privacy-bundle).

## Installation

### Text messages and WhatsApp: always opt-in

The shopper ticks a checkbox next to their phone
number. That consent is separate from email and doesn't need the cookie banner. Add
[module-abandoned-cart-sms](https://github.com/commercekings/module-abandoned-cart-sms) and one provider (see the
add-ons above).

---

### Email: choose one mode

**Opt-out** – a reminder email for every shopper who enters an email at checkout:

```sh
composer require commercekings/module-abandoned-cart:^0.1
bin/magento setup:upgrade
```

**Opt-in** – a reminder email only for shoppers who agree in the cookie banner:

```sh
composer require commercekings/module-abandoned-cart:^0.1 commercekings/privacy-bundle:^0.1
bin/magento setup:upgrade
```

Then switch it on in the admin – nothing is sent until you do:

1. **Stores > Configuration > CommerceKings > Abandoned Cart > Enabled**: Yes.
2. Opt-in only: **Stores > Configuration > CommerceKings > Consent > Source**: *Cookie banner (vanilla-cookieconsent)*.
   Without a source, no shopper has consent and no reminder is sent.

| | Opt-out | Opt-in |
|---|---|---|
| Who gets a reminder | every shopper who entered an email | only with consent for `cart_reminder` |
| Checkout notice | on, optional | off |
| Email button | opens the cart page | restores the saved cart, only in the browser that consented |
| Unsubscribe | the "Stop these emails" link, always on; optional one-click headers; or [your own page](#your-own-unsubscribe-page) | signed link, optional one-click headers |
| Consent tables | none | created |

To move a shop from opt-in to opt-out, uninstall the **consent bridge**, the **cookie banner** and **Consent** with
`bin/magento module:uninstall`, then `composer remove commercekings/privacy-bundle`.
Subscriptions made with a consent are not used in opt-out mode; a shopper is subscribed again when they enter
their email.

## Settings

**Stores > Configuration > CommerceKings > Abandoned Cart**:

| Field | Default |
|---|---|
| Enabled (store) | No |
| Inactivity delay (minutes, global) | 180 |
| Maximum reminders per hour (global) | 100 – set your email provider's limit; 0 = no limit |
| Email template (store) | Abandoned Cart Reminder – neutral, for both setups |
| Sender (store) | General Contact |
| Show notice (website) | Automatic – Yes without consent, No with consent |
| Notice text (store) | If you leave your cart, we may email you one reminder about it. |

Carts expire with Magento's *Quote Lifetime* (normally 30 days). The recipient cooldown (7 days per address), quiet
hours (for email, select it under *Channels*) and the optional one-click unsubscribe headers are under
**CommerceKings > Notifications**; a reminder held by quiet hours goes out when they end.

To change the email, load the template in **Marketing > Email Templates**. Variables: `customer_name`, `items_html`,
`grand_total`, `restore_url`, `optout_url`, `stop_url`, `contact_url`, `store_url`; keep the unsubscribe line inside
`{{depend optout_url}}` and the stop link inside `{{depend stop_url}}`.

## Guest becomes a customer

When a guest registers or signs in, the reminder goes to the account email, never to the address typed as a guest.
The customer's subscription replaces the guest one when they open checkout. If Magento merges the guest cart into an
existing customer cart, the guest address is removed by the nightly cleanup.

## Before going live

- **Opt-out:** add your own unsubscribe page ([how](#your-own-unsubscribe-page)). Without it, reminders go out with
  no unsubscribe link. In the EU, every marketing email must offer one (ePrivacy Directive, Art. 13).
- Set *Maximum reminders per hour* to your email provider's limit.

**Recommended**

- Run the consumer `commerceKingsNotificationSend` with `--max-messages=1000`, so long-running workers recycle.
- To scale, run more consumers in parallel. Number of consumers = email provider's limit per second ÷ emails one
  consumer sends per second.
- Rate-limit the guest subscribe endpoint (without one, a script can make the shop email any address, up to the
  hourly cap) – in your web server, proxy or CDN first
  ([how](https://github.com/commercekings/module-notification/blob/main/docs/operations.md#rate-limiting)), then
  module-rate-limit (above) as a second line.

**Note:** If you turn on the optional *One-click unsubscribe*, send from a no-reply address whose domain DKIM-signs
the unsubscribe headers, as [RFC 8058](https://www.rfc-editor.org/rfc/rfc8058) requires.

## Extending

**Reminders on other channels** (SMS, WhatsApp, Viber): a channel module adds its own reminder type next to the
built-in email reminder – di.xml and `@api` only, sharing the finder, claim, retries, quiet hours and send history.
See [Reminders on other channels](docs/extending.md).

Replace any of these with a DI preference:

`Api\ReminderPolicyInterface` – when to send (default: once per cart)

`Api\UnsubscribeLinkInterface` – your own unsubscribe page

`Api\ReminderEmailResolverInterface` – where the recipient comes from

### Your own unsubscribe page

Opt-out has no consent to withdraw: unsubscribing removes the shopper's subscription, so no further reminders are
sent. Implement `Api\UnsubscribeLinkInterface` to put your link into the email (`{{var optout_url}}`), and remove the
subscription on your page:

```php
// Your link, e.g. https://shop.example/unsubscribe?id=123&sig=…
public function getUrl(UnsubscribeContext $context): string
{
    $id = (int)$context->getAttribute('subscription_id');
    return $this->url->getUrl('acme/unsubscribe', ['_query' => ['id' => $id, 'sig' => $this->sign($id)]]);
}

// Your controller, after checking the signature
$this->subscriptionRemoval->remove($id); // CommerceKings\Notification\Api\SubscriptionRemovalInterface
```

```xml
<preference for="CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface" type="Acme\Unsubscribe\Model\Link"/>
```

Sign the link (e.g. an HMAC of the id), so nobody can unsubscribe someone else. If you turn on *One-click
unsubscribe*, your page must also accept the mail client's POST. Without your own page, opt-out reminders use the
"Stop these emails" link for both the visible link and the one-click header.

## Where it shines

A flash sale leaves thousands of carts abandoned at once, and every shopper gets one reminder
within minutes. In a load test on a local sandbox (Magento 2.4.9 in production mode, 14 CPUs / 8 GB, RabbitMQ),
12 consumers sent 13,333 reminder emails a minute – every cart exactly once, no email after a withdrawal, no
deadlocks – while checkout subscribe and unsubscribe held p95 under 80 ms at 50 requests a second, with zero errors.

## Limitations

- One reminder per cart; follow-ups need another reminder policy. The restore link shows the cart as it is now and
  works for 7 days.
- A worker killed mid-send can leave a cart marked as reminded without an email – it is never sent twice.
- Restore links are signed with Magento's encryption key: changing the key invalidates links already sent.

## More

[How it works](docs/how-it-works.md) · [Security and links](docs/security.md) · [Testing](docs/testing.md) ·
[Other channels](docs/extending.md) ·
[Compatibility](COMPATIBILITY.md) · [Changes](CHANGELOG.md)

## Disclaimer

These modules help you ask for, record and respect your shoppers' choices – they don't make your store compliant on
their own. You are responsible for your store's privacy: what you collect, the wording you show, your privacy policy
and whether your setup meets the laws that apply to you (e.g. GDPR, ePrivacy, email marketing rules). Get legal advice
if you are unsure.
