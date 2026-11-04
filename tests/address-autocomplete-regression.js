const fs=require('fs'),vm=require('vm'),assert=require('assert');
const s=fs.readFileSync(__dirname+'/../wp-content/plugins/supreme-autoparts-core/includes/address-autocomplete.php','utf8');
const a=s.indexOf('function stateNorm('),b=s.indexOf('var pickGeneration',a);const ctx={};vm.createContext(ctx);vm.runInContext(s.slice(a,b),ctx);
const st={tagName:'SELECT',options:[{value:'',text:'Select'},{value:'30',text:'Nairobi'},{value:'22',text:'Kiambu'}]};
assert.equal(ctx.stateValue(st,{state:'Nairobi County',country:'KE'}),'30');assert.equal(ctx.stateValue(st,{state_code:'KE-22',state:'Nairobi',country:'KE'}),'22');assert.equal(ctx.stateValue(st,{state:'Unknown County',country:'KE'}),'');assert.equal(ctx.stateValue({tagName:'INPUT'},{state:'Free Region'}),'Free Region');assert.equal(ctx.stateValue(st,{state:'',country:'KE'}),'');
for(const x of ["'country' => strtoupper", "'state_code' =>", "'sa_aa_v2_'", "setVal('billing_postcode',it.postcode||'')", "setVal('billing_address_2',it.line2||'')", "MutationObserver", "country()!==queryCountry"])assert(s.includes(x),x);
assert(!s.includes('if(e&&v)'));
console.log('PASS 5 state mapping cases, 7 country/cache/stale/empty-field/async contracts; no HTTP');
