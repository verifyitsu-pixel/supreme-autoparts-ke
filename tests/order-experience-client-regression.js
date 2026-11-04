const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync('wp-content/plugins/supreme-autoparts-core/includes/order-experience.php','utf8').match(/<script>([\s\S]*?)<\/script>/)[1];
(async()=>{
 for(const success of [true,false]){
  const handlers={},button={disabled:false},status={textContent:''},endpoint='https://example.invalid/wp-admin/admin-ajax.php';let request;
  const f={action:{tagName:'INPUT',name:'action',value:'sa_order_experience'},getAttribute:k=>k==='action'?endpoint:null,addEventListener:(k,v)=>handlers[k]=v,reportValidity:()=>true,querySelector:s=>s==='button'?button:status};
  class FormData {constructor(form){assert.strictEqual(form,f);this.action='sa_order_experience';}}
  vm.runInNewContext(source,{document:{querySelector:()=>f},FormData,Number,Error,fetch:async(url,options)=>{request={url,options};return {json:async()=>success?{success:true}:{success:false,data:{message:'Please refresh this confirmation page and try again.'}}};}});
  let prevented=false;await handlers.submit({preventDefault(){prevented=true;}});
  assert(prevented);assert.strictEqual(request.url,endpoint);assert.strictEqual(request.options.method,'POST');assert.strictEqual(request.options.credentials,'same-origin');assert.strictEqual(request.options.body.action,'sa_order_experience');assert.strictEqual(button.disabled,false);assert(status.textContent.includes(success?'has been saved':'Please refresh'));
 }
 console.log('PASS named-action shadowing, correct AJAX URL/body, success/error status, button recovery (mocked transport, no production write)');
})().catch(e=>{console.error(e);process.exit(1);});
