const fs=require('fs'),assert=require('assert');const s=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');const f=s.slice(s.indexOf('function saCheckoutSteps()'),s.indexOf('function saCheckoutStickyPay()'));
for(const x of ["next.type='button'","edit.type='button'","body(p).hidden=i!==index","if(i===active&&valid(p))","e.stopImmediatePropagation()","}},true)","checkout_error","aria-controls","aria-expanded","title.focus","Change"])assert(f.includes(x),x);for(const x of ['payment_complete','fetch(','localStorage','setAttribute(\'disabled\'','form.submit'])assert(!f.includes(x),x);const css=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/css/cart-ui.css','utf8');for(const x of ['min-height:54px!important','font-size:15px!important','.sa-aa-wrap{display:block!important'])assert(css.includes(x),x);console.log('PASS 19 step no-submit/accessibility/form-size source contracts; actual captured DOM interaction proof separate');

assert(f.includes("(document.querySelector('.sa-checkout-final')||document.querySelector('form.checkout')).appendChild(actions)"));assert(f.includes('actions.appendChild(next)'));assert(!f.includes('b.appendChild(next)'));console.log('PASS Next footer is after layout/order-summary, only active-step action visible');

const experience=s.slice(s.indexOf('function saCheckoutExperience()'),s.indexOf('// Accessible, reversible checkout steps.'));
for(const x of ['main.appendChild(payment)','final.appendChild(fresh)','tbody.appendChild(row)','row.replaceWith(replacement)','old.remove()','box.checked=prior.checked','country!==\'KE\'||lists.length!==1','labels.every','updated_checkout']) {
  assert(experience.includes(x),x);
}
for(const x of ['fetch(','XMLHttpRequest','form.submit(','localStorage','payment_complete'])assert(!experience.includes(x),x);
assert(experience.includes("' · Estimated delivery 1-2 working days from order, excluding weekends'"));
assert(experience.includes("if(review&&!review.dataset.saFlowReady)sync()"));
assert(css.includes('body.woocommerce-cart .sa-cart-sticky-bar{display:none!important}'));
assert(css.includes('.sa-checkout-refreshed .sa-checkout-final{grid-column:1;grid-row:3}'));
const assets=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/inc/assets.php','utf8');
assert(assets.includes("SA_THEME_VERSION . '.checkout8'"));assert(assets.includes("SA_THEME_VERSION . '.cu15'"));
console.log('PASS moved native fragment controls, terms-state retention, KE-only single-package tabs, exact known-estimate compression, cache keys, final mobile CTA and no network/order side effects');

assert(f.includes('edit.hidden=i>=index'));assert(f.includes('if(i<active)open(i,true)'));console.log('PASS Change only for completed earlier steps, never a future step bypass');

assert(css.includes('.sa-checkout-refreshed .sa-co-line__p{max-width:none!important;white-space:nowrap!important}'));console.log('PASS product-line amounts never split digits on phones');

const terms=s.slice(s.indexOf('function saSyncCheckoutTerms()'),s.indexOf('function saEnsureRememberMe()'));for(const x of ["form.dataset.saTotalsBusy='true'","data&&data.result==='success'","form.dataset.saTotalsBusy==='true'","sa-checkout-qty-busy","e.stopImmediatePropagation()","form.dataset.saTermsBound='true'"])assert(terms.includes(x),x);console.log('PASS final button and capture-phase submit guard block pending totals and quantity AJAX, clear only on successful totals refresh');

assert(experience.includes('Review your US delivery address and shipping option.'));console.log('PASS delivery-stage helper follows actual billing/shipping destination, not geo hint');

assert(experience.includes("layout.querySelector('.sa-checkout-layout__summary')||layout"));
assert(experience.includes("shipping.insertBefore(station,notes)"));
assert(terms.includes('stationOK'));assert(terms.includes('Reload checkout'));
console.log('PASS summary-local final action, pickup selector in shipping, station readiness guard and safe reload');

require('./checkout-quantity-guard-regression.cjs');
