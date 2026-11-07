// Execute the actual quantity scheduler/request and capture-submit guard without a network.
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
const qty=src.slice(src.indexOf('  var saCheckoutQtyState ='),src.indexOf('  function bindQty('));
const terms=src.slice(src.indexOf('  function saSyncCheckoutTerms()'),src.indexOf('  function saEnsureRememberMe()'));
function harness(){
  const events={},timers=new Map(),requests=[],classes=new Set();let seq=0,refreshes=0;
  const button={disabled:false,setAttribute(){}},box={checked:true,addEventListener(){},removeEventListener(){}};
  const recovery={hidden:true,append(){},prepend(){}},hint={hidden:true};
  const form={classList:{contains(){return false}},dataset:{},addEventListener(n,f){events[n]=f},querySelector(q){if(q.includes('#terms'))return box;if(q==='#place_order')return button;if(q==='[data-sa-terms-hint]')return hint;if(q==='.sa-checkout-recovery')return recovery;if(q==='#sa_pickup_station')return null;if(q==='.sa-checkout-final')return recovery;return null},prepend(){}};
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
  {
    const t=harness(),u={url:'/?wc-ajax=update_order_review'};t.woo.update_checkout();assert(t.button.disabled);
    const x1={status:0,statusText:'abort'},x2={status:502,statusText:'Bad Gateway',responseJSON:undefined},x3={status:200,responseJSON:{result:'success'}};
    t.woo.ajaxSend(null,x1,u);t.woo.ajaxSend(null,x2,u);
    t.woo.ajaxComplete(null,x1,u);assert.notEqual(t.form.dataset.saTotalsFailed,'true','intentional abort of a superseded request is ignored');
    t.woo.ajaxComplete(null,x2,{url:'/other'});assert.notEqual(t.form.dataset.saTotalsFailed,'true','other endpoints ignored');
    t.woo.ajaxComplete(null,x2,u);assert.equal(t.form.dataset.saTotalsFailed,'true','newest request 502 shows failure');assert.equal(t.form.dataset.saTotalsBusy,'true','lock stays closed');assert(t.button.disabled);assert(t.submit(),'submit still blocked');
    t.woo.ajaxSend(null,x3,u);assert.equal(t.form.dataset.saTotalsFailed,'false','a new request clears the failure message only');assert(t.button.disabled,'still locked until success');
    t.woo.updated_checkout(null,{result:'success'});t.woo.ajaxComplete(null,x3,u);assert(!t.button.disabled,'success unlocks');assert.notEqual(t.form.dataset.saTotalsFailed,'true');
    const ab=harness(),a1={status:0,statusText:'abort'};ab.woo.update_checkout();ab.woo.ajaxSend(null,a1,u);ab.woo.ajaxComplete(null,a1,u);assert.notEqual(ab.form.dataset.saTotalsFailed,'true','Woo aborts the old request before sending the new one, so an abort of the still-latest request is not a failure');
    const v=harness(),vf={status:200,responseJSON:{result:'failure',messages:'<ul><li>Enter a valid postcode</li></ul>'}};v.woo.update_checkout();v.woo.ajaxSend(null,vf,u);v.woo.ajaxComplete(null,vf,u);assert.notEqual(v.form.dataset.saTotalsFailed,'true','Woo validation failure keeps its own messages and is not a reload failure');assert(v.button.disabled,'lock unchanged on validation failure');
    for(const bad of [{status:200,responseJSON:undefined},{status:200,responseJSON:{}},{status:504,responseJSON:undefined},{status:0,statusText:'error'}]){const b=harness();b.woo.update_checkout();b.woo.ajaxSend(null,bad,u);b.woo.ajaxComplete(null,bad,u);assert.equal(b.form.dataset.saTotalsFailed,'true','unparseable/empty/proxy failure fails closed with reload message');assert(b.button.disabled);}
    const older={status:502},newer={status:200,responseJSON:{result:'success'}};const q=harness();q.woo.update_checkout();q.woo.ajaxSend(null,older,u);q.woo.ajaxSend(null,newer,u);q.woo.ajaxComplete(null,older,u);assert.notEqual(q.form.dataset.saTotalsFailed,'true','stale older failure cannot mark newest request failed');
  }
  {
    const frag={'.woocommerce-checkout-review-order-table':'<table></table>'};const notice='<div class="woocommerce-message" role="alert">Coupon code applied successfully.</div>';
    const run=(payload)=>{const c=harness();c.woo.update_checkout();assert(c.button.disabled);c.woo.updated_checkout(null,payload);return !c.button.disabled;};
    assert(run({result:'failure',messages:notice,fragments:frag,reload:false}),'benign success notice with fragments unlocks (older Woo, no has_errors)');
    assert(run({result:'failure',has_errors:false,messages:notice,fragments:frag}),'has_errors false unlocks');
    assert(!run({result:'failure',has_errors:true,messages:notice,fragments:frag}),'has_errors true keeps the lock');
    assert(!run({result:'failure',messages:'<ul class="woocommerce-error" role="alert"><li>Enter a valid postcode</li></ul>',fragments:frag}),'error notice keeps the lock');
    assert(!run({result:'failure',messages:'<div class="woocommerce-message">x</div><ul class="woocommerce-error"><li>y</li></ul>',fragments:frag}),'mixed notice with an error keeps the lock');
    assert(!run({result:'failure',messages:notice}),'no fragments keeps the lock');
    assert(!run({result:'failure',messages:notice,fragments:frag,reload:true}),'reload response keeps the lock');
    assert(!run({result:'failure',fragments:frag}),'failure without messages keeps the lock');
    const info='<div class="woocommerce-info" role="alert">Shipping costs updated.</div>';
    assert(run({result:'failure',messages:'\n\t'+notice+'\n',fragments:frag}),'recognised success notice with Woo whitespace unlocks');
    assert(run({result:'failure',messages:notice+info,fragments:frag}),'two recognised notices unlock');
    for(const [name,msg] of [['plain text','Something happened'],['unknown wrapper','<div class="custom-notice">Coupon applied</div>'],['malformed','<div class="woocommerce-message" role="alert">Coupon applied'],['error outside wrapper',notice+'<ul class="woocommerce-error"><li>Bad</li></ul>'],['stray text outside wrapper',notice+'Please wait'],['nested markup in notice','<div class="woocommerce-message" role="alert">Added <a href="/cart">View cart</a></div>'],['block banner','<div class="wc-block-components-notice-banner is-error">Bad</div>'],['script','<div class="woocommerce-message" role="alert"><script>1</script></div>'],['whitespace only','  \n\t ']])assert(!run({result:'failure',messages:msg,fragments:frag}),'unclassified markup stays locked: '+name);
    const q=harness();q.woo.update_checkout();q.classes.add('sa-checkout-qty-busy');q.woo.updated_checkout(null,{result:'failure',messages:notice,fragments:frag});assert(q.button.disabled,'qty lock still wins over a benign notice');
    const f2=harness();f2.woo.update_checkout();f2.form.dataset.saQtyFailed='true';f2.woo.updated_checkout(null,{result:'failure',messages:notice,fragments:frag});assert(f2.button.disabled,'quantity failure latch still wins');
  }
  console.log('PASS actual qty/terms code: immediate debounce lock, capture-submit rejection, coalescing, serialized overlapping writes, authoritative-totals-only unlock, HTTP/JSON/network failure latches and reload recovery');
})().catch(e=>{console.error(e);process.exit(1)});

{
  const src=require('fs').readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/theme.js','utf8');
  const syncAt=src.indexOf('function sync()',src.indexOf('function saSyncCheckoutTerms'));
  const body=src.slice(syncAt,src.indexOf('sync();',syncAt));
  const assert2=require('assert');
  assert2(body.indexOf("failed ? failMsg")<body.indexOf("!addressOK ?"),'quantity failure has hint priority over address/station/busy/terms');
  assert2(body.includes('status.textContent = failed ? failMsg'),'recovery paragraph follows quantity failure');
  assert2(body.indexOf("!addressOK ?")<body.indexOf("!stationOK ?")&&body.indexOf("!stationOK ?")<body.indexOf("busy ? 'Updating")&&body.indexOf("busy ? 'Updating")<body.indexOf("'Accept the terms"),'hint priority address, station, busy, terms');
  assert2(body.includes('hint.hidden = ok;')&&body.includes("btn.disabled = !ok;"),'enable/disable semantics unchanged');
  console.log('PASS reason-specific pay hint priority and quantity-failure recovery text');
}
