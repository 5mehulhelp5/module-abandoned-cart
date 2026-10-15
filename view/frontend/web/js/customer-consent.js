/**
 * Every page - logged-in customer on checkout (no email field - the email comes from the account):
 * subscribe their cart through CommerceKings_Notification when checkout opens or the consent is switched
 * on there: POST /V1/ck-notification/subscriptions/mine {type, reference: quote id} (session REST, AJAX).
 * The customer's equivalent of a guest typing the email. Withdrawals are handled by CommerceKings_Consent
 * (beacon + Notification's registered withdrawal handler).
 */
define(['jquery', 'CommerceKings_Consent/js/consent/provider'], function ($, consent) {
    'use strict';

    var PURPOSE = 'cart_reminder';

    function customerOnCheckout() {
        return !!(window.checkoutConfig && window.checkoutConfig.isCustomerLoggedIn);
    }

    function capture() {
        // loaded here: the url builder reads window.checkoutConfig, which only checkout has
        require(['mage/storage', 'Magento_Checkout/js/model/url-builder'], function (storage, urlBuilder) {
            storage.post(
                urlBuilder.createUrl('/ck-notification/subscriptions/mine', {}),
                JSON.stringify({type: PURPOSE, reference: String(window.checkoutConfig.quoteData.entity_id)}),
                false
            );
        });
    }

    consent.onChange(PURPOSE, function (isOn) {
        if (isOn && customerOnCheckout()) {
            capture();
        }
    });

    $(function () {
        if (customerOnCheckout() && consent.hasConsent(PURPOSE)) {
            capture();
        }
    });
});
