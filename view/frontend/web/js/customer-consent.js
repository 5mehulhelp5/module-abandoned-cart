/**
 * Every page - logged-in customer on checkout (no email field - the email comes from the account):
 * subscribe their cart through CommerceKings_Notification when checkout opens or the permission is switched
 * on there: POST /V1/ck-notification/subscriptions/mine {type, reference: quote id} (session REST, AJAX).
 * The customer's equivalent of a guest typing the email. Permission: CommerceKings_Notification/js/permission
 * (with the consent bridge: the consent choice; its withdrawals remove the subscription).
 */
define(['jquery', 'CommerceKings_Notification/js/permission'], function ($, permission) {
    'use strict';

    var PURPOSE = 'cart_reminder';

    function customerOnCheckout() {
        return !!(window.checkoutConfig && window.checkoutConfig.isCustomerLoggedIn &&
            window.checkoutConfig.quoteData && window.checkoutConfig.quoteData.entity_id);
    }

    function capture() {
        // loaded here: the url builder reads window.checkoutConfig, which only checkout has
        require(['mage/storage', 'Magento_Checkout/js/model/url-builder'], function (storage, urlBuilder) {
            // Loading dependencies is asynchronous: checkout, login or permission may have changed.
            if (!customerOnCheckout() || !permission.hasPermission(PURPOSE)) {
                return;
            }
            storage.post(
                urlBuilder.createUrl('/ck-notification/subscriptions/mine', {}),
                JSON.stringify({type: PURPOSE, reference: String(window.checkoutConfig.quoteData.entity_id)}),
                false
            );
        }, function () {
            // Leave capture unstored if dependencies fail; do not interrupt checkout or retry in a loop.
        });
    }

    permission.onChange(PURPOSE, function (isOn) {
        if (isOn && customerOnCheckout()) {
            capture();
        }
    });

    $(function () {
        if (customerOnCheckout() && permission.hasPermission(PURPOSE)) {
            capture();
        }
    });
});
