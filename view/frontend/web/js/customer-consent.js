/**
 * Every page - logged-in customer on checkout (no email field - the email comes from the account):
 * store the reminder consent on their cart when checkout opens or the consent is switched on there.
 * The customer's equivalent of a guest typing the email. Withdrawals are handled by
 * CommerceKings_Consent (beacon + the registered WithdrawalHandler).
 */
define(['jquery', 'CommerceKings_Consent/js/consent/provider', 'mage/cookies'], function ($, consent) {
    'use strict';

    var PURPOSE = 'cart_reminder';

    function customerOnCheckout() {
        return !!(window.checkoutConfig && window.checkoutConfig.isCustomerLoggedIn);
    }

    function capture() {
        $.ajax({
            url: window.BASE_URL + 'cartreminder/consent/capture',
            type: 'POST',
            data: {form_key: $.mage.cookies.get('form_key')},
            global: false
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
