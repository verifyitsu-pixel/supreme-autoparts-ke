'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync('wp-content/mu-plugins/sa-security-timeline.js','utf8');
const ctx={};vm.createContext(ctx);vm.runInContext(src,ctx);const d=ctx.SACompactSecurity.describe;
let n=0;function ck(ok,s){assert(ok,s);n++;}
ck(d('Local security checks | Notify only\nIndicators: No configured indicator matched.').summary==='Security checks: no local indicators matched','short no-indicator summary');
ck(d('Local security checks | Notify only\nIndicators: Disposable domain').summary.includes('review local'),'indicator review');
ck(d('Security review | Saved checkout data\nDevice: Mobile / Safari / iOS (derived)').summary==='Checkout recorded · Mobile / Safari / iOS','device summary excludes caveat but detail retained');
ck(d('Payment security | Pesapal verified response\nProcessor status: FAILED').summary==='Pesapal: FAILED','payment event');
ck(d('Internal security alert | Review indicators\nSomething').summary.includes('internal alert only'),'notify only label');
ck(d('Customer email sent: info request (new)')===null,'customer event untouched');
ck(d('Paid order confirmation accepted')===null,'payment event untouched');
const text='Local security checks | Notify only\nAssessed: 2026-11-11 10:04:32 UTC\nIndicators: No configured indicator matched.\nUnavailable historical journey';
ck(d(text).detail===text,'exact original detail retained');
class El{constructor(text=''){this.innerText=text;this.dataset={};this.children=[];}append(...e){this.children.push(...e);}replaceChildren(...e){this.children=e;}}
const security=new El(text),customer=new El('Customer responded to info request'),doc={querySelectorAll(){return[security,customer];},createElement(){return new El;}};
ctx.SACompactSecurity.render(doc);ck(security.children.length===2,'title plus details');ck(security.children[1].children[0].textContent==='Details','details toggle');ck(!security.children[1].open,'details collapsed');ck(customer.children.length===0,'customer DOM untouched');
const saved=security.children;ctx.SACompactSecurity.render(doc);ck(saved===security.children,'idempotent');
ck(!/innerHTML|insertAdjacentHTML|fetch\(|sessionStorage|localStorage|\.sort\(/.test(src),'no unsafe content, tracking or reordering');
console.log(n+' timeline display checks PASS');
