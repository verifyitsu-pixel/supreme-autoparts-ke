const fs=require('fs'),vm=require('vm'),assert=require('assert');
const php=fs.readFileSync('wp-content/plugins/supreme-autoparts-core/includes/order-experience.php','utf8');
assert(/class="sa-experience__form" data-sa-loader-skip/.test(php));
const loader=fs.readFileSync('wp-content/themes/supreme-autoparts/assets/js/sa-loader.js','utf8');
const ignore=loader.match(/function isIgnorableForm\(form\) \{[\s\S]*?\n  \}/)[0];
assert(vm.runInNewContext(ignore+';isIgnorableForm({hasAttribute:k=>k==="data-sa-loader-skip"})'));
const source=php.match(/<script>([\s\S]*?)<\/script>/)[1];
(async()=>{for(const mode of ['success','server-error','network','json','hung-fetch','hung-body']){
 const handlers={},b={disabled:false},r={textContent:''};let calls=0,timer;
 const f={dataset:{},getAttribute:()=>'/wp-admin/admin-ajax.php',addEventListener:(k,v)=>handlers[k]=v,reportValidity:()=>true,querySelector:s=>s==='button'?b:r};
 const fetch=async()=>{calls++;if(mode==='network')throw Error('Offline');if(mode==='hung-fetch')return new Promise(()=>{});return {json:async()=>{if(mode==='hung-body')return new Promise(()=>{});if(mode==='json')throw Object.assign(Error('bad JSON'),{name:'SyntaxError'});return {success:mode!=='server-error',data:{message:'Refresh this page.'}};}};};
 vm.runInNewContext(source,{document:{querySelector:()=>f},FormData:class{},Number,Error,Promise,AbortController,fetch,setTimeout:fn=>(timer=fn,1),clearTimeout:()=>{}});
 const p=handlers.submit({preventDefault(){}});assert(b.disabled);await handlers.submit({preventDefault(){}});assert.equal(calls,1);
 if(mode.startsWith('hung'))timer();await p;assert(!b.disabled);assert(!f.dataset.saving);assert(!r.textContent.includes('Saving your'));
 if(mode==='success')assert(r.textContent.includes('has been saved'));if(mode.startsWith('hung'))assert(r.textContent.includes('may have saved'));
 console.log('PASS '+mode+' status/button/duplicate recovery');
 }})();
