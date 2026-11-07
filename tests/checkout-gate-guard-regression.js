// Behavioural check of the address-gate guards (extracted from theme.js): an unchanged-value change event must not
// reopen the confirmed address, and a payment-only checkout_error must not relock payment. Address errors still reopen.
const fs=require('fs'),assert=require('assert');
const s=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
const m=s.match(/function addressRelatedError\(html\)\{.*\}\n/);assert(m,'classifier present');
const addressRelatedError=eval('('+m[0].trim()+')');
const A=addressRelatedError;
const ul=(...li)=>'<ul class="woocommerce-error" role="alert">'+li.map(l=>'<li>'+l+'</li>').join('')+'</ul>';
// fail closed (reopen = true) for everything that is not a complete, exactly-shaped known payment failure
assert.strictEqual(A('<ul><li data-id="billing_email">Email is required</li></ul>'),true,'field error reopens');
assert.strictEqual(A(ul('Please enter a valid postcode.')),true,'address wording reopens');
assert.strictEqual(A(ul('Something unexpected happened')),true,'unknown reopens');
assert.strictEqual(A(ul('Processing failed')),true,'generic processing reopens');
assert.strictEqual(A(ul('Card number looks wrong')),true,'generic card reopens');
assert.strictEqual(A(ul('Payment failed: invalid billing address')),true,'mixed in one notice reopens');
assert.strictEqual(A(ul('Payment failed. Product out of stock.')),true,'payment + stock in one li reopens');
assert.strictEqual(A(ul('Payment declined.','Coupon code is invalid.')),true,'payment + coupon notices reopen');
assert.strictEqual(A(ul('Payment failed.','Please select a collection point.')),true,'payment + collection point reopens');
assert.strictEqual(A('<div>Payment failed.</div><div>Product out of stock.</div>'),true,'div markup reopens');
assert.strictEqual(A('<div class="x"><ul><li>Payment failed.</li></ul></div>'),true,'wrapper reopens');
assert.strictEqual(A('<UL><LI>Payment failed.</LI><LI>Unexpected error</LI></UL>'),true,'uppercase known + unknown reopens');
assert.strictEqual(A('Payment declined.'),true,'plain text (no list) reopens');
assert.strictEqual(A('<ul><li>Payment failed.<b>x</b></li></ul>'),true,'nested markup reopens');
assert.strictEqual(A('<ul><li>Payment failed &amp; more</li></ul>'),true,'entities reopen');
assert.strictEqual(A(''),true,'empty reopens');
assert.strictEqual(A('<ul><li></li></ul>'),true,'empty notice reopens');
assert.strictEqual(A(ul('Payment declined.')),false,'complete known payment failure keeps payment unlocked');
assert.strictEqual(A(ul('There was an error processing your payment. Please try again.')),false,'core wording keeps payment unlocked');
assert.strictEqual(A('<UL><LI>Payment failed.</LI></UL>'),false,'uppercase complete known notice passes');
assert.strictEqual(A(ul('Payment failed.','Transaction declined.')),false,'several complete known notices pass');
// unchangedField
const u=s.match(/function fieldVal\(e\)\{[^}]*\}/);assert(u,'fieldVal present');
const f=s.match(/function unchangedField\(t\)\{[^}]*\}/);assert(f,'unchangedField present');
let snap={billing_city:'Nairobi',ship:'',billing_terms:''};const form={dataset:{saAddressReady:'true'}};
const fieldVal=eval('('+u[0].trim()+')');const unchangedField=eval('('+f[0].trim()+')');
assert.strictEqual(unchangedField({name:'billing_city',type:'text',value:'Nairobi'}),true,'same value ignored');
assert.strictEqual(unchangedField({name:'billing_city',type:'text',value:'Mombasa'}),false,'changed value reopens');
assert.strictEqual(unchangedField({name:'billing_other',type:'text',value:'x'}),false,'unknown field reopens');
form.dataset.saAddressReady='false';assert.strictEqual(unchangedField({name:'billing_city',type:'text',value:'Nairobi'}),false,'not ready: normal path');
form.dataset.saAddressReady='true';
assert.strictEqual(unchangedField({name:'billing_terms',type:'checkbox',checked:false,value:'1'}),true,'unchecked checkbox unchanged');
assert.strictEqual(unchangedField({name:'billing_terms',type:'checkbox',checked:true,value:'1'}),false,'checked checkbox changed');
assert(s.includes("snap=snapshot();gate(true)"),'snapshot taken at confirm');
assert(s.includes("if(!unchangedField(e.target))reopen();"),'change handler uses guard');
assert(s.includes(".on('checkout_error',function(ev,html){if(form.dataset.saAddressReady==='true'&&!addressRelatedError(html))return;reopen();})"),'error handler guarded');
console.log('checkout gate guard checks PASS (extracted functions, not browser proof)');
