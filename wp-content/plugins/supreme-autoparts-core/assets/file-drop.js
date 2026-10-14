/* Supreme Autoparts: styled upload dropzone for every visible <input type=file>. Camera, gallery AND files (no capture attr). */
(function(){
  var MAXDEF=10*1024*1024;
  function fmt(b){return b>1048576?(b/1048576).toFixed(1)+' MB':Math.max(1,Math.round(b/1024))+' KB';}
  function enhance(inp){
    if(inp.__sadz||inp.hidden||inp.hasAttribute('data-nodz')||inp.type!=='file')return;
    inp.__sadz=1;
    inp.removeAttribute('capture');
    if(!inp.getAttribute('accept')||/^image\/\*$/.test(inp.getAttribute('accept')))inp.setAttribute('accept','image/*,application/pdf,.heic,.heif');
    var max=parseInt(inp.getAttribute('data-max')||'',10)||MAXDEF;
    var wrap=document.createElement('div');wrap.className='sa-dz';
    var btn=document.createElement('label');btn.className='sa-dz-btn';
    var id=inp.id||('sadz'+Math.random().toString(36).slice(2,8));inp.id=id;btn.htmlFor=id;
    btn.innerHTML='<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg><span class="sa-dz-t"><strong>'+(inp.multiple?'Upload photos or files':'Upload photo or file')+'</strong><small>Take a photo, choose from your gallery, or upload a file'+(window.matchMedia&&matchMedia('(hover:hover)').matches?' (or drag it here)':'')+'. Up to '+fmt(max)+'.</small></span>';
    var list=document.createElement('div');list.className='sa-dz-list';list.setAttribute('aria-live','polite');
    var err=document.createElement('div');err.className='sa-dz-err';err.setAttribute('role','alert');
    inp.parentNode.insertBefore(wrap,inp);wrap.appendChild(inp);wrap.appendChild(btn);wrap.appendChild(list);wrap.appendChild(err);
    inp.classList.add('sa-dz-input');
    var keep=[];
    function sync(){try{var dt=new DataTransfer();keep.forEach(function(f){dt.items.add(f);});inp.files=dt.files;}catch(e){}}
    function render(){
      list.innerHTML='';
      keep.forEach(function(f,i){
        var row=document.createElement('div');row.className='sa-dz-row';
        var th=document.createElement('div');th.className='sa-dz-th';
        if(/^image\/(jpe?g|png|webp|gif)$/i.test(f.type)){var im=new Image();im.alt='';im.src=URL.createObjectURL(f);th.appendChild(im);}else{th.textContent=(f.name.split('.').pop()||'FILE').slice(0,4).toUpperCase();}
        var meta=document.createElement('div');meta.className='sa-dz-meta';meta.innerHTML='<span></span><small></small>';meta.firstChild.textContent=f.name;meta.lastChild.textContent=fmt(f.size);
        var rm=document.createElement('button');rm.type='button';rm.className='sa-dz-rm';rm.textContent='Remove';rm.setAttribute('aria-label','Remove '+f.name);
        rm.onclick=function(){keep.splice(i,1);sync();render();};
        row.appendChild(th);row.appendChild(meta);row.appendChild(rm);list.appendChild(row);
      });
      wrap.classList.toggle('has',keep.length>0);
      btn.querySelector('strong').textContent=keep.length?(inp.multiple?'Add more':'Replace photo or file'):(inp.multiple?'Upload photos or files':'Upload photo or file');
    }
    function add(files){
      err.textContent='';var bad=[];
      Array.prototype.forEach.call(files,function(f){
        if(f.size>max){bad.push(f.name+' is larger than '+fmt(max));return;}
        if(inp.multiple)keep.push(f);else keep=[f];
      });
      var lim=parseInt(inp.getAttribute('data-limit')||'',10);if(lim&&keep.length>lim){keep=keep.slice(0,lim);bad.push('Up to '+lim+' files');}
      if(bad.length)err.textContent=bad.join('. ')+'.';
      sync();render();
    }
    inp.addEventListener('change',function(){var f=Array.prototype.slice.call(inp.files||[]);if(!f.length)return;if(inp.multiple){keep=keep.filter(function(){return true;});}add(f.filter(function(x){return keep.indexOf(x)<0;}));});
    ['dragenter','dragover'].forEach(function(e){wrap.addEventListener(e,function(ev){ev.preventDefault();wrap.classList.add('drag');});});
    ['dragleave','drop'].forEach(function(e){wrap.addEventListener(e,function(ev){ev.preventDefault();wrap.classList.remove('drag');});});
    wrap.addEventListener('drop',function(ev){if(ev.dataTransfer&&ev.dataTransfer.files.length)add(ev.dataTransfer.files);});
  }
  function scan(root){(root||document).querySelectorAll('input[type=file]').forEach(enhance);}
  if(document.readyState!=='loading')scan();else document.addEventListener('DOMContentLoaded',function(){scan();});
  if(window.MutationObserver)new MutationObserver(function(m){m.forEach(function(x){x.addedNodes&&x.addedNodes.forEach(function(n){if(n.nodeType===1){if(n.matches&&n.matches('input[type=file]'))enhance(n);else if(n.querySelectorAll)scan(n);}});});}).observe(document.documentElement,{childList:true,subtree:true});
})();
