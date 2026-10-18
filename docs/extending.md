# Reminders on other channels

Abandoned cart sends its email reminder out of the box. A channel module – e.g. `module-abandoned-cart-sms` – adds a
reminder on another channel (SMS, WhatsApp, Viber, …) next to it, through `@api` interfaces and di.xml only: no
preferences on this module's classes, no plugins, nothing changed here. It depends on this module and on
`commercekings/module-notification`, never on a provider (Twilio, Infobip, …): the provider is Notification's, chosen
per channel in the admin.

Everything except the type's own settings, consent and text is shared: the finder (cron, every minute), the atomic
claim, retries after a failed send, the recipient cooldown, quiet hours, the hourly limit, the reminder policy and the
send history (Notification's delivery state, one row per cart and type – so each channel reminds a cart once, also
after a withdrawal and a new sign-up).

## What a channel module provides

1. **A reminder type:** a class implementing `Api\ReminderTypeInterface`, registered in `Model\ReminderTypePool`.
2. **Its notification and subscription type:** two virtual types of this module's `Model\Notification\CartReminder`
   and `Model\Notification\CartSubscription` with its reminder type as argument `type`, registered in Notification's
   pools under the same code.
3. **Its consent:** usually Notification's address consent for the number (a checkbox) – a virtual type of
   `CommerceKings\Notification\Model\AddressConsent`, returned by `getPermission()`.
4. **Its checkout UI:** a phone field with Notification's checkbox, subscribing through Notification's REST routes.
5. **Its settings:** its own `system.xml` group and `config.xml` defaults (enabled, delay, per hour, the text).

## 1. The reminder type

```php
namespace Vendor\CartSms\Model;

use CommerceKings\AbandonedCart\Api\CartDataInterface;
use CommerceKings\AbandonedCart\Api\Data\ReminderMessage;
use CommerceKings\AbandonedCart\Api\ReminderPolicyInterface;
use CommerceKings\AbandonedCart\Api\ReminderTypeInterface;
use CommerceKings\AbandonedCart\Api\RestoreLinkInterface;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\SubscriberInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;

class SmsReminder implements ReminderTypeInterface
{
    public const CODE = 'cart_reminder_sms';

    public function __construct(
        private readonly Config $config,                              // your own settings
        private readonly SubscriptionPermissionInterface $permission, // the AddressConsent virtual type (di.xml)
        private readonly ReminderPolicyInterface $policy,             // e.g. OncePerCart
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly CartDataInterface $cartData,
        private readonly RestoreLinkInterface $restoreLinks
    ) {
    }

    public function getCode(): string { return self::CODE; }
    public function getChannel(): string { return Channel::SMS; }
    public function getPurpose(): string { return self::CODE; }
    public function getPermission(): ?SubscriptionPermissionInterface { return $this->permission; }
    public function getSubscriber(): ?SubscriberInterface { return null; }
    public function isEnabled(int $storeId): bool { return $this->config->isEnabled($storeId); }
    public function getDelayMinutes(int $storeId): int { return $this->config->getDelayMinutes($storeId); }
    public function getMaxPerHour(): int { return $this->config->getMaxPerHour(); }
    public function getPolicy(): ReminderPolicyInterface { return $this->policy; }

    /** The number the shopper subscribed for this cart (guest or customer), stored normalized by Notification. */
    public function getRecipient(int $quoteId): ?string
    {
        $subscription = $this->subscriptions->find(self::CODE, $quoteId)[0] ?? null;
        $recipient = $subscription ? $this->subscriptions->getRecipient($subscription) : null;
        return $recipient !== null && $recipient['channel'] === Channel::SMS ? $recipient['recipient'] : null;
    }

    public function createDelivery(ReminderMessage $message): Delivery
    {
        return Delivery::text($message->storeId, Channel::SMS, $message->recipient, function () use ($message) {
            $quote = ($message->quote)();                  // fresh cart, inside the store's environment
            $facts = $this->cartData->get($quote);
            $text = strtr($this->config->getText($message->storeId), [
                '{{name}}' => $facts->firstName,
                '{{product}}' => $facts->items[0]['name'] ?? '',
                '{{total}}' => $facts->grandTotal,
                '{{link}}' => $this->restoreLinks->getUrl($quote, self::CODE),
            ]);
            return new DeliveryContent([], text: $text);
        }, true, $message->isRecipientCurrent, true, self::CODE);
    }
}
```

- `getCode()` is unique and is the code everywhere: the reminder type pool, Notification's type pools, the queue
  messages, the send history, the subscriptions and the hourly lock (`commercekings_abandoned_cart_hourly_<code>`).
- `getPermission()`: your own permission, or `null` for the shop-wide one (opt-out, or the cookie banner with the
  consent bridge). The claim, the send-time recheck and Notification's sign-up all use it.
- `getMaxPerHour()` is per type – your provider's own limit; `0` for none.
- `createDelivery()` gets a claimed cart. Build the content inside the closure (prices and URLs in the store's
  environment), pass `$message->isRecipientCurrent` (the sender rechecks after its cooldown claim), `stoppable: true`
  and your code as `type`. Notification's sender then applies the stop list, quiet hours (also in the recipient's
  time zone, with a zone provider) and the cooldown, and hands it to the channel's provider.
- `Api\CartDataInterface::get($quote)` gives the cart's plain facts – first name, items (name, quantity, price,
  image, the store's own option choices), grand total, store URL – the same the email shows, never text a visitor
  typed. Escape them for your channel yourself.
- `Api\RestoreLinkInterface::getUrl($quote, $code)` is the link back to the cart. For a channel other than email it
  is the cart page (a phone cannot prove it is the shopper's browser); a later version may give it a secure restore
  link without changing the method.

## 2. di.xml

```xml
<!-- your reminder in the finder -->
<type name="CommerceKings\AbandonedCart\Model\ReminderTypePool">
    <arguments>
        <argument name="types" xsi:type="array">
            <item name="cart_reminder_sms" xsi:type="object">Vendor\CartSms\Model\SmsReminder</item>
        </argument>
    </arguments>
</type>
<!-- its consent: a checkbox for the number (Notification) -->
<virtualType name="VendorCartSmsConsent" type="CommerceKings\Notification\Model\AddressConsent">
    <arguments>
        <argument name="typeCode" xsi:type="string">cart_reminder_sms</argument>
    </arguments>
</virtualType>
<type name="Vendor\CartSms\Model\SmsReminder">
    <arguments>
        <argument name="permission" xsi:type="object">VendorCartSmsConsent</argument>
        <argument name="policy" xsi:type="object">CommerceKings\AbandonedCart\Model\ReminderPolicy\OncePerCart</argument>
    </arguments>
</type>
<!-- its notification type (Notification's consumer) and subscription type (who may sign up a cart) -->
<virtualType name="VendorCartSmsReminder" type="CommerceKings\AbandonedCart\Model\Notification\CartReminder">
    <arguments>
        <argument name="type" xsi:type="object">Vendor\CartSms\Model\SmsReminder</argument>
    </arguments>
</virtualType>
<virtualType name="VendorCartSmsSubscription" type="CommerceKings\AbandonedCart\Model\Notification\CartSubscription">
    <arguments>
        <argument name="type" xsi:type="object">Vendor\CartSms\Model\SmsReminder</argument>
    </arguments>
</virtualType>
<type name="CommerceKings\Notification\Model\TypePool">
    <arguments>
        <argument name="types" xsi:type="array">
            <item name="cart_reminder_sms" xsi:type="object">VendorCartSmsReminder</item>
        </argument>
    </arguments>
</type>
<type name="CommerceKings\Notification\Model\SubscriptionTypePool">
    <arguments>
        <argument name="types" xsi:type="array">
            <item name="cart_reminder_sms" xsi:type="object">VendorCartSmsSubscription</item>
        </argument>
    </arguments>
</type>
```

If your type depends on something that is built from Notification's subscription type pool (as the email reminder's
data is), inject that through a Magento proxy (`…\Proxy`) – the pool builds your subscription type, which builds your
reminder type.

## 3. Checkout

- **The checkbox and the phone field:** Notification's `CommerceKings_Notification::address-consent.phtml` with
  `type` = your code and `channel` = `sms` (see Notification's module-authors guide, *Address consent*). Show both
  only when a provider exists for the channel: `ProviderPoolInterface::getAll()` has one with that channel.
- **Subscribe:** after the checkbox recorded the consent (`ckAddressConsent:recorded`), sign the cart up through
  Notification's REST routes: guests `POST /V1/ck-notification/subscriptions` with `{"type": "cart_reminder_sms",
  "reference": <masked cart id>, "recipient": <phone>}`, customers `POST …/subscriptions/mine` with the quote id as
  `reference` and the `recipient` too (non-email types need it).
  `CartSubscription` authorizes the cart exactly as for email (the guest's masked id, the customer's own cart, the
  current website) and takes sign-ups only where your `isEnabled()` is true.

## Settings, texts, tests

- Your `system.xml` group: enabled, delay, reminders per hour and the text per store view, with a `config.xml`
  default that names the brand and ends with "Reply STOP to stop" (see Notification's *Messaging* guide for SMS
  length).
- The send history is per type and cart (`ck_notification_delivery_state`, `type` = your code); Notification's daily
  cleanup removes it with the cart.
- Test your type with this module's `Test/Unit/Fixture/TestReminderType` as the model: the finder and the reminder
  are exercised with a second type there (`FindAbandonedCartsTest`, `CartReminderTest`).

## Stable interfaces

`Api\ReminderTypeInterface`, `Api\Data\ReminderMessage`, `Api\CartDataInterface`, `Api\Data\CartFacts`,
`Api\RestoreLinkInterface`, `Api\ReminderPolicyInterface` with `Api\Data\ReminderQuery` / `ReminderState`, the
`types` argument of `Model\ReminderTypePool`, and virtual types of `Model\Notification\CartReminder` and
`Model\Notification\CartSubscription` with their `type` argument. Everything else in this module is internal.
