(function(window) {
    // Ensure required globals are available
    if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp) {
        console.error('Required WooCommerce Blocks dependencies not found');
        return;
    }

    const settings = window.pesapalBlocksData?.gatewayData || {};
    
    const Content = () => {
        return window.wp.element.createElement('div', {
            dangerouslySetInnerHTML: { 
                __html: window.wp.htmlEntities.decodeEntities(settings.description || '') 
            }
        });
    };

    const paymentMethod = {
        name: 'pesapal',
        label: window.wp.htmlEntities.decodeEntities(settings.title || 'Pesapal'),
        content: window.wp.element.createElement(Content, null),
        edit: window.wp.element.createElement(Content, null),
        canMakePayment: () => true,
        ariaLabel: window.wp.htmlEntities.decodeEntities(settings.title || 'Pesapal'),
        supports: settings.supports || {},
    };

    try {
        window.wc.wcBlocksRegistry.registerPaymentMethod(paymentMethod);
        console.log('Pesapal payment method registered successfully');
    } catch (error) {
        console.error('Failed to register Pesapal payment method:', error);
    }
})(window);