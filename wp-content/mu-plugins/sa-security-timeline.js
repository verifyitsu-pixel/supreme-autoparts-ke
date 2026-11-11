/* Private wp-admin presentation only. Never captures visits or changes stored order notes. */
(function(root){
'use strict';
function describe(text){
 var lines=text.split('\n'),head=lines[0]||'',summary='';
 function field(prefix){var row=lines.find(function(s){return s.indexOf(prefix)===0;});return row?row.slice(prefix.length).trim():'';}
 if(head==='Local security checks | Notify only'){
  var indicators=field('Indicators:');summary=indicators.indexOf('No configured indicator matched')===0?'Security checks: no local indicators matched':'Security checks: review local indicators';
 }else if(head==='Security review | Saved checkout data'){
  var device=field('Device:').split(' (')[0];summary='Checkout recorded'+(device?' · '+device:'');
 }else if(head.indexOf('Payment security | ')===0){
  summary=head.slice('Payment security | '.length).replace(' verified response','')+': '+(field('Processor status:')||'verified response');
 }else if(head==='Internal security alert | Review indicators'){
  summary='Security review needed · internal alert only';
 }else if(head==='On-site journey | Same WooCommerce session'){
  summary='On-site journey recorded · partial session';
 }else{return null;}
 return {summary:summary,detail:text};
}
function render(doc){
 doc.querySelectorAll('.order_notes .note_content').forEach(function(el){
  if(el.dataset.saCompact==='1'){return;}
  var result=describe(el.innerText||el.textContent||'');if(!result){return;}
  el.dataset.saCompact='1';
  var title=doc.createElement('span');title.className='sa-security-event-summary';title.textContent=result.summary;
  var details=doc.createElement('details');details.className='sa-security-event-details';
  var toggle=doc.createElement('summary');toggle.textContent='Details';
  var body=doc.createElement('div');body.className='sa-security-event-body';body.textContent=result.detail;
  details.append(toggle,body);el.replaceChildren(title,details);
 });
}
root.SACompactSecurity={describe:describe,render:render};
if(typeof document==='undefined'){return;}
function start(){render(document);var list=document.querySelector('#woocommerce-order-notes');if(list&&typeof MutationObserver!=='undefined'){new MutationObserver(function(){render(document);}).observe(list,{childList:true,subtree:true});}}
if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',start);}else{start();}
})(typeof window==='undefined'?globalThis:window);
