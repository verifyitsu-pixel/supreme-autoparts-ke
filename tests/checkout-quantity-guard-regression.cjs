// Execute the actual quantity scheduler/request and capture-submit guard without a network.
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
const qty=src.slice(src.indexOf('  var saCheckoutQtyState ='),src.indexOf('  function bindQty('));
const terms=src.slice(src.indexOf('  function saSyncCheckoutTerms()'),src.indexOf('  function saEnsureRememberMe()'));
function harness(){
  const events={},timers=new Map(),requests=[],classes=new Set();let seq=0,refreshes=0;
  const button={disabled:false,setAttribute(){}},box={checked:true,addEventListener(){},removeEventListener(){}};
  const recovery={hidden:true,append(){},prepend(){}},hint={hidden:true};
  const form={dataset:{},addEventListener(n,f){events[n]=f},querySelector(q){if(q.includes('#terms'))return box;if(q==='#place_order')return button;if(q==='[data-sa-terms-hint]')return hint;if(q==='.sa-checkout-recovery')return recovery;if(q==='#sa_pickup_station')return null;if(q==='.sa-checkout-final')return recovery;return null},prepend(){}};
  const doc={body:{},documentElement:{classList:{add(x){classes.add(x)},remove(x){classes.delete(x)},contains(x){return classes.has(x)}}},querySelector(q){if(q.includes('form.'))return form;if(q==='#place_order')return button;return null},createElement(){return {hidden:true,append(){},addEventListener(){}}}};
  const woo={};const context={document:doc,window:{},FormData:class{constructor(){this.values={}}append(k,v){this.values[k]=v}},setTimeout(f){timers.set(++seq,f);return seq},clearTimeout(i){timers.delete(i)},Promise,Error,console,fetch(url,options){return new Promise((resolve,reject)=>requests.push({resolve,reject,body:options.body}))}};
  context.window=context;context.jQuery=()=>({on(names,f){for(const n of names.split(' '))woo[n]=f},trigger(n){if(n==='update_checkout')refreshes++;if(woo[n])woo[n]()}});
  vm.createContext(context);vm.runInContext(qty+terms+'saSyncCheckoutTerms();',context);
  const wrap=()=>{const input={value:'2'};return {input,getAttribute(){return 'test-cart-key'},querySelector(){return input}}};
  return {context,form,button,box,recovery,requests,classes,wrap,woo,get refreshes(){return refreshes},schedule(w){context.saScheduleCheckoutQty(w)},fire(){const all=[...timers.values()];timers.clear();for(const f of all)f()},submit(){let prevented=false;events.submit({preventDefault(){prevented=true},stopImmediatePropagation(){},stopPropagation(){}});return prevented}};
}
const settle=async()=>{for(let i=0;i<20;i++)await Promise.resolve()};
(async()=>{
  const h=harness(),w=h.wrap();assert(!h.button.disabled);
  h.schedule(w);assert(h.button.disabled,'lock before debounce or any microtask');assert(h.submit(),'capture guard covers immediate quantity edit');assert.equal(h.requests.length,0);
  h.woo.updated_checkout(null,{result:'success'});assert(h.button.disabled,'unrelated totals success cannot unlock pending quantity');
  w.input.value='3';h.schedule(w);h.fire();await settle();assert.equal(h.requests.length,1);assert.equal(h.requests[0].body.values.qty,'3','debounce sends last typed value');
  w.input.value='4';h.schedule(w);h.fire();await settle();assert.equal(h.requests.length,1,'quantity writes serialized');
  h.requests[0].resolve({ok:true,json:async()=>({success:true,data:{}})});await settle();assert.equal(h.requests.length,2);assert.equal(h.refreshes,0,'do not refresh before queued writes finish');assert(h.button.disabled);assert(h.submit());
  h.requests[1].resolve({ok:true,json:async()=>({success:true,data:{}})});await settle();assert.equal(h.refreshes,1);assert(h.button.disabled,'still locked until authoritative totals');assert(!h.classes.has('sa-checkout-qty-busy'));
  h.woo.updated_checkout(null,{result:'failure'});assert(h.button.disabled);h.woo.updated_checkout(null,{result:'success'});assert(!h.button.disabled);
  for(const failure of ['json','http','network']){
    const f=harness();f.schedule(f.wrap());f.fire();await settle();
    if(failure==='network')f.requests[0].reject(new Error('offline'));else f.requests[0].resolve({ok:failure!=='http',json:async()=>({success:false})});
    await settle();assert.equal(f.form.dataset.saQtyFailed,'true');assert(f.button.disabled);assert(f.submit());assert(f.classes.has('sa-checkout-qty-busy'));assert.equal(f.refreshes,0);assert(!f.recovery.hidden,'safe reload recovery visible');f.woo.updated_checkout(null,{result:'success'});assert(f.button.disabled,'unrelated success never clears failed quantity write');
  }
  console.log('PASS actual qty/terms code: immediate debounce lock, capture-submit rejection, coalescing, serialized overlapping writes, authoritative-totals-only unlock, HTTP/JSON/network failure latches and reload recovery');
})().catch(e=>{console.error(e);process.exit(1)});
