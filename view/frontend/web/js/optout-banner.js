/**
 * Opt-out confirmation page: if this browser still has the reminder consent on in the consent source,
 * switch only that off - the other choices stay. The opt-out itself is already done on the server;
 * this only keeps the source (e.g. the cookie banner) truthful.
 */
define(['CommerceKings_Consent/js/consent/provider'], function (consent) {
    'use strict';

    var PURPOSE = 'cart_reminder';

    return function () {
        if (consent.hasConsent(PURPOSE)) {
            consent.revoke(PURPOSE);
        }
    };
});
