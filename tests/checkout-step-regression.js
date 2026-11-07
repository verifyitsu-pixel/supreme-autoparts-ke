// The old three-step wizard has been replaced by the observed one-page hierarchy.
const fs=require('fs'),assert=require('assert');const s=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
const flow=s.slice(s.indexOf('  function saCheckoutExperience()'),s.indexOf('  function saCheckoutStickyPay()')),one=s.slice(s.indexOf('  function saCheckoutOnePage()'),s.indexOf('  function saCartWorkspace()'));
for(const x of ['saCheckoutSteps','Next: Shipping','Next: Payment','data-sa-next-index'])assert(!s.includes(x),x);
for(const x of ['form.dataset.saAddressReady','Use this address','Use these pickup details','body.inert=!ready','Change','sa:addresschange','checkout_error','sa_pickup_station','e.stopImmediatePropagation()','valid(true)','shipping_address','shipping_first_name','billing_email'])assert(one.includes(x),x);
for(const x of ['fetch(','XMLHttpRequest','localStorage','form.submit(','payment_complete'])assert(!one.includes(x),x);
for(const x of ['main.appendChild(payment)','final.appendChild(fresh)','tbody.appendChild(row)','row.replaceWith(replacement)','old.remove()','box.checked=prior.checked',"country!=='KE'||lists.length!==1",'updated_checkout'])assert(flow.includes(x),x);
assert(!flow.includes('compressEstimates(delivery)'),'retain actual per-option estimates');assert(flow.includes('sa-fulfilment-tabs'));
const terms=s.slice(s.indexOf('  function saSyncCheckoutTerms()'),s.indexOf('  function saEnsureRememberMe()'));
for(const x of ['addressOK','sa:addresschange',"form.dataset.saTotalsBusy='true'","data&&data.result==='success'",'sa-checkout-qty-busy','saQtyFailed','stationOK','Reload checkout','e.stopImmediatePropagation()'])assert(terms.includes(x),x);
const css=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/css/cart-ui.css','utf8');for(const x of ['font-size:16px!important','font-size:24px!important','.sa-payment-locked','.sa-address-confirmed','.sa-cart-shipping-summary','.sa-cart-quantity-select'])assert(css.includes(x),x);
const assets=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/inc/assets.php','utf8');assert(assets.includes("'.checkout16'"));assert(assets.includes("'.cu18'"));
const cart=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/woocommerce/cart/cart.php','utf8');assert(!cart.includes('sa-cart-line__buy'));assert(!cart.includes('sa-cart-line__was'));assert(cart.includes('sa-cart-delivery__options'));assert(cart.includes('woocommerce_quantity_input'));assert(cart.includes("wp_nonce_field('woocommerce-cart'"));
assert(s.includes("cart.dataset.saCartBusy='true'"));assert(s.includes("root.dataset.saCartBusy='false'"));assert(s.includes('Updating your cart total'));
console.log('PASS coherent one-page gate/required-field/capture-submit source contracts, no wizard, retained native controls and guarded totals, per-option estimate, cart real qty/nonce/remove/calculator/summary and larger phone text. Actual browser interaction proof separate.');
require('./checkout-quantity-guard-regression.cjs');
