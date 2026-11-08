(function () {
    'use strict';
    var registry = window.wc && window.wc.wcBlocksRegistry;
    var settings = window.wc && window.wc.wcSettings;
    var element = window.wp && window.wp.element;
    var entities = window.wp && window.wp.htmlEntities;
    if (!registry || !settings || !element || !entities) {
        return;
    }
    var data = settings.getSetting('suawave_data', {});
    var title = entities.decodeEntities(data.title || 'Pay with Suawave (USDT)');
    var description = entities.decodeEntities(data.description || '');
    var Label = function () {
        return element.createElement('span', null, title);
    };
    var Content = function () {
        return element.createElement('div', null, description);
    };
    registry.registerPaymentMethod({
        name: 'suawave',
        label: element.createElement(Label, null),
        ariaLabel: title,
        content: element.createElement(Content, null),
        edit: element.createElement(Content, null),
        canMakePayment: function () {
            return true;
        },
        supports: {
            features: data.supports || ['products']
        }
    });
})();
