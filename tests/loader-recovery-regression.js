// Loader ownership: a generic show() must not replace a pending payment
// recovery callback or its deadline, and payment_method_selected must not
// start an overlay on a page with no checkout form (order-pay).
const fs=require('fs'),vm=require('vm'),assert=require('assert');
const src=fs.readFileSync(__dirname+'/../wp-content/themes/supreme-autoparts/assets/js/sa-loader.js','utf8');
function run(hasCheckoutForm){
  let now=0,next=0;const jobs=new Map(),handlers={};
  const cls=new Set();
  const el=()=>({classList:{add:c=>cls.add(c),remove:c=>cls.delete(c),contains:c=>cls.has(c)},setAttribute(){},removeAttribute(){},querySelector:()=>({style:{},textContent:''}),style:{},appendChild(){},set innerHTML(v){}});
  const label={textContent:''};
  const overlay={id:'',className:'',classList:{add:c=>cls.add('o:'+c),remove:c=>cls.delete('o:'+c),contains:c=>cls.has('o:'+c)},setAttribute(){},querySelector:s=>s.indexOf('label')>-1?label:{style:{}},set innerHTML(v){}};
  const body={appendChild(){},classList:{add(){},remove(){}}};
  const doc={readyState:'complete',body,documentElement:{setAttribute(){},removeAttribute(){}},
    createElement:()=>overlay,addEventListener(){},
    querySelector:s=>/form\.checkout/.test(s)?(hasCheckoutForm?{classList:{contains:()=>false}}:null):null,
    getElementById:()=>null,querySelectorAll:()=>[]};
  const jq=()=>({on:(names,...r)=>{for(const n of names.split(' '))(handlers[n]??=[]).push(r.at(-1));}});
  jq.fn={};
  const win={document:doc,jQuery:jq,saTheme:{},location:{href:'https://x.invalid/'},addEventListener(){},MutationObserver:undefined,
    setTimeout:(f,ms)=>{const id=++next;jobs.set(id,{f,at:now+ms});return id},clearTimeout:id=>jobs.delete(id)};
  win.window=win;
  const D={now:()=>now};
  vm.runInNewContext(src,{window:win,document:doc,Date:D,URL,console,Math},{});
  const advance=t=>{const end=now+t;for(;;){let b=null;for(const [id,j] of jobs)if(j.at<=end&&(!b||j.at<b[1].at))b=[id,j];if(!b)break;now=b[1].at;jobs.delete(b[0]);b[1].f();}now=end;};
  return {win,handlers,advance,isVisible:()=>cls.has('o:is-visible'),label,jobs};
}
// 1) generic show after payment show keeps the 90s recovery
{const t=run(true);let rec=0;
 t.win.saLoader.show('payment',{failsafeMs:90000,onFailsafe:()=>rec++});
 t.advance(10000);
 t.handlers.update_checkout[0]();            // generic show('processing')
 t.advance(30000);t.handlers.update_checkout[0]();
 t.advance(49999);assert.equal(rec,0,'too early');
 t.advance(2);assert.equal(rec,1,'recovery must fire at the original 90s deadline');}
// 2) plain generic show still self-clears at 45s
{const t=run(true);t.handlers.update_checkout[0]();t.advance(44000);assert(t.isVisible());t.advance(2000);assert(!t.isVisible());}
// 3) hide() clears the recovery
{const t=run(true);let rec=0;t.win.saLoader.show('payment',{failsafeMs:90000,onFailsafe:()=>rec++});t.win.saLoader.hide(true);t.advance(100000);assert.equal(rec,0);}
// 4) payment_method_selected: no overlay without a checkout form, overlay with one
{const t=run(false);t.handlers.payment_method_selected[0]();assert(!t.isVisible(),'order-pay page must not show overlay');}
{const t=run(true);t.handlers.payment_method_selected[0]();assert(t.isVisible());}
console.log('PASS loader recovery ownership');
