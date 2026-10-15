/**
 * Guest checkout email field -> abandoned cart reminder.
 *
 * Only with consent for purpose cart_reminder (CommerceKings_Consent/js/consent/provider). On checkout
 * entry (prefilled email) and 1 s after the last change of a valid email, subscribe the cart through
 * CommerceKings_Notification: POST /V1/ck-notification/subscriptions {type, reference: masked cart id, email}.
 * Saves and clears are
 * serialized, acknowledged by the server, and retried after transient failures.
 * If the consent is switched off while on checkout, the same endpoint is called with an empty email
 * and the server clears what it stored. The server re-reads the consent itself - this check only
 * avoids sending the email without consent. Logged-in customers are skipped (js/customer-consent.js).
 */
define([
    'CommerceKings_Consent/js/consent/provider',
    'mage/storage',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/url-builder',
    'Magento_Customer/js/model/customer',
    'CommerceKings_AbandonedCart/js/guest-email-capture'
], function (consent, storage, quote, urlBuilder, customer, capture) {
    'use strict';

    var PURPOSE = 'cart_reminder';

    function send(cartId, email) {
        return storage.post(
            urlBuilder.createUrl('/ck-notification/subscriptions', {}),
            JSON.stringify({type: PURPOSE, reference: cartId, email: email}),
            false
        );
    }

    return function (Component) {
        return Component.extend({
            ckTimer: null,

            initialize: function () {
                var self = this;
                this._super();
                this.ckCapture = capture(send);
                this.ckOnline = function () { self.ckCapture.online(); };
                window.addEventListener('online', this.ckOnline);
                // consent given / switched on or off while on checkout
                consent.onChange(PURPOSE, function () {
                    self.ckSync(true);
                });
                // an email Magento prefilled (typed earlier, maybe before consent was given) - no
                // change event fires for it, so sync once on checkout entry
                this.ckTimer = setTimeout(function () {
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
                this.ckCapture.update({
                    cartId: quote.getQuoteId(),
                    email: this.email(),
                    allowed: consent.hasConsent(PURPOSE),
                    loggedIn: customer.isLoggedIn()
                }, consentChanged);
            },

            destroy: function () {
                clearTimeout(this.ckTimer);
                window.removeEventListener('online', this.ckOnline);
                this.ckCapture.destroy();
                return this._super();
            }
        });
    };
});
