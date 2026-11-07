// Behavioural check of the address-gate guards (extracted from theme.js): an unchanged-value change event must not
// reopen the confirmed address, and a payment-only checkout_error must not relock payment. Address errors still reopen.
const fs=require('fs'),assert=require('assert');
const s=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
const m=s.match(/function addressRelatedError\(html\)\{.*\}\n/);assert(m,'classifier present');
const addressRelatedError=eval('('+m[0].trim()+')');
const A=addressRelatedError;
assert.strictEqual(A('<ul><li data-id="billing_email">Email is required</li></ul>'),true,'field error reopens');
assert.strictEqual(A('<li>Please enter a valid postcode</li>'),true,'address wording reopens');
assert.strictEqual(A('<ul class="woocommerce-error"><li>There was an error processing your payment. Please try again.</li></ul>'),false,'known payment failure keeps payment unlocked');
assert.strictEqual(A('<li>Payment declined.</li>'),false,'payment declined');
assert.strictEqual(A('<li>Paystack could not start the transaction.</li>'),false,'gateway start failure');
assert.strictEqual(A('<li>Something unexpected happened</li>'),true,'unknown reopens');
assert.strictEqual(A('<li>Processing failed</li>'),true,'generic processing is unknown: reopens');
assert.strictEqual(A('<li>Card number looks wrong</li>'),true,'generic card wording is unknown: reopens');
assert.strictEqual(A('<li>Please check your bank details</li>'),true,'generic bank wording is unknown: reopens');
assert.strictEqual(A('<li>Payment failed: invalid billing address</li>'),true,'mixed in one notice reopens');
assert.strictEqual(A('<ul><li>Payment declined.</li><li>Coupon code is invalid.</li></ul>'),true,'payment + coupon notices reopen');
assert.strictEqual(A('<ul><li>Payment failed.</li><li>Please select a collection point.</li></ul>'),true,'payment + collection point reopens');
assert.strictEqual(A('<ul><li>Payment declined.</li><li>Payment failed.</li></ul>'),false,'only known payment notices stay unlocked');
assert.strictEqual(A('Payment declined.'),false,'plain text known payment failure');
assert.strictEqual(A(''),true,'empty reopens');
assert.strictEqual(A('<ul><li></li></ul>'),true,'no notice text reopens');
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
