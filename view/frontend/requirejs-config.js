var config = {
    // every page: subscribes a logged-in customer's cart on checkout (permission: CommerceKings_Notification/js/permission)
    deps: ['CommerceKings_AbandonedCart/js/customer-consent'],
    config: {
        mixins: {
            // guest checkout: subscribe the cart with the typed email for the abandoned cart reminder
            'Magento_Checkout/js/view/form/element/email': {
                'CommerceKings_AbandonedCart/js/email-capture-mixin': true
            }
        }
    }
};
