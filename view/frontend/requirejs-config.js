var config = {
    // every page: customer consent capture on checkout (withdrawal: CommerceKings_Consent)
    deps: ['CommerceKings_AbandonedCart/js/customer-consent'],
    config: {
        mixins: {
            // guest checkout: send the typed email to the cart for the abandoned cart reminder
            'Magento_Checkout/js/view/form/element/email': {
                'CommerceKings_AbandonedCart/js/email-capture-mixin': true
            }
        }
    }
};
