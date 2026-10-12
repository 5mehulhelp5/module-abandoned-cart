/**
 * Guest checkout email field -> abandoned cart reminder.
 *
 * Only with consent for purpose cart_reminder (CommerceKings_Consent/js/consent/provider). On checkout
 * entry (prefilled email) and 1 s after the last
 * change of a valid email, POST /V1/guest-carts/:cartId/ck-abandoned-email (once per distinct email).
 * If the consent is switched off while on checkout, the same endpoint is called with an empty email
 * and the server clears what it stored. The server re-reads the consent itself - this check only
 * avoids sending the email without consent. Logged-in customers are skipped (js/customer-consent.js).
 */
define([
    'CommerceKings_Consent/js/consent/provider',
    'mage/storage',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'Magento_Customer/js/model/customer'
], function (consent, storage, quote, urlBuilder, customer) {
    'use strict';

    var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/,
        PURPOSE = 'cart_reminder';

    function send(email) {
        if (!quote.getQuoteId()) {
            return;
        }
        storage.post(
            urlBuilder.createUrl('/guest-carts/:cartId/ck-abandoned-email', {cartId: quote.getQuoteId()}),
            JSON.stringify({email: email}),
            false
        );
    }

    return function (Component) {
        return Component.extend({
            ckLastSent: null,
            ckTimer: null,

            initialize: function () {
                var self = this;
                this._super();
                // consent given / switched on or off while on checkout
                consent.onChange(PURPOSE, function () {
                    self.ckSync(true);
                });
                // an email Magento prefilled (typed earlier, maybe before consent was given) - no
                // change event fires for it, so sync once on checkout entry
                setTimeout(function () {
                    self.ckSync(false);
                }, 1000);
                return this;
            },

            emailHasChanged: function () {
                var result = this._super(),
                    self = this;
                clearTimeout(this.ckTimer);
                this.ckTimer = setTimeout(function () {
                    self.ckSync(false);
                }, 1000);
                return result;
            },

            ckSync: function (consentChanged) {
                var email = (this.email() || '').trim();
                if (customer.isLoggedIn()) {
                    return;
                }
                if (!consent.hasConsent(PURPOSE)) {
                    if (consentChanged && this.ckLastSent !== null) {
                        this.ckLastSent = null;
                        send('');   // withdraw
                    }
                    return;
                }
                if (!EMAIL.test(email) || email === this.ckLastSent) {
                    return;
                }
                this.ckLastSent = email;
                send(email);
            }
        });
    };
});
