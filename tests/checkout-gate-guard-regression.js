// Behavioural check of the address-gate guards (extracted from theme.js): an unchanged-value change event must not
// reopen the confirmed address, and a payment-only checkout_error must not relock payment. Address errors still reopen.
const fs=require('fs'),assert=require('assert');
const s=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
const m=s.match(/function addressRelatedError\(html\)\{[^}]*\}/);assert(m,'classifier present');
const addressRelatedError=eval('('+m[0].trim()+')');
assert.strictEqual(addressRelatedError('<ul><li data-id="billing_email">Email is required</li></ul>'),true,'field error reopens');
assert.strictEqual(addressRelatedError('<li>Please enter a valid postcode</li>'),true,'address wording reopens');
assert.strictEqual(addressRelatedError('<li>There was an error processing your payment. Please try again.</li>'),false,'payment error keeps payment unlocked');
assert.strictEqual(addressRelatedError('<li>Paystack could not start the transaction.</li>'),false,'gateway error keeps payment unlocked');
assert.strictEqual(addressRelatedError('<li>Something unexpected happened</li>'),true,'unknown error keeps existing reopen');
assert.strictEqual(addressRelatedError('<li>Payment failed: invalid billing address</li>'),true,'mixed wording reopens');
assert.strictEqual(addressRelatedError(''),true,'empty keeps existing reopen');
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
