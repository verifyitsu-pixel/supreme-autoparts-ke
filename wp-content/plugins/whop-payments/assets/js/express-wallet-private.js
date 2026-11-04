/* Private preparation, deliberately not enqueued. No provider secrets or direct charge here. */
(function (root) {
'use strict';
async function prepare(options) {
  if (!options.privatePreview || !options.serverQuote || !options.elements) throw new Error('Express preview unavailable');
  const quote = options.serverQuote;
  if (quote.currency !== 'USD' || !Number.isSafeInteger(quote.total_minor) || quote.total_minor < 1 || quote.total_minor > 250000) throw new Error('Unsupported quote');
  const request = options.elements.payments.paymentRequest.create({
    accountId: options.accountId, currency: quote.currency, amount: quote.total_minor,
    requestPayerEmail: true, requestPayerPhone: true, requestShipping: !!quote.requires_shipping,
    shippingOptions: quote.shipping_options || [], lineItems: quote.line_items || [],
    paymentMethodConfiguration: { enabled: ['apple_pay', 'google_pay'], disabled: ['paypal'], include_platform_defaults: false }
  });
  const available = await request.canMakePayment({ types: ['apple_pay', 'google_pay'] });
  const unsubscribers = [];
  if (quote.requires_shipping) {
    for (const [event, kind] of [['onShippingAddressChange','address'],['onShippingOptionChange','option']]) {
      unsubscribers.push(request[event](function (change) {
        // Server callback must derive current prices, never accept client prices.
        change.updateWith(Promise.resolve().then(() => options.requote(kind, change)).catch(() => ({errors:[{code:'shipping_address_unserviceable',message:'Delivery could not be confirmed. Please use regular checkout.'}]})));
      }));
    }
  }
  unsubscribers.push(request.onPaymentAuthorization(function (event) {
    event.respondWith(Promise.resolve().then(() => options.validateAuthorization(event, quote)).then(ok => ok === true ? {approved:true} : {approved:false,message:'Order details changed. Please review your order.'}).catch(() => ({approved:false,message:'Could not verify your order. Please use regular checkout.'})));
  }));
  return {
    available,
    // Invoke directly from a real click, after prepare has resolved.
    show(type) {
      if (!['apple_pay','google_pay'].includes(type) || !available.wallets || !available.wallets[type]) throw new Error('Wallet unavailable');
      if (!options.termsAccepted()) throw new Error('Accept the displayed policies first');
      return request.show(type); // ctok result goes only to a future reviewed server confirmation route.
    },
    destroy() { unsubscribers.forEach(fn => { if (typeof fn === 'function') fn(); }); if (typeof request.destroy === 'function') request.destroy(); }
  };
}
root.SupremeWhopExpressPrivate = { prepare };
})(typeof window === 'undefined' ? globalThis : window);
