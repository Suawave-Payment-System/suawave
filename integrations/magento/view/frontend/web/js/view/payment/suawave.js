define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'suawave',
        component: 'Suawave_Payment/js/view/payment/method-renderer/suawave-method'
    });

    return Component.extend({});
});
