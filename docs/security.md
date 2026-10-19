# Security and links

Restore and unsubscribe links, endpoints and abuse protection of `commercekings/module-abandoned-cart`.
Start with the [README](../README.md).

## Links in the email

The restore link works for up to 7 days after sending, capped by Magento's configured quote lifetime from the cart's last update, and uses `?q={cart id}&t={signature}`. Its signature (`LinkSignature`) uses Magento's `EncryptorInterface::hash()` over purpose + cart id, retaining the existing 32-hex token format. Magento supplies HMAC-SHA256 and selects/decodes its current deployment key. The opt-out link (by default – see *Custom unsubscribe link* below) comes from Consent's `WithdrawalLinkInterface`, uses `?c={consent id}&t={signature}`, and remains usable while that consent record is retained. Its separate HMAC binds the consent id and website. Neither signature includes the email, so changing a cart or account email does not invalidate it. Magento's masked cart id (the guest cart REST key) never appears in the email. Changing the deployment signing key invalidates existing links.

Generation and verification use only Magento's native signer, with no legacy raw-key fallback.
Older development links can stop working when their raw-key signatures differ from Magento's
current-key signatures. Use a fresh cart to generate a reminder when testing this change. Comparison uses
`hash_equals`; password-hash formats and unkeyed hashes cannot authorize recovery.

**Restore** `/cartreminder/cart/restore` – only when the subscription's permission can identify the visitor
(`identifiesVisitor()`, true with Consent). Otherwise `restore_url` is Magento's cart page (`checkout/cart`): the
visitor's own session or account cart, with no restore or merge.

Restore steps:

- invalid/forged signature or changed quote id → homepage before any quote lookup;
- link generation requires a nonempty string deployment `crypt/key`; missing, blank or invalid keys stop generation and signature validation rejects safely;
- the visitor must be the one who gave the subscription's permission – Notification's `SubscriberInterface::isCurrentVisitor()`; with Consent: the browser id (cookie `ck_consent_browser`) must match the browser the consent was given in – and the current store must match the cart's store, else → homepage. Checked before availability, so a stranger cannot tell whether a cart was ordered;
- cart ordered/closed, emptied or expired under Magento's quote lifetime, or the link is older than 7 days (`Config::RESTORE_LINK_DAYS`, from the reminder's `sent_at`) → notice "This reminder link is no longer available. You can continue shopping with your current cart." (owner only);
- customer cart → cart page if logged in as that customer, otherwise login page with "Sign in to continue shopping – your cart is waiting for you.";
- guest cart → **GET shows a confirmation page without merging or replacing the session cart**. If it is already the session cart, the visitor goes straight to the cart page;
- confirmation → **POST with a valid Magento form key**. Source and destination quote rows are locked in id order, then freshly loaded. Browser ownership, store, expiry and destination account/active status are checked again;
- guest with no existing cart → the saved cart becomes the session cart. An existing cart, including an empty one or a logged-in customer's cart, keeps its identity and receives the saved products through `Quote::merge`;
- a merge keeps the destination's contact details, account, addresses and coupon. Matching product configurations have their quantities added; distinct configurations remain separate. Totals and shipping rates are recalculated. Like Magento's own merge when a guest signs in, unavailable products and quantities above stock do not refuse the merge: they show as cart errors and checkout refuses the order until the shopper fixes them;
- both carts are saved in one database transaction. Only after it commits does the session switch and the saved cart become closed. A failed save rolls back both carts; a repeated or concurrent restore cannot add the closed source twice. Success: "Your saved cart has been restored.";
- the restore POST is registered in `etc/frontend/sections.xml` for the customer-data `cart` section. Magento invalidates it on submission and reloads it after the redirect, so the mini cart shows the current count. Magento owns its customer-data cookies and storage.

**Opt-out** `/ckconsent/optout` – Consent owns the signed link and explicit confirmation:

- valid GET → read-only confirmation form; consent, captured email and banner preferences stay unchanged until the visitor submits its form-key-protected POST. After confirmation, Notification's handler removes the cart's subscription while preserving Magento checkout email, with durable retry if cleanup fails;
- already withdrawn → confirmation again, without withdrawing a later explicit consent. Deleting the original cart does not break the link; deleting the consent record, an invalid signature or another website → a "Link expired" page that changes nothing and points to "Cookie settings";
- confirmed in the owning browser → Consent's `js/withdrawal-banner.js` switches the mapped choice off only if that browser's current choice was withdrawn. GET never synchronizes preferences; an already-withdrawn choice can be applied explicitly with the form. Another browser sees a generic question and, after submitting, a generic confirmation. Other categories and later explicit choices stay; another device's cookie is untouched. Purposes sharing the same category follow it together;

**Custom unsubscribe link** – `optout_url` (and the one-click header) comes from
`CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface::getUrl(UnsubscribeContext $context)`. The default,
`Model\SubscriberUnsubscribeLink`, returns the subscriber's link (Notification's `SubscriberInterface::getUnsubscribeUrl()`): Consent's signed link above with the consent bridge, none without it – the email then leaves the unsubscribe line out and sends no one-click header. Replace it with a DI preference to use your
own page or an external preference center:

```xml
<preference for="CommerceKings\AbandonedCart\Api\UnsubscribeLinkInterface" type="Vendor\Module\Model\MyUnsubscribeLink"/>
```

`UnsubscribeContext` has `permissionRef` (the subscription's permission reference – the consent id with the consent
bridge, '' without it) and `storeId`, and `getAttribute($name)` for the rest: `quote_id`, `customer_id` (null for
guests), `subscription_id` and `email`; an unknown name returns null, so attributes can be added without breaking
implementations. Your link must be unguessable (signed or a random token, never a plain id or email), accept a
one-click `POST` if Notification's *One-click unsubscribe* is on, and revoke the subscription – with the consent
bridge `ConsentInterface::withdraw($permissionRef, 'email')`, without it Notification's
`SubscriptionRemovalInterface::remove($subscriptionId)` – that is what stops reminders.

## Endpoint safety

Subscribing authorizes an active guest/customer cart on the current website (`CartSubscription::authorize()`:
masked cart id for guests, own quote id for customers) before Notification reads consent or writes anything. It
uses the cart's store configuration; Notification requires the reminder purpose and the same consent website, and
repeats the authorized cart condition (active, store, guest/customer) and the consent guard in its atomic INSERT,
retry acknowledgement and guest unsubscribe. The consumer rejects a wrong-purpose or wrong-website consent before
claiming a cart.

Storefront POST actions require a valid form key even for AJAX. Malformed form keys are
rejected before Magento's string comparison; malformed purpose/link parameters and source
cookies are refused without server errors. Signed withdrawal HEAD requests have no effects. The customer
subscribe endpoint uses Magento's `self` resource: the storefront session only for AJAX requests, or a customer
token; the customer id is never taken from the request body.

## Endpoints

| Endpoint | Who | Purpose |
|---|---|---|
| `POST /rest/V1/ck-notification/subscriptions` (anonymous, Notification) | guest checkout | subscribe the cart with its recipient (email, or a number with its checkbox consent), or unsubscribe with `""` |
| `POST /rest/V1/ck-notification/subscriptions/mine` (`self`, Notification) | logged-in customer on checkout | subscribe their own cart |
| `GET /cartreminder/cart/restore?q=&t=` | email | validate ownership and show restore confirmation |
| `POST /cartreminder/cart/restore` (form key, `q`, `t`) | confirmation page | adopt or merge the saved cart |
| `GET /ckconsent/optout?c=&t=` (Consent module) | email | show read-only withdrawal confirmation |
| `POST /ckconsent/optout` (form key, `c`, `t`) | confirmation form | withdraw consent and apply owned-browser preferences |

Guest capture sends `{"type":"cart_reminder","reference":"<masked cart id>","recipient":"visitor@example.test"}`; an
explicit `"recipient":""` unsubscribes. Notification validates the recipient (an email: missing, non-string, invalid
format or over 255 characters; a number: E.164 through the channel's normalizer → Magento's standard 400 input error,
without echoing the address) before the cart is authorized, so invalid input leaves an existing subscription intact. See
[module-notification](https://github.com/commercekings/module-notification/blob/main/docs/how-it-works.md#subscribe).

## Abuse protection

Reminder content restricts visitor-controlled values and uses the shop's catalog data:

- **No visitor text in a reminder.** Custom options are shown only for the store's predefined choices (`drop_down`, `radio`, `checkbox`, `multiple`); free text (such as an engraved message), file names and other types are left out. Product and configurable option names come from the catalog.
- **Greeting** uses only the first word of the name, letters/`-`/`'` only, max 20 characters. The default template uses "Hi %name," when a valid name is available and omits the greeting otherwise.
- The guest subscribe endpoint is anonymous and has no rate limit in code. Without one, a script can create guest carts and make the shop send one reminder to any address – or a paid text to any number (SMS pumping) – up to *Maximum reminders per hour* – delaying real shoppers' reminders in that hour. Adding one is recommended: in your web server, proxy or CDN as the first line of defense – see module-notification's [operations guide](https://github.com/commercekings/module-notification/blob/main/docs/operations.md#rate-limiting). [commercekings/module-rate-limit](https://github.com/commercekings/module-rate-limit) (Magento 2.4.7+) is an optional second line for it and the restore link.
- The recorded consent shows what a browser sent; a scripted client can fake it, like with any cookie banner.
