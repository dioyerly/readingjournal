/* ============ MI LECTOR · EPUB + PDF ============
   Se carga solo cuando la lectora abre "Mi lector". Usa el puente window.MB que expone la app. */
(function(){
'use strict';
const MB=window.MB,$=(s,r)=>(r||document).querySelector(s),$$=(s,r)=>[...(r||document).querySelectorAll(s)];
const L=(es,en)=>MB.lang()==='en'?en:es;
const esc=MB.esc,clamp=(v,a,b)=>Math.max(a,Math.min(b,v));
const BASE=(document.currentScript&&document.currentScript.src||'').replace(/reader\/reader\.js.*$/,'')||'';
const COLORS=['h1','h2','h3','h4'],STICKERS=['😍','😭','🥵','🔥','💔','🤯','⭐','📌'];
const hcls=h=>h.c+(h.k==='u'?' u':'')+(h.n?' hn':'');

/* ---------- carga perezosa de librerías ---------- */
const loaded={};
function loadScript(src){return loaded[src]||(loaded[src]=new Promise((ok,ko)=>{const s=document.createElement('script');s.src=BASE+src;s.onload=ok;s.onerror=()=>ko(new Error('No se pudo cargar '+src));document.head.appendChild(s)}))}
const needZip=()=>window.JSZip?Promise.resolve():loadScript('vendor/jszip.min.js');
async function needPdf(){if(!window.pdfjsLib)await loadScript('vendor/pdf.min.js');window.pdfjsLib.GlobalWorkerOptions.workerSrc=BASE+'vendor/pdf.worker.min.js'}

/* ---------- archivos: IndexedDB local + servidor ---------- */
const FS={db:null,
  open(){return new Promise(res=>{if(this.db)return res();try{const r=indexedDB.open('bookwat-files'+(MB.demo?'-demo':''),1);r.onupgradeneeded=()=>r.result.createObjectStore('f');r.onsuccess=()=>{this.db=r.result;res()};r.onerror=()=>res()}catch(e){res()}})},
  async get(id){await this.open();return new Promise(res=>{if(!this.db)return res(null);const q=this.db.transaction('f').objectStore('f').get(id);q.onsuccess=()=>res(q.result||null);q.onerror=()=>res(null)})},
  async set(id,v){await this.open();return new Promise(res=>{if(!this.db)return res(false);const t=this.db.transaction('f','readwrite');t.objectStore('f').put(v,id);t.oncomplete=()=>res(true);t.onerror=()=>res(false)})},
  async del(id){await this.open();try{this.db&&this.db.transaction('f','readwrite').objectStore('f').delete(id)}catch(e){}}};
async function srvUp(id,blob){
  if(!MB.SY.user)return {ok:false,skip:true};
  try{const r=await fetch(BASE+'api.php?a=file&id='+encodeURIComponent(id),{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'reading-journal','Content-Type':'application/octet-stream'},body:blob});
    const j=await r.json().catch(()=>({}));return j}catch(e){return {ok:false}}}
async function srvDown(id){
  if(!MB.SY.user)return null;
  try{const r=await fetch(BASE+'api.php?a=file&id='+encodeURIComponent(id),{credentials:'same-origin'});if(!r.ok)return null;return await r.blob()}catch(e){return null}}

/* ---------- utilidades ---------- */
const fmtT=s=>{s=Math.max(0,Math.round(s));const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return h?h+' h '+String(m).padStart(2,'0')+' min':m?m+' min':(s?s+' s':'0 min')};
const P=()=>Object.assign({fs:19,font:'serif',lh:1.62,paper:'tema',anim:true,flow:'pag',pdfText:false},MB.S.settings.reader||{});
function setP(k,v){MB.S.settings.reader=Object.assign(P(),{[k]:v});MB.save()}
const FONTS={serif:{f:"'Iowan Old Style','Palatino Linotype',Palatino,Georgia,'Times New Roman',serif",k:1},elegante:{f:"var(--serif)",k:1.14},sans:{f:"system-ui,-apple-system,'Segoe UI',Roboto,sans-serif",k:.93}};
const dig=b=>b&&b.dig;
const digBooks=()=>MB.S.books.filter(b=>b.dig);

/* ---------- metadatos y portada al subir ---------- */
const kindOf=f=>/\.pdf$/i.test(f.name)||f.type==='application/pdf'?'pdf':/\.epub$/i.test(f.name)||f.type==='application/epub+zip'?'epub':null;
const xml=s=>new DOMParser().parseFromString(s,'application/xml');
const tagText=(d,t)=>{const e=d.getElementsByTagNameNS('*',t)[0];return e?e.textContent.trim():''};
const resolve=(base,href)=>{try{return decodeURIComponent(new URL(href.split('#')[0],'http://x/'+base).pathname.slice(1))}catch(e){return href}};

async function epubOpen(blob){
  await needZip();
  const zip=await JSZip.loadAsync(blob);
  const cf=zip.file('META-INF/container.xml');if(!cf)throw new Error('epub');
  const opfPath=xml(await cf.async('string')).getElementsByTagNameNS('*','rootfile')[0].getAttribute('full-path');
  const opf=xml(await zip.file(opfPath).async('string'));
  const items={};$$('item',opf).forEach(it=>{items[it.getAttribute('id')]={id:it.getAttribute('id'),href:it.getAttribute('href'),type:it.getAttribute('media-type'),props:it.getAttribute('properties')||'',path:resolve(opfPath,it.getAttribute('href'))}});
  const spine=[...opf.getElementsByTagNameNS('*','itemref')].map(r=>items[r.getAttribute('idref')]).filter(i=>i&&/x?html|xml/.test(i.type||''));
  if(!spine.length)throw new Error('epub-empty');
  spine.forEach(s=>{const f=zip.file(s.path);s.w=Math.max(200,(f&&f._data&&f._data.uncompressedSize)||1500)});
  const meta={title:tagText(opf,'title'),author:tagText(opf,'creator')};
  let cover=null;const cm=[...opf.getElementsByTagNameNS('*','meta')].find(m=>m.getAttribute('name')==='cover');
  const cit=Object.values(items).find(i=>/cover-image/.test(i.props))||(cm&&items[cm.getAttribute('content')])||Object.values(items).find(i=>/image/.test(i.type||'')&&/cover/i.test(i.href));
  if(cit&&zip.file(cit.path))cover=await zip.file(cit.path).async('blob').then(b=>new Blob([b],{type:cit.type||'image/jpeg'}));
  return {zip,opfPath,items,spine,meta,cover,opf};
}
async function epubToc(E){
  const idx={};E.spine.forEach((s,i)=>idx[s.path]=i);const out=[];
  const navIt=Object.values(E.items).find(i=>/\bnav\b/.test(i.props));
  const push=(t,href,depth,base)=>{if(!href)return;const p=resolve(base,href),fr=(href.split('#')[1]||'');if(idx[p]!=null)out.push({t:t.replace(/\s+/g,' ').trim()||'—',idx:idx[p],frag:fr,depth})};
  try{
    if(navIt&&E.zip.file(navIt.path)){
      const d=new DOMParser().parseFromString(await E.zip.file(navIt.path).async('string'),'text/html');
      const nav=d.querySelector('nav[epub\\:type~=toc],nav[role=doc-toc]')||d.querySelector('nav');
      const walk=(ol,dp)=>{[...(ol?ol.children:[])].forEach(li=>{const a=li.querySelector(':scope>a,:scope>span>a');if(a)push(a.textContent,a.getAttribute('href'),dp,navIt.path);const sub=li.querySelector(':scope>ol');if(sub)walk(sub,dp+1)})};
      if(nav)walk(nav.querySelector('ol'),0);
    }
    if(!out.length){
      const ncxId=E.opf.getElementsByTagNameNS('*','spine')[0].getAttribute('toc');const ncx=ncxId&&E.items[ncxId];
      if(ncx&&E.zip.file(ncx.path)){const d=xml(await E.zip.file(ncx.path).async('string'));
        const walk=(el,dp)=>{[...el.children].forEach(c=>{if(c.localName==='navPoint'){const t=c.getElementsByTagNameNS('*','text')[0],ct=c.getElementsByTagNameNS('*','content')[0];push(t?t.textContent:'',ct&&ct.getAttribute('src'),dp,ncx.path);walk(c,dp+1)}})};
        walk(d.getElementsByTagNameNS('*','navMap')[0],0)}
    }
  }catch(e){}
  return out;
}

/* ---------- subir / vincular un libro ---------- */
function pick(forId){
  const i=document.createElement('input');i.type='file';i.accept='.epub,.pdf,application/epub+zip,application/pdf';
  i.onchange=()=>{const f=i.files[0];if(f)attachFile(f,forId)};i.click();
}
const normT=s=>(s||'').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'').replace(/[^a-z0-9]+/g,' ').trim();
async function attachFile(file,forId){
  const kind=kindOf(file);
  if(!kind){MB.toast(L('Subí un archivo EPUB o PDF','Upload an EPUB or PDF file'));return}
  if(file.size>90*1024*1024){MB.toast(L('Ese archivo pesa demasiado (máx. 90 MB)','That file is too big (max 90 MB)'));return}
  const dlg=document.createElement('div');dlg.className='rd-dlg notr';dlg.innerHTML=`<div><h3>${L('Leyendo tu archivo…','Reading your file…')}</h3><p style="color:var(--ink2)">${esc(file.name)}</p></div>`;document.body.appendChild(dlg);
  let meta={title:file.name.replace(/\.(epub|pdf)$/i,'').replace(/[_-]+/g,' '),author:''},coverBlob=null,pages=0;
  try{
    if(kind==='epub'){const E=await epubOpen(file);if(E.meta.title)meta.title=E.meta.title;meta.author=E.meta.author||'';coverBlob=E.cover;
      pages=Math.max(1,Math.round(E.spine.reduce((a,s)=>a+s.w,0)/2300))}
    else{await needPdf();const doc=await pdfjsLib.getDocument({data:await file.arrayBuffer()}).promise;pages=doc.numPages;
      try{const m=await doc.getMetadata();if(m.info&&m.info.Title&&m.info.Title.trim().length>1)meta.title=m.info.Title.trim();if(m.info&&m.info.Author)meta.author=m.info.Author.trim()}catch(e){}
      const pg=await doc.getPage(1),vp=pg.getViewport({scale:1}),sc=640/vp.height,v2=pg.getViewport({scale:sc}),cv=document.createElement('canvas');cv.width=v2.width;cv.height=v2.height;
      await pg.render({canvasContext:cv.getContext('2d'),viewport:v2}).promise;coverBlob=await new Promise(r=>cv.toBlob(r,'image/jpeg',.85));doc.destroy()}
  }catch(e){dlg.remove();MB.toast(L('No pude abrir ese archivo. ¿Es un EPUB/PDF válido y sin protección (DRM)?','I couldn\'t open that file. Is it a valid EPUB/PDF without DRM?'));return}
  const cands=MB.S.books.filter(b=>!b.dig);const guess=cands.find(b=>forId?b.id===forId:normT(b.title)&&(normT(meta.title).includes(normT(b.title))||normT(b.title).includes(normT(meta.title))));
  const pv=coverBlob?URL.createObjectURL(coverBlob):'';
  dlg.innerHTML=`<div><h3>${L('Tu libro está listo','Your book is ready')}</h3>
   <div class="pv"><div class="rd-cv" style="${pv?`background-image:url('${pv}')`:''}"></div><div><b style="font:600 19px var(--serif)">${esc(meta.title)}</b><br><small style="color:var(--ink2)">${esc(meta.author||'')} · ${kind.toUpperCase()} · ${(file.size/1048576).toFixed(1)} MB${pages?' · ~'+pages+' '+L('págs.','pages'):''}</small></div></div>
   <label>${L('¿Es uno de tus libros?','Is it one of your books?')}</label>
   <select id="rdLink"><option value="">${L('✚ No, agregarlo como libro nuevo','✚ No, add it as a new book')}</option>${cands.map(b=>`<option value="${b.id}" ${guess&&guess.id===b.id?'selected':''}>${esc(b.title)}${b.author?' — '+esc(b.author):''}</option>`).join('')}</select>
   <label>${L('Título','Title')}</label><input id="rdTitle" value="${esc(meta.title)}"><label>${L('Autora/autor','Author')}</label><input id="rdAuth" value="${esc(meta.author)}">
   <div class="rd-row"><button class="btn dark" id="rdCancel">${L('Cancelar','Cancel')}</button><button class="btn" id="rdOk">${L('📖 Guardar y leer','📖 Save and read')}</button></div></div>`;
  const sync=()=>{const sel=$('#rdLink',dlg).value,nw=!sel;$('#rdTitle',dlg).disabled=!nw;$('#rdAuth',dlg).disabled=!nw;if(!nw){const b=MB.S.books.find(x=>x.id===sel);$('#rdTitle',dlg).value=b.title;$('#rdAuth',dlg).value=b.author||''}};
  $('#rdLink',dlg).onchange=sync;sync();
  $('#rdCancel',dlg).onclick=()=>{dlg.remove();pv&&URL.revokeObjectURL(pv)};
  $('#rdOk',dlg).onclick=async()=>{
    const sel=$('#rdLink',dlg).value;let b;
    if(sel)b=MB.S.books.find(x=>x.id===sel);
    else{b=MB.newBook({title:$('#rdTitle',dlg).value.trim()||meta.title,author:$('#rdAuth',dlg).value.trim(),format:'Ebook',pages:pages||0,status:'Por leer',genre:MB.S.settings.genres[0]||'Romance'});MB.S.books.push(b);MB.S.demo=false}
    b.dig={kind,name:file.name,size:file.size,pos:null,max:0,last:0,hl:[]};b.secs=b.secs||0;if(!b.pages&&pages)b.pages=pages;
    if(!MB.covers[b.id]&&coverBlob){try{const r=await MB.processImage(coverBlob);MB.covers[b.id]=r.data;MB.IDB.set(b.id,r.data);b.color=r.color}catch(e){}}
    await FS.set(b.id,{blob:file,kind,name:file.name});
    MB.save();dlg.remove();pv&&URL.revokeObjectURL(pv);MB.render();
    srvUp(b.id,file).then(j=>{if(j&&j.ok)FS.get(b.id).then(r=>r&&FS.set(b.id,Object.assign(r,{up:true})));else if(j&&j.msg)MB.toast(j.msg)});
    read(b.id);
  };
}

/* Libro de ejemplo (solo demo): El conde de Montecristo, dominio público */
async function openExample(){
  let b=MB.S.books.find(x=>x.dig&&x.dig.example);
  if(b){read(b.id);return}
  const dlg=document.createElement('div');dlg.className='rd-dlg notr';dlg.innerHTML=`<div><h3>${L('Preparando el libro…','Getting the book ready…')}</h3></div>`;document.body.appendChild(dlg);
  try{
    let blob;
    if(window.__BWEX){const bin=atob(window.__BWEX),u=new Uint8Array(bin.length);for(let i=0;i<bin.length;i++)u[i]=bin.charCodeAt(i);blob=new Blob([u],{type:'application/epub+zip'})}
    else{const r=await fetch(BASE+'demo-libro/montecristo.epub');if(!r.ok)throw 0;blob=await r.blob()}
    const file=new File([blob],'montecristo.epub',{type:'application/epub+zip'});
    const E=await epubOpen(file);
    const pages=Math.max(1,Math.round(E.spine.reduce((a,s)=>a+s.w,0)/2300));
    b=MB.newBook({title:'El conde de Montecristo',author:'Alejandro Dumas',format:'Ebook',pages,status:'Por leer',genre:(MB.S.settings.genres||[])[0]||'Aventura'});
    MB.S.books.push(b);
    b.dig={kind:'epub',name:'montecristo.epub',size:file.size,pos:null,max:0,last:0,hl:[],example:true};b.secs=0;
    if(E.cover){try{const r=await MB.processImage(E.cover);MB.covers[b.id]=r.data;MB.IDB.set(b.id,r.data);b.color=r.color}catch(e){}}
    await FS.set(b.id,{blob:file,kind:'epub',name:file.name});
    MB.save();dlg.remove();MB.render();read(b.id);
  }catch(e){dlg.remove();MB.toast(L('No pude cargar el libro de ejemplo','Could not load the example book'))}
}

/* ======================================================================
   EL LECTOR
   ====================================================================== */
const R={open:false,b:null,kind:null,E:null,pdf:null,sess:0,act:Date.now(),chrome:true,panel:null,askedFin:false,lastPg:0,estPages:0};
let tmr=null;

async function read(id){
  const b=MB.S.books.find(x=>x.id===id);if(!b||!b.dig){MB.toast(L('Este libro todavía no tiene archivo digital','This book has no digital file yet'));return}
  if(R.open)close(true);
  let rec=await FS.get(id);
  if(!rec){
    MB.toast(L('Buscando tu libro en la nube…','Fetching your book from the cloud…'));
    const blob=await srvDown(id);
    if(blob){rec={blob:new Blob([blob],{type:b.dig.kind==='pdf'?'application/pdf':'application/epub+zip'}),kind:b.dig.kind,name:b.dig.name,up:true};await FS.set(id,rec)}
  }
  if(!rec){MB.toast(L('El archivo de este libro no está en este dispositivo. Volvé a subirlo desde “Mi lector”.','This book\'s file isn\'t on this device. Upload it again from “My reader”.'));return}
  R.open=true;R.b=b;R.pdfSrc=b.dig.kind==='pdf';R.kind=R.pdfSrc&&P().pdfText?'epub':b.dig.kind;R.sess=0;R.askedFin=false;R.chrome=!isPhone();R.panel=null;R.E=null;R.pdf=null;act();
  buildUI();
  try{
    if(R.kind==='epub')await epubStart(rec.blob,R.pdfSrc);else await pdfStart(rec.blob);
  }catch(e){console.error(e);MB.toast(L('No se pudo abrir el libro','Could not open the book'));close(true);return}
  if(b.status==='Por leer'||b.status==='Abandonado'){b.status='Leyendo';if(!b.start)b.start=MB.today()}
  b.dig.last=Date.now();MB.save();
  startTimer();
}
const isTouch=()=>matchMedia('(pointer:coarse)').matches||isPhone();
const isPhone=()=>matchMedia('(max-width:760px)').matches;
const act=()=>{R.act=Date.now()};

function buildUI(){
  const p=P(),b=R.b;
  const el=document.createElement('div');el.id='rd';el.className='rd notr';el.dataset.paper=p.paper;el.dataset.mode=R.kind;if(!p.anim)el.classList.add('noanim');
  el.innerHTML=`<div class="rd-fx">${Array.from({length:14},(_,i)=>`<i style="left:${(i*7+Math.random()*6)%100}%;bottom:${Math.random()*50}%;animation-duration:${6+Math.random()*7}s;animation-delay:${-Math.random()*9}s"></i>`).join('')}</div>
  <div class="rd-top"><button class="rd-ic" data-a="close" title="${L('Volver','Back')}">←</button>
   <div class="tt"><b>${esc(b.title)}</b><small>${esc(b.author||'')}</small></div>
   <span class="rd-timer" id="rdTimer" title="${L('Tiempo de esta sesión','This session')}">⏱ <span>0:00</span></span>
   ${R.kind==='epub'?`<button class="rd-ic" data-a="toc" title="${L('Índice','Contents')}">☰</button>`:''}
   <button class="rd-ic" data-a="hls" title="${L('Mis frases subrayadas','My highlighted quotes')}">✦</button>
   <button class="rd-ic" data-a="set" title="${L('Ajustes de lectura','Reading settings')}" style="font:600 16px var(--serif)">Aa</button></div>
  <div class="rd-stage" id="rdStage">${R.kind==='epub'?`
   <div class="rd-book"><div class="rd-paper" id="rdPaper"><div class="rd-gutter"></div><div class="rd-rh" id="rdRh"></div>
    <div class="rd-view" id="rdView"><div class="rd-flow notr nt" id="rdFlow"></div><div class="rd-chnav" id="rdChNav"></div></div>
    <div class="rd-pn pl" id="rdPnL"></div><div class="rd-pn pr" id="rdPnR"></div><div class="rd-sweep" id="rdSweep"></div>
    <button class="rd-arrow al" data-a="prev" aria-label="${L('Anterior','Previous')}">‹</button><button class="rd-arrow ar" data-a="next" aria-label="${L('Siguiente','Next')}">›</button>
    <div class="rd-load" id="rdLoad"><div><div class="rd-sp"></div>${L('Abriendo tu libro…','Opening your book…')}</div></div></div></div>`
   :`<div class="rd-scroll" id="rdScroll"><div class="pdf-pages notr" id="rdPages"></div></div>
     <div class="rd-zoom"><button class="rd-ic" data-a="zin">+</button><button class="rd-ic" data-a="zout">−</button></div>
     <div class="rd-load" id="rdLoad"><div><div class="rd-sp"></div>${L('Abriendo tu libro…','Opening your book…')}</div></div>`}</div>
  <div class="rd-bot"><div class="rd-row"><button class="rd-pgb" data-a="prev" aria-label="${L('Anterior','Previous')}">‹</button><span id="rdCh"></span><span id="rdInfo"></span><button class="rd-pgb" data-a="next" aria-label="${L('Siguiente','Next')}">›</button></div><input class="rd-rng" id="rdRng" type="range" min="0" max="1000" value="0" aria-label="${L('Progreso','Progress')}"></div>`;
  document.body.appendChild(el);document.documentElement.style.overflow='hidden';R.el=el;
  applyType();
  if(isPhone()){const n=MB.S.settings.rdHint||0;if(n<3){MB.S.settings.rdHint=n+1;setTimeout(()=>note(L('Tocá el centro para ver el menú (Aa = letra, modo noche y cómo leer)','Tap the center to show the menu (Aa = text, night mode and how to read)')),900)}
    setTimeout(()=>{if(R.el===el&&!R.panel)el.classList.add('chromeoff')},4500)}
  el.addEventListener('click',onClick);
  $('#rdRng').addEventListener('input',e=>{act();previewSeek(+e.target.value/1000)});
  $('#rdRng').addEventListener('change',e=>{act();seek(+e.target.value/1000)});
  ['pointerdown','keydown','wheel','touchstart'].forEach(ev=>el.addEventListener(ev,act,{passive:true}));
  document.addEventListener('keydown',onKey);document.addEventListener('selectionchange',onSel);window.addEventListener('resize',onResize);document.addEventListener('visibilitychange',onVis);
}
function applyType(){
  const p=P(),f=FONTS[p.font]||FONTS.serif,el=R.el;if(!el)return;
  el.dataset.paper=p.paper;el.classList.toggle('noanim',!p.anim);el.classList.toggle('scrollmode',!!(R.kind==='epub'&&(p.flow==='scroll'||R.forceScroll)));
  el.style.setProperty('--rdfont',f.f);el.style.setProperty('--rdfs',Math.round(p.fs*f.k*10)/10+'px');el.style.setProperty('--rdlh',p.lh);
}
function close(silent){
  if(!R.open)return;R.forceScroll=false;flush();clearInterval(tmr);R.open=false;
  document.removeEventListener('keydown',onKey);document.removeEventListener('selectionchange',onSel);window.removeEventListener('resize',onResize);document.removeEventListener('visibilitychange',onVis);
  hideSel();if(R.pdf&&R.pdf.doc)try{R.pdf.doc.destroy()}catch(e){}
  if(R.E){R.E.blobs.forEach(u=>URL.revokeObjectURL(u));if(R.E.pdf)try{R.E.pdf.destroy()}catch(e){}}
  if(R.ro){R.ro.disconnect();R.ro=null}
  R.el&&R.el.remove();document.documentElement.style.overflow='';R.el=null;R.E=null;R.pdf=null;
  if(!silent){MB.save();MB.render()}
}

/* ---------- tiempo de lectura ---------- */
function startTimer(){
  clearInterval(tmr);
  tmr=setInterval(()=>{
    if(!R.open||document.hidden||Date.now()-R.act>75000)return;
    const b=R.b,S=MB.S;R.sess++;b.secs=(b.secs||0)+1;S.logSecs=S.logSecs||{};const d=MB.today();S.logSecs[d]=(S.logSecs[d]||0)+1;
    const t=$('#rdTimer span');if(t){const m=Math.floor(R.sess/60),s=R.sess%60;t.textContent=m+':'+String(s).padStart(2,'0')}
    if(R.sess%20===0){b.dig.last=Date.now();MB.save()}
    if(R.sess%30===0)updateInfo();
  },1000);
}
function flush(){if(R.b){R.b.dig.last=Date.now();savePos();MB.save()}}
function onVis(){if(document.hidden)flush()}

/* ---------- progreso común ---------- */
function setProgress(prog,pos){
  const b=R.b,d=b.dig;prog=clamp(prog,0,1);d.pos=pos;R.prog=prog;
  if(prog>(d.max||0))d.max=prog;
  const total=b.pages||R.estPages||0;
  if(total){const pg=clamp(Math.round(prog*total),0,total);
    if(R.lastPg!=null){const dd=pg-R.lastPg;if(dd>0&&dd<=12){MB.S.log[MB.today()]=(MB.S.log[MB.today()]||0)+dd}}
    R.lastPg=pg;b.page=pg}
  updateInfo();
  const r=$('#rdRng');if(r&&!R.seeking){r.value=Math.round(prog*1000);r.style.setProperty('--p',(prog*100)+'%')}
  clearTimeout(R.sv);R.sv=setTimeout(()=>MB.save(),1500);
  if(prog>=.985&&!R.askedFin&&b.status!=='Terminado'){R.askedFin=true;setTimeout(askFinish,700)}
}
function savePos(){}
function updateInfo(){
  const b=R.b,prog=R.prog||0,info=$('#rdInfo');if(!info)return;
  let rest='';if(b.secs>240&&(b.dig.max||0)>.02&&prog<1){const left=b.secs*(1-(b.dig.max||0))/(b.dig.max||1);rest=' · ≈ '+fmtT(left)+' '+L('para terminar','left')}
  info.textContent=Math.round(prog*100)+' %'+rest;
}
function askFinish(){
  if(!R.open)return;const b=R.b,box=document.createElement('div');box.className='rd-fin';
  box.innerHTML=`<div><div style="font-size:46px">🎉</div><h2>${L('¿Terminaste este libro?','Did you finish this book?')}</h2><p>${esc(b.title)}</p>
   <button class="rd-btn" data-a="fin">${L('Sí, marcarlo como terminado','Yes, mark it finished')}</button><button class="rd-btn g" data-a="finno">${L('Todavía no','Not yet')}</button></div>`;
  R.el.appendChild(box);
}
function finishBook(){
  const b=R.b;b.status='Terminado';b.end=MB.today();if(b.pages)b.page=b.pages;MB.save();close(true);MB.render();
  MB.toast(L('🎉 ¡Libro terminado! Poné tu puntaje, spicy y tropos','🎉 Book finished! Add your rating, spice and tropes'));
  setTimeout(()=>{MB.openBook(b.id);MB.modalTab('b')},450);
}

/* ======================================================================
   PDF → TEXTO AJUSTABLE (usa el mismo motor que el EPUB: letra, papel, subrayados)
   ====================================================================== */
async function pdfTextOpen(blob){
  await needPdf();
  const doc=await pdfjsLib.getDocument({data:new Uint8Array(await blob.arrayBuffer()),isEvalSupported:false,useSystemFonts:true}).promise;
  const n=doc.numPages,step=n>400?10:6,spine=[];
  for(let a=0;a<n;a+=step){const to=Math.min(n,a+step);spine.push({path:'pdf'+a,from:a,to,w:(to-a)*2300})}
  const toc=[];
  try{const ol=await doc.getOutline();for(const o of (ol||[]).slice(0,80)){let pi=null;try{const d=typeof o.dest==='string'?await doc.getDestination(o.dest):o.dest;if(d&&d[0])pi=await doc.getPageIndex(d[0])}catch(e){}
    if(pi!=null)toc.push({t:(o.title||'').replace(/\s+/g,' ').trim()||'—',idx:Math.floor(pi/step),frag:'',depth:0})}}catch(e){}
  return {pdf:doc,step,n,spine,items:{},meta:{},toc};
}
const escH=t=>t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function pdfLines(items){
  const its=items.filter(i=>i.str&&i.str.trim()!==''||i.str===' ').map(i=>({s:i.str,x:i.transform[4],y:i.transform[5],h:Math.abs(i.transform[3])||i.height||10,w:i.width||0}));
  its.sort((a,b)=>b.y-a.y||a.x-b.x);
  const lines=[];
  for(const it of its){const L=lines[lines.length-1];if(L&&Math.abs(L.y-it.y)<Math.max(2,L.h*.45))L.its.push(it);else lines.push({y:it.y,h:it.h,its:[it]})}
  lines.forEach(L=>{L.its.sort((a,b)=>a.x-b.x);let t='',px=null;L.its.forEach(i=>{if(px!=null&&i.x-px>L.h*.18&&!/\s$/.test(t)&&!/^\s/.test(i.s))t+=' ';t+=i.s;px=i.x+i.w});
    L.t=t.replace(/\s+/g,' ').trim();L.x=L.its[0].x;L.h=Math.max(...L.its.map(i=>i.h));L.r=Math.max(...L.its.map(i=>i.x+i.w))});
  return lines.filter(l=>l.t);
}
async function pdfChunkHtml(i){
  const E=R.E,it=E.spine[i];if(it.html!=null)return it.html;
  const all=[];
  for(let p=it.from;p<it.to;p++){
    const page=await E.pdf.getPage(p+1),tc=await page.getTextContent();let ls=pdfLines(tc.items);
    // números de página sueltos arriba/abajo
    if(ls.length&&/^\d{1,4}$/.test(ls[ls.length-1].t))ls.pop();if(ls.length&&/^\d{1,4}$/.test(ls[0].t))ls.shift();
    if(ls.length){ls[0].pageStart=true;all.push(...ls)}
    try{page.cleanup()}catch(e){}
  }
  if(!all.length){it.html='<p class="rd-emptytxt">'+escH(L('Esta parte del PDF no tiene texto que se pueda ajustar (puede ser un escaneo). Volvé al modo “Página” para verla igual que el original.','This part of the PDF has no adjustable text (it may be a scan). Go back to “Page” mode to see it like the original.'))+'</p>';return it.html}
  const hs=all.map(l=>l.h).sort((a,b)=>a-b),mh=hs[Math.floor(hs.length/2)]||10,leftM=Math.min(...all.map(l=>l.x)),maxW=Math.max(...all.map(l=>l.r-l.x));
  let out='',cur='',prev=null;
  const flush=()=>{if(cur.trim())out+='<p>'+escH(cur.trim())+'</p>';cur=''};
  for(const l of all){
    const head=l.h>mh*1.28&&l.t.length<90;
    if(head){flush();out+='<h3>'+escH(l.t)+'</h3>';prev=null;continue}
    const gap=prev&&!l.pageStart?prev.y-l.y:0,indent=l.x>leftM+mh*.9;
    const endS=/[.!?…:»”"')\]]$/.test(cur.trim());
    const shortPrev=prev&&(prev.r-prev.x)<maxW*.62;
    const newPar=!cur||(indent&&(endS||shortPrev))||(gap>mh*1.9)||(l.pageStart&&endS&&shortPrev)||(prev&&shortPrev&&endS&&gap>mh*1.45);
    if(newPar){flush();cur=l.t}
    else if(/[-‐]$/.test(cur)&&/^[a-záéíóúñü]/.test(l.t))cur=cur.slice(0,-1)+l.t;
    else cur+=' '+l.t;
    prev=l;
  }
  flush();
  return it.html=out;
}

/* ======================================================================
   MOTOR EPUB
   ====================================================================== */
async function epubStart(blob,fromPdf){
  const E=fromPdf?await pdfTextOpen(blob):await epubOpen(blob);E.blobs=[];E.imgs={};E.toc=fromPdf?E.toc:await epubToc(E);
  E.cum=[];let a=0;E.spine.forEach(s=>{E.cum.push(a);a+=s.w});E.total=a;E.w=E.spine.map(s=>s.w);
  E.pathIdx={};E.spine.forEach((s,i)=>E.pathIdx[s.path]=i);
  R.E=E;R.estPages=R.b.pages||Math.max(1,Math.round(a/2300));
  const pos=R.b.dig.pos;R.lastPg=R.b.page||0;R.prog=R.b.dig.max?(pos&&pos.ch!=null?(E.cum[pos.ch]+E.w[pos.ch]*pos.f)/E.total:0):0;
  let ch=0,w='start';
  if(pos&&pos.ch!=null){ch=clamp(pos.ch,0,E.spine.length-1);w={f:pos.f}}
  else if(R.b.page>0&&R.b.pages>0&&R.b.status!=='Terminado'){const tg=clamp(R.b.page/R.b.pages,0,.99)*E.total;ch=E.spine.length-1;for(let i=0;i<E.spine.length;i++){if(E.cum[i]+E.w[i]>tg){ch=i;break}}w={f:clamp((tg-E.cum[ch])/E.w[ch],0,.99)}}
  await showCh(ch,w,true);
  R.ro=new ResizeObserver(()=>onResize());R.ro.observe($('#rdPaper'));
  let vst=null;$('#rdView').addEventListener('scroll',()=>{if(!scrollMode()||vst)return;vst=requestAnimationFrame(()=>{vst=null;if(R.open&&R.E)place(true)})},{passive:true});
  $('#rdLoad').remove();
}
async function imgUrl(E,path){
  if(E.imgs[path]!==undefined)return E.imgs[path];
  const f=E.zip.file(path);if(!f){E.imgs[path]='';return ''}
  const mt=/\.png$/i.test(path)?'image/png':/\.gif$/i.test(path)?'image/gif':/\.svg$/i.test(path)?'image/svg+xml':/\.webp$/i.test(path)?'image/webp':'image/jpeg';
  const u=URL.createObjectURL(new Blob([await f.async('arraybuffer')],{type:mt}));E.blobs.push(u);return E.imgs[path]=u;
}
async function chHtml(i){
  const E=R.E;if(E.pdf)return pdfChunkHtml(i);const it=E.spine[i],raw=await E.zip.file(it.path).async('string');
  let doc=new DOMParser().parseFromString(raw,'application/xhtml+xml');if(doc.getElementsByTagName('parsererror').length)doc=new DOMParser().parseFromString(raw,'text/html');
  const body=doc.body||doc.getElementsByTagName('body')[0];const out=document.createElement('div');
  if(!body)return '';
  [...body.querySelectorAll('script,style,link,iframe,object,embed,form,meta,title,audio,video,canvas')].forEach(n=>n.remove());
  // svg con imagen (portadas) → img
  for(const sv of [...body.querySelectorAll('svg')]){
    const im=sv.querySelector('image');const href=im&&(im.getAttribute('xlink:href')||im.getAttribute('href'));
    if(href){const img=doc.createElement('img');img.setAttribute('data-src',resolve(it.path,href));sv.replaceWith(img)}else sv.remove();
  }
  for(const n of [...body.querySelectorAll('*')]){
    [...n.attributes].forEach(at=>{const k=at.name.toLowerCase();if(k.startsWith('on')||k==='style'||k==='class'||k==='width'||k==='height'||k==='align')n.removeAttribute(at.name)});
    if(n.localName==='img'){const s=n.getAttribute('src');if(s&&!/^data:/.test(s)){n.setAttribute('data-src',resolve(it.path,s));n.removeAttribute('src')}}
    if(n.localName==='a'){const h=n.getAttribute('href')||'';if(/^https?:/i.test(h)){n.setAttribute('target','_blank');n.setAttribute('rel','noopener')}else if(h){n.setAttribute('data-href',h.startsWith('#')?it.path+h:resolve(it.path,h)+(h.includes('#')?'#'+h.split('#')[1]:''));n.removeAttribute('href')}}
  }
  [...body.childNodes].forEach(c=>out.appendChild(document.importNode(c,true)));
  await Promise.all([...out.querySelectorAll('img[data-src]')].map(async im=>{const u=await imgUrl(E,im.getAttribute('data-src'));if(u)im.src=u;else im.remove()}));
  return out.innerHTML;
}
const geo={n:1,g:40,W:0,H:0};
const scrollMode=()=>R.kind==='epub'&&!!R.el&&R.el.classList.contains('scrollmode');
function layout(){
  const E=R.E,view=$('#rdView'),flow=$('#rdFlow'),paper=$('#rdPaper');if(!view)return;
  const pw=paper.clientWidth,ph=paper.clientHeight,sm=scrollMode();const n=!sm&&pw>=940?2:1;
  const edge=n===2?58:clamp(Math.round(pw*.075),22,70),top=isPhone()?54:46,bot=isPhone()?90:50;
  view.style.left=(sm?0:edge)+'px';view.style.right=(sm?0:edge)+'px';view.style.top=top+'px';view.style.bottom=bot+'px';
  const W=pw-edge*2,H=ph-top-bot,g=n===2?edge*2:40;
  geo.n=n;geo.g=g;geo.W=W;geo.H=H;R.el.classList.toggle('spread',n===2);R.el.style.setProperty('--rdh',(H-16)+'px');
  if(sm){
    const W2=Math.min(W,720);flow.style.width=W2+'px';flow.style.height='auto';flow.style.columnCount='auto';flow.style.columnGap='normal';flow.style.marginLeft=Math.round((pw-W2)/2)+'px';
    E.vp=false;E.stride=1;E.pages=E.views=1;chNav();return;
  }
  if(n===1){ /* una página por vez: se cortan las líneas a mano (no depende de las columnas CSS, que Safari del iPhone no maneja bien) */
    flow.style.marginLeft='0';flow.style.width=W+'px';flow.style.height='auto';flow.style.columnCount='auto';flow.style.columnGap='normal';
    E.vp=true;E.stride=0;vBreaks(flow,H);E.pages=E.views=E.vs.length;return}
  E.vp=false;
  flow.style.marginLeft='0';flow.style.width=W+'px';flow.style.height=H+'px';flow.style.columnCount=n;flow.style.columnGap=g+'px';
  E.stride=W+g;
  // contar páginas con una marca al final del texto (Safari no siempre informa bien scrollWidth)
  let end=$('#rdEnd',flow);if(!end){end=document.createElement('span');end.id='rdEnd';flow.appendChild(end)}else flow.appendChild(end);
  const fr=flow.getBoundingClientRect(),er=end.getBoundingClientRect();
  const byMark=Math.floor((er.left-fr.left+2)/E.stride)+1,byScroll=Math.ceil((flow.scrollWidth+g-2)/E.stride);
  if(er.top-fr.top>H*1.15&&byMark<=1&&!R.forceScroll){R.forceScroll=true;applyType();return layout()}// el navegador no pagina: pasar a lectura hacia abajo
  E.pages=Math.max(1,byMark,byScroll);
  E.views=E.pages;
}
function vBreaks(flow,H){
  const E=R.E,y0=flow.getBoundingClientRect().top,rects=[],rg=document.createRange(),w=document.createTreeWalker(flow,NodeFilter.SHOW_TEXT);let n;
  while((n=w.nextNode())){if(!n.nodeValue.trim())continue;rg.selectNodeContents(n);for(const r of rg.getClientRects())if(r.height>1)rects.push([r.top-y0,r.bottom-y0])}
  flow.querySelectorAll('img,hr,svg,table,pre').forEach(e=>{const r=e.getBoundingClientRect();if(r.height>1)rects.push([r.top-y0,r.bottom-y0])});
  rects.sort((a,b)=>a[0]-b[0]);
  const total=Math.max(flow.scrollHeight,flow.getBoundingClientRect().height,rects.length?rects[rects.length-1][1]:0);
  const vs=[0],ve=[];let start=0,maxB=0;
  for(const r of rects){
    if(r[1]>start+H+.5&&r[0]>start+2){const cut=r[0]>maxB?Math.round((maxB+r[0])/2):Math.round(r[0]);ve.push(cut);vs.push(cut);start=cut;maxB=0}
    if(r[1]>maxB)maxB=r[1];
  }
  ve.push(Math.max(total,start+H));E.vs=vs;E.ve=ve;
}
function pageOfEl(t){
  const E=R.E,flow=$('#rdFlow');if(E.vp){const y=t.getBoundingClientRect().top-flow.getBoundingClientRect().top;let p=0;for(let i=0;i<E.vs.length;i++)if(E.vs[i]<=y+2)p=i;return p}
  const x=t.getBoundingClientRect().left-flow.getBoundingClientRect().left;return clamp(Math.floor((x+2)/E.stride),0,E.views-1);
}
function chNav(){
  const E=R.E,nv=$('#rdChNav');if(!nv||!E)return;
  const nx=E.ch<E.spine.length-1,pv=E.ch>0,t=nx?tocFor(E.ch+1):null;
  nv.innerHTML=`${nx?`<button class="rd-btn" data-a="nextch">${L('Seguir leyendo','Keep reading')} ›<small>${esc(t&&t.idx===E.ch+1?t.t:L('Capítulo siguiente','Next chapter'))}</small></button>`:`<p>✦ ${L('Fin del libro','The end')} ✦</p>`}${pv?`<button class="rd-btn g" data-a="prevch">‹ ${L('Capítulo anterior','Previous chapter')}</button>`:''}`;
}
function chFrac(){const E=R.E;if(scrollMode()){const v=$('#rdView');return v?clamp(v.scrollTop/Math.max(1,v.scrollHeight-v.clientHeight),0,1):0}return E.page/E.views}
function setFrac(f){const v=$('#rdView');if(v)v.scrollTop=f*Math.max(0,v.scrollHeight-v.clientHeight)}
async function showCh(i,where,first){
  const E=R.E;i=clamp(i,0,E.spine.length-1);E.ch=i;const flow=$('#rdFlow');
  flow.classList.add('nt');if(!first)flow.classList.add('fade');
  flow.style.transform='translateX(0)';
  flow.innerHTML=await chHtml(i);
  applyMarks(i);layout();
  // imágenes que cargan después cambian el alto: re-medir
  $$('img',flow).forEach(im=>{if(!im.complete)im.addEventListener('load',()=>relayout(),{once:true})});
  let p=0;
  if(where==='end')p=E.views-1;
  else if(where&&typeof where==='object'&&where.f!=null)p=clamp(Math.floor(where.f*E.views+1e-6),0,E.views-1);
  else if(where&&typeof where==='object'&&where.anchor){const t=flow.querySelector('[id="'+where.anchor.replace(/"/g,'')+'"]');if(t)p=pageOfEl(t)}
  E.page=p;
  if(scrollMode()){const v=$('#rdView');if(where==='end')setFrac(1);else if(where&&typeof where==='object'&&where.f!=null)setFrac(where.f);else if(where&&typeof where==='object'&&where.anchor){const t=flow.querySelector('[id="'+where.anchor.replace(/"/g,'')+'"]');v.scrollTop=t?t.offsetTop-12:0}else v.scrollTop=0}
  place(false);
  requestAnimationFrame(()=>{flow.classList.remove('nt');flow.classList.remove('fade')});
  heads();
}
function place(animate){
  const E=R.E,flow=$('#rdFlow');if(!flow)return;
  if(!animate)flow.classList.add('nt');
  const vw=$('#rdView');
  if(scrollMode()){flow.style.transform='none';vw.style.clipPath=''}
  else if(E.vp){const a=E.vs[E.page]||0,b=E.ve[E.page]||a+geo.H;flow.style.transform=`translateY(${-a}px)`;vw.style.clipPath=`inset(0 0 ${Math.max(0,Math.round(geo.H-(b-a)))}px 0)`}
  else{flow.style.transform=`translateX(${-E.page*E.stride}px)`;vw.style.clipPath=''}
  if(!animate)requestAnimationFrame(()=>requestAnimationFrame(()=>flow.classList.remove('nt')));
  const prog=epubProg();setProgress(prog,{ch:E.ch,f:chFrac()});
  heads();
}
function epubProg(){const E=R.E,f=chFrac();if(E.ch===E.spine.length-1&&(scrollMode()?f>.995:E.page===E.views-1))return 1;return (E.cum[E.ch]+E.w[E.ch]*f)/E.total}
function tocFor(ch){const E=R.E;let t=null;E.toc.forEach(x=>{if(x.idx<=ch)t=x});return t}
function heads(){
  const E=R.E;if(!E||E.ch==null)return;const t=tocFor(E.ch);
  $('#rdRh')&&($('#rdRh').textContent=t?t.t:R.b.title);$('#rdCh')&&($('#rdCh').textContent=t?t.t:R.b.title);
  const tot=R.b.pages||R.estPages,pg=Math.round((R.prog||0)*tot);
  const l=$('#rdPnL'),r=$('#rdPnR');if(!l)return;
  if(geo.n===2){l.textContent=Math.max(1,pg+1);r.textContent=Math.max(1,pg+2);l.style.width=r.style.width='50%'}
  else{l.textContent='';r.textContent=Math.max(1,pg+1);r.style.width='100%'}
}
function sweep(back){
  const s=$('#rdSweep');if(!s||!P().anim)return;s.classList.remove('go','back');void s.offsetWidth;s.classList.add(back?'back':'go');
}
async function turn(dir){
  if(R.kind==='pdf'){pdfTurn(dir);return}
  const E=R.E;if(!E||E.busy)return;act();
  if(scrollMode()){const v=$('#rdView'),max=v.scrollHeight-v.clientHeight;
    if(dir>0&&v.scrollTop<max-4){v.scrollBy({top:v.clientHeight*.88,behavior:'smooth'});return}
    if(dir<0&&v.scrollTop>4){v.scrollBy({top:-v.clientHeight*.88,behavior:'smooth'});return}}
  const np=E.page+dir;
  if(np>=0&&np<E.views){E.page=np;sweep(dir<0);place(true);return}
  E.busy=true;
  try{
    if(dir>0&&E.ch<E.spine.length-1){sweep(false);await showCh(E.ch+1,'start')}
    else if(dir<0&&E.ch>0){sweep(true);await showCh(E.ch-1,'end')}
  }finally{E.busy=false}
}
let rzT;function relayout(){
  const E=R.E;if(!E||E.ch==null)return;const f=R.lastFrac!=null&&R.modeSwitch?R.lastFrac:chFrac();R.modeSwitch=false;layout();if(scrollMode()){setFrac(f);E.page=0}else E.page=clamp(Math.floor(f*E.views+1e-6),0,E.views-1);place(false)}
function onResize(){clearTimeout(rzT);rzT=setTimeout(()=>{if(!R.open)return;if(R.kind==='epub')relayout();else pdfLayout()},180)}
async function seek(t){
  if(R.kind==='pdf'){pdfSeek(t);return}
  const E=R.E;const target=t*E.total;let ch=E.spine.length-1;for(let i=0;i<E.spine.length;i++){if(E.cum[i]+E.w[i]>target){ch=i;break}}
  const f=clamp((target-E.cum[ch])/E.w[ch],0,.999);R.seeking=false;
  if(ch===E.ch){if(scrollMode())setFrac(f);else E.page=clamp(Math.floor(f*E.views),0,E.views-1);place(true)}else await showCh(ch,{f});
}
function previewSeek(t){
  R.seeking=true;const r=$('#rdRng');r.style.setProperty('--p',(t*100)+'%');
  $('#rdInfo').textContent=Math.round(t*100)+' %';
  if(R.kind==='epub'){const E=R.E,target=t*E.total;let ch=E.spine.length-1;for(let i=0;i<E.spine.length;i++){if(E.cum[i]+E.w[i]>target){ch=i;break}}const tc=tocFor(ch);$('#rdCh').textContent=tc?tc.t:'';}
  else $('#rdCh').textContent=L('Página ','Page ')+clamp(Math.ceil(t*R.pdf.n),1,R.pdf.n)+' / '+R.pdf.n;
}

/* ---------- subrayado en EPUB (offsets de texto por capítulo) ---------- */
function textNodes(root){const w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);const a=[];let n;while((n=w.nextNode()))a.push(n);return a}
function offsetOf(root,node,off){
  let pos=0;for(const t of textNodes(root)){if(t===node)return pos+off;pos+=t.nodeValue.length}
  // el nodo puede ser un elemento
  if(node.nodeType===1){const r=document.createRange();r.setStart(root,0);r.setEnd(node,off);return r.toString().length}
  return pos;
}
function applyMarks(ch){
  const flow=$('#rdFlow');if(!flow)return;
  R.b.dig.hl.filter(h=>h.ch===ch&&h.s!=null).forEach(h=>wrapMark(flow,h));
}
function wrapMark(flow,h){
  if($('mark[data-id="'+h.id+'"]',flow))return;
  let pos=0;const segs=[],made=[];
  for(const t of textNodes(flow)){const len=t.nodeValue.length,a=Math.max(h.s,pos),b=Math.min(h.e,pos+len);if(a<b)segs.push([t,a-pos,b-pos]);pos+=len;if(pos>=h.e)break}
  segs.forEach(([t,a,b])=>{if(!t.nodeValue.slice(a,b).trim())return;const r=document.createRange();r.setStart(t,a);r.setEnd(t,b);const m=document.createElement('mark');m.className=hcls(h);m.dataset.id=h.id;try{r.surroundContents(m);made.push(m)}catch(e){}});
  if(h.st&&made.length)made[made.length-1].dataset.st=h.st;
}
function unwrapMark(id){$$('mark[data-id="'+id+'"]').forEach(m=>{const p=m.parentNode;while(m.firstChild)p.insertBefore(m.firstChild,m);p.removeChild(m);p.normalize()})}

/* ======================================================================
   MOTOR PDF
   ====================================================================== */
async function pdfStart(blob){
  await needPdf();
  const doc=await pdfjsLib.getDocument({data:new Uint8Array(await blob.arrayBuffer()),isEvalSupported:false,useSystemFonts:true}).promise;
  const pg=await doc.getPage(1),vp=pg.getViewport({scale:1});
  const S={doc,n:doc.numPages,ratio:vp.height/vp.width,zoom:1,els:[],done:new Set(),mode:P().flow==='scroll'?'scroll':'pag',cur:{p:1,y:0}};R.pdf=S;R.estPages=S.n;if(!R.b.pages)R.b.pages=S.n;
  const pos=R.b.dig.pos;R.lastPg=R.b.page||0;
  const sp=pos&&pos.p?pos.p:(R.b.page>1&&R.b.status!=='Terminado'?clamp(R.b.page,1,S.n):0);
  const sc=$('#rdScroll');sc.classList.toggle('pg',S.mode==='pag');R.el.classList.toggle('pdfscroll',S.mode==='scroll');
  if(S.mode==='pag'){await pdfShow(sp||1)}
  else{
    pdfBuild();sc.addEventListener('scroll',onPdfScroll,{passive:true});
    if(sp){const el=S.els[sp-1];if(el)sc.scrollTop=el.offsetTop+((pos&&pos.y)||0)*el.offsetHeight-56}
    onPdfScroll();
  }
  $('#rdLoad')&&$('#rdLoad').remove();
}
/* una página por vez, ajustada a la pantalla */
async function pdfShow(p){
  const S=R.pdf;if(!S)return;p=clamp(p,1,S.n);const sc=$('#rdScroll'),box=$('#rdPages');if(!sc||!box)return;
  if(S.shown!=null&&S.shown!==p-1)pdfFree(S.shown);
  const tok=S.tok=(S.tok||0)+1,page=await S.doc.getPage(p);if(tok!==S.tok||!R.pdf)return;
  const vp=page.getViewport({scale:1}),ratio=vp.height/vp.width;
  const availW=sc.clientWidth-(isPhone()?10:56),availH=sc.clientHeight-(isPhone()?104:92);
  const fit=Math.max(180,Math.min(availW,availH/ratio)),W=Math.round(fit*S.zoom);
  let el=S.els[p-1];if(!el){el=document.createElement('div');el.className='pdf-pg';el.dataset.i=p-1;el.innerHTML=`<span class="pdf-num">${p}</span>`;S.els[p-1]=el}
  S.done.delete(p-1);$$('canvas,.textLayer,.pdf-hl,.pdf-st',el).forEach(n=>n.remove());
  el.style.width=W+'px';el.style.height=Math.round(W*ratio)+'px';
  box.innerHTML='';box.appendChild(el);S.shown=p-1;S.cur={p,y:0};
  sc.classList.toggle('zoomed',S.zoom>1.02);sc.scrollTop=0;sc.scrollLeft=Math.max(0,(W-sc.clientWidth)/2);
  el.classList.remove('in');void el.offsetWidth;el.classList.add('in');
  pdfProgressPag();
  await pdfRender(p-1);
}
function pdfProgressPag(){const S=R.pdf;if(!S||S.mode!=='pag')return;const p=S.cur.p;const ch=$('#rdCh');if(ch)ch.textContent=L('Página ','Page ')+p+' / '+S.n;setProgress(p>=S.n?1:(p-1)/S.n,{p,y:0});R.b.page=p;R.lastPg=p;hideSel()}
function pdfTurn(dir){
  const S=R.pdf;if(!S)return;act();
  if(S.mode==='pag'){const np=S.cur.p+dir;if(np<1)return;if(np>S.n){setProgress(1,{p:S.n,y:0});return}sweep(dir<0);pdfShow(np);return}
  const sc=$('#rdScroll');sc.scrollBy({top:dir*sc.clientHeight*.86,behavior:'smooth'});
}
function pdfWidth(){const sc=$('#rdScroll');return clamp(sc.clientWidth-(isPhone()?12:48),260,980)*R.pdf.zoom}
function pdfBuild(){
  const S=R.pdf,box=$('#rdPages');box.innerHTML='';S.els=[];S.done.clear();const W=pdfWidth();
  if(S.io)S.io.disconnect();
  S.io=new IntersectionObserver(es=>es.forEach(e=>{const i=+e.target.dataset.i;if(e.isIntersecting)pdfQueue(i);else pdfFree(i)}),{root:$('#rdScroll'),rootMargin:isTouch()?'500px 0px':'1400px 0px'});
  for(let i=0;i<S.n;i++){const d=document.createElement('div');d.className='pdf-pg';d.dataset.i=i;d.style.width=W+'px';d.style.height=Math.round(W*S.ratio)+'px';d.innerHTML=`<span class="pdf-num">${i+1}</span>`;box.appendChild(d);S.els.push(d);S.io.observe(d)}
}
function pdfLayout(){
  const S=R.pdf;if(!S)return;if(S.mode==='pag'){pdfShow(S.cur.p);return}const sc=$('#rdScroll'),cur=S.cur||{p:1,y:0};pdfBuild();
  const el=S.els[cur.p-1];if(el)sc.scrollTop=el.offsetTop+cur.y*el.offsetHeight-56;onPdfScroll();
}
function pdfQueue(i){const S=R.pdf;if(!S)return;S.q=(S.q||Promise.resolve()).then(()=>{const el=S.els[i];if(!R.pdf||!el)return;const sc=$('#rdScroll'),r=el.getBoundingClientRect(),m=isTouch()?700:1600;if(r.bottom<-m||r.top>innerHeight+m)return;return pdfRender(i)})}
async function pdfRender(i){
  const S=R.pdf;if(!S||S.done.has(i)||S.busy&&S.busy.has(i))return;(S.busy=S.busy||new Set()).add(i);
  try{
    const page=await S.doc.getPage(i+1),el=S.els[i];if(!el||!R.pdf)return;const W=el.clientWidth;
    const base=page.getViewport({scale:1}),sc=W/base.width,vp=page.getViewport({scale:sc});el.style.height=Math.round(vp.height)+'px';
    let dpr=Math.min(window.devicePixelRatio||1,isTouch()?1.6:2);const maxPx=isTouch()?2.4e6:6e6;if(vp.width*vp.height*dpr*dpr>maxPx)dpr=Math.sqrt(maxPx/(vp.width*vp.height));const cv=document.createElement('canvas');cv.width=Math.round(vp.width*dpr);cv.height=Math.round(vp.height*dpr);
    await page.render({canvasContext:cv.getContext('2d',{alpha:false}),viewport:page.getViewport({scale:sc*dpr})}).promise;
    if(!R.pdf||!S.els[i].isConnected){cv.width=cv.height=0;return}
    const tl=document.createElement('div');tl.className='textLayer';tl.style.setProperty('--scale-factor',vp.scale);
    el.insertBefore(cv,el.firstChild);el.appendChild(tl);
    const tc=await page.getTextContent();await pdfjsLib.renderTextLayer({textContentSource:tc,container:tl,viewport:vp,textDivs:[]}).promise;
    S.done.add(i);pdfMarks(i);try{page.cleanup()}catch(x){}
  }catch(e){}finally{S.busy&&S.busy.delete(i)}
}
function pdfFree(i){const S=R.pdf;if(!S||!S.done.has(i))return;const el=S.els[i];if(!el)return;$$('canvas',el).forEach(c=>{c.width=c.height=0});$$('canvas,.textLayer,.pdf-hl,.pdf-st',el).forEach(n=>n.remove());S.done.delete(i)}
function pdfMarks(i){
  const el=R.pdf.els[i];if(!el)return;R.b.dig.hl.filter(h=>h.p===i+1&&h.r).forEach(h=>{
    h.r.forEach(([x,y,w,hh])=>{const d=document.createElement('div');d.className='pdf-hl '+hcls(h);d.dataset.id=h.id;d.style.cssText=`left:${x*100}%;top:${y*100}%;width:${w*100}%;height:${hh*100}%`;el.appendChild(d)});
    if(h.st&&h.r.length){const [x,y,w,hh]=h.r[h.r.length-1],d=document.createElement('div');d.className='pdf-st';d.textContent=h.st;d.style.cssText=`left:${(x+w)*100}%;top:${y*100}%;font-size:${Math.max(12,Math.round(hh*el.offsetHeight*1.05))}px`;el.appendChild(d)}})
}
function pdfRemark(i){const el=R.pdf&&R.pdf.els[i];if(!el)return;$$('.pdf-hl,.pdf-st',el).forEach(n=>n.remove());pdfMarks(i)}
let pst=null;
function onPdfScroll(){
  if(pst)return;pst=requestAnimationFrame(()=>{pst=null;const S=R.pdf;if(!S||!R.open)return;const sc=$('#rdScroll');if(!sc)return;
    const top=sc.scrollTop+sc.clientHeight*.3;let lo=0,hi=S.n-1;while(lo<hi){const m=(lo+hi+1)>>1;if(S.els[m].offsetTop<=top)lo=m;else hi=m-1}
    const el=S.els[lo],y=clamp((sc.scrollTop+56-el.offsetTop)/el.offsetHeight,0,1);S.cur={p:lo+1,y};
    const prog=(lo+clamp((sc.scrollTop+sc.clientHeight*.5-el.offsetTop)/el.offsetHeight,0,1))/S.n;
    $('#rdCh').textContent=L('Página ','Page ')+(lo+1)+' / '+S.n;
    setProgress(sc.scrollTop+sc.clientHeight>=sc.scrollHeight-8?1:prog,{p:lo+1,y});
    const tot=S.n;R.b.page=lo+1;R.lastPg=lo+1;
  })
}
function pdfSeek(t){R.seeking=false;const S=R.pdf,sc=$('#rdScroll'),p=clamp(Math.floor(t*S.n),0,S.n-1);if(S.mode==='pag'){pdfShow(p+1);return}const el=S.els[p];sc.scrollTop=el.offsetTop-56}
function pdfZoom(d){const S=R.pdf;S.zoom=clamp(Math.round((S.zoom+d)*100)/100,S.mode==='pag'?1:.6,3.2);pdfLayout();$('#rdScroll').classList.toggle('zoomed',S.zoom>1.02)}

/* ======================================================================
   SELECCIÓN Y SUBRAYADOS
   ====================================================================== */
let selT=null,curSel=null;
function onSel(){clearTimeout(selT);selT=setTimeout(checkSel,220)}
function checkSel(){
  if(!R.open)return;const s=getSelection();
  if(!s||s.isCollapsed||!s.rangeCount){R.selMode=null;hideSel();return}
  const r=s.getRangeAt(0),anc=r.commonAncestorContainer.nodeType===1?r.commonAncestorContainer:r.commonAncestorContainer.parentNode;
  const inEpub=R.kind==='epub'&&anc.closest&&anc.closest('#rdFlow'),inPdf=R.kind==='pdf'&&anc.closest&&anc.closest('.textLayer');
  if(!inEpub&&!inPdf){hideSel();return}
  if(!r.toString().trim()){hideSel();return}
  curSel=r.cloneRange();showSel(r.getBoundingClientRect());
}
function showSel(rect){
  hideSel();R.selRect=rect;const t=document.createElement('div');t.className='rd-sel notr';t.id='rdSel';const m=R.selMode||'';
  const bk=`<button data-a="selback" title="${L('Atrás','Back')}">‹</button>`;
  t.innerHTML=m==='hl'||m==='ul'?bk+COLORS.map((c,i)=>`<button class="c c${i+1}${m==='ul'?' ul':''}" data-a="hl" data-k="${m==='ul'?'u':'h'}" data-c="${c}" title="${L('Guardar frase','Save quote')}"></button>`).join('')
   :m==='st'?bk+STICKERS.map(e=>`<button class="stk" data-a="stk" data-st="${e}">${e}</button>`).join('')
   :`<button data-a="selmode" data-m="hl" title="${L('Resaltar','Highlight')}"><span class="ic-hl"></span></button><button data-a="selmode" data-m="ul" title="${L('Subrayar','Underline')}"><u>U</u></button><button data-a="selmode" data-m="st" title="Sticker">☺</button><button data-a="selnote" title="${L('Agregar nota','Add note')}">✎</button><span class="sep"></span><button data-a="cpy" title="${L('Copiar','Copy')}">⧉</button>`;
  ['mousedown','touchstart'].forEach(ev=>t.addEventListener(ev,e=>e.preventDefault(),{passive:false}));
  R.el.appendChild(t);
  const w=t.offsetWidth,x=clamp(rect.left+rect.width/2,w/2+8,innerWidth-w/2-8);
  const below=isPhone()||rect.top<70;t.style.left=x+'px';t.style.top=(below?rect.bottom+(isPhone()?44:12):rect.top-58)+'px';
}
function hideSel(){const t=$('#rdSel');if(t)t.remove()}
function sparkle(x,y){for(let i=0;i<7;i++){const s=document.createElement('span');s.className='rd-spark';s.textContent=['✦','✧','♡','✨'][i%4];s.style.left=x+'px';s.style.top=y+'px';s.style.setProperty('--dx',(Math.random()*90-45)+'px');s.style.animationDelay=(i*40)+'ms';s.style.color=getComputedStyle(R.el).getPropertyValue('--gold2');document.body.appendChild(s);setTimeout(()=>s.remove(),1100)}}
function addQuote(h){
  const b=R.b,q=h.t.replace(/\s+/g,' ').trim();h.q=q;b.quotes=b.quotes||[];if(!b.quotes.includes(q))b.quotes.push(q);
}
function makeHl(c,ev,k,st,openNote){
  const r=curSel;if(!r)return;const txt=r.toString().replace(/\s+/g,' ').trim();if(!txt)return;const b=R.b,id=MB.uid();let h;
  if(R.kind==='epub'){
    const flow=$('#rdFlow');h={id,ch:R.E.ch,s:offsetOf(flow,r.startContainer,r.startOffset),e:offsetOf(flow,r.endContainer,r.endOffset),t:txt,c,n:'',d:Date.now()};if(k==='u')h.k='u';if(st)h.st=st;
    if(h.e<=h.s){hideSel();return}
    b.dig.hl.push(h);addQuote(h);getSelection().removeAllRanges();wrapMark(flow,h);
  }else{
    const sNode=r.startContainer.nodeType===1?r.startContainer:r.startContainer.parentNode,pgEl=sNode.closest('.pdf-pg');if(!pgEl){hideSel();return}
    const pr=pgEl.getBoundingClientRect(),rects=[...r.getClientRects()].filter(x=>x.width>2&&x.height>2&&x.top>=pr.top-2&&x.bottom<=pr.bottom+2).map(x=>[(x.left-pr.left)/pr.width,(x.top-pr.top)/pr.height,x.width/pr.width,x.height/pr.height]);
    if(!rects.length){hideSel();return}
    h={id,p:+pgEl.dataset.i+1,r:rects,t:txt,c,n:'',d:Date.now()};if(k==='u')h.k='u';if(st)h.st=st;b.dig.hl.push(h);addQuote(h);getSelection().removeAllRanges();pdfMarks(+pgEl.dataset.i);
  }
  const rc=ev?{x:ev.clientX,y:ev.clientY}:{x:innerWidth/2,y:innerHeight/2};sparkle(rc.x,rc.y);
  const rect=R.selRect;R.selMode=null;hideSel();curSel=null;R.lastC=c;MB.save();
  if(openNote&&rect)hlPop(h.id,{clientX:rect.left+rect.width/2,clientY:rect.bottom});else note(st?st+' '+L('Guardado','Saved'):L('✦ Frase guardada en tus citas','✦ Quote saved to your quotes'));
  return h;
}
function note(t){const old=$('.rd-toast');old&&old.remove();const d=document.createElement('div');d.className='rd-toast';d.textContent=t;R.el.appendChild(d);setTimeout(()=>d.remove(),2200)}
function delHl(id){
  const d=R.b.dig,i=d.hl.findIndex(h=>h.id===id);if(i<0)return;const h=d.hl[i];d.hl.splice(i,1);
  if(h.q&&!d.hl.some(x=>x.q===h.q))R.b.quotes=(R.b.quotes||[]).filter(q=>q!==h.q);
  unwrapMark(id);MB.save();
}
function hlPop(id,ev){
  const h=R.b.dig.hl.find(x=>x.id===id);if(!h)return;closePop();
  const p=document.createElement('div');p.className='rd-pop notr';p.id='rdPop';
  p.innerHTML=`<q>${esc(h.t.length>240?h.t.slice(0,240)+'…':h.t)}</q><textarea placeholder="${L('Agregá una nota (opcional)…','Add a note (optional)…')}">${esc(h.n||'')}</textarea>
   <div class="rd-sel" style="position:static;transform:none;box-shadow:none;border:0;background:none;padding:8px 0 0;animation:none;flex-wrap:wrap">${COLORS.map((c,i)=>`<button class="c${i+1} ${h.c===c?'c':''}" data-a="recol" data-c="${c}"></button>`).join('')}<span class="sep"></span><button data-a="rekind" data-k="h" class="${h.k==='u'?'':'on'}" title="${L('Resaltado','Highlight')}"><span class="ic-hl"></span></button><button data-a="rekind" data-k="u" class="${h.k==='u'?'on':''}" title="${L('Subrayado','Underline')}"><u>U</u></button></div>
   <div class="rd-sel" style="position:static;transform:none;box-shadow:none;border:0;background:none;padding:2px 0 0;animation:none;flex-wrap:wrap">${STICKERS.map(e=>`<button class="stk ${h.st===e?'on':''}" data-a="restk" data-st="${e}">${e}</button>`).join('')}<button data-a="restk" data-st="" title="${L('Sin sticker','No sticker')}">✕</button></div>
   <div class="rd-r"><button class="g" data-a="hdel">🗑 ${L('Quitar','Remove')}</button><button data-a="hok">${L('Listo','Done')}</button></div>`;
  R.el.appendChild(p);p.dataset.id=id;
  const x=clamp(ev.clientX-150,8,innerWidth-308),y=clamp(ev.clientY+14,60,innerHeight-p.offsetHeight-10);p.style.left=x+'px';p.style.top=y+'px';
}
function closePop(save){const p=$('#rdPop');if(!p)return;if(save!==false){const h=R.b.dig.hl.find(x=>x.id===p.dataset.id);if(h){const v=$('textarea',p).value.trim();if(v!==(h.n||'')){h.n=v;$$('mark[data-id="'+h.id+'"]').forEach(m=>m.classList.toggle('hn',!!v));MB.save()}}}p.remove()}

/* ======================================================================
   PANELES
   ====================================================================== */
function panel(kind){
  closePanel();R.panel=kind;const el=R.el,b=R.b;
  const back=document.createElement('div');back.className='rd-back';back.dataset.a='pclose';el.appendChild(back);
  const p=document.createElement('div');p.className='rd-panel'+(kind==='toc'?' left':'');p.id='rdPanel';
  if(kind==='toc'){
    const E=R.E,cur=tocFor(E.ch);
    p.innerHTML=`<h3>${L('Índice','Contents')}<span class="rd-x" data-a="pclose">✕</span></h3><div class="body rd-toc">${E.toc.length?E.toc.map((t,i)=>`<a data-a="goto" data-i="${i}" class="${cur===t?'on':''}" style="padding-left:${12+t.depth*16}px">${esc(t.t)}</a>`).join(''):E.spine.map((s,i)=>`<a data-a="gotoch" data-i="${i}">${L('Sección','Section')} ${i+1}</a>`).join('')}</div>`;
  }else if(kind==='hls'){
    const hl=b.dig.hl.slice().sort((a,c)=>(a.p!=null&&c.p!=null)?a.p-c.p:(a.ch!=null&&c.ch!=null)?(a.ch-c.ch)||(a.s-c.s):(a.d||0)-(c.d||0));
    p.innerHTML=`<h3>${L('Mis frases','My quotes')} <small style="font:600 13px var(--sans);opacity:.7">${hl.length}</small><span class="rd-x" data-a="pclose">✕</span></h3><div class="body">${hl.length?hl.map(h=>`<div class="rd-hli" data-a="gohl" data-id="${h.id}"><i style="background:var(--${h.c==='h1'?'hl1':h.c==='h2'?'hl2':h.c==='h3'?'hl3':'hl4'})"></i><div style="flex:1"><div class="t">${h.st?h.st+' ':''}${esc(h.t.length>260?h.t.slice(0,260)+'…':h.t)}</div>${h.n?`<small>📝 ${esc(h.n)}</small>`:''}<small>${h.p!=null?L('Página ','Page ')+h.p:esc((R.E&&R.E.toc.length?(R.E.toc.filter(x=>x.idx<=h.ch).pop()||{t:''}).t:'')||L('Capítulo ','Chapter ')+(h.ch+1))}</small></div><span class="d" data-a="hdel2" data-id="${h.id}" title="${L('Quitar','Remove')}">✕</span></div>`).join(''):`<div class="rd-empty">${L('Todavía no subrayaste nada.<br>Mantené apretada una frase y elegí un color: se guarda sola en tus Citas favoritas ✦','Nothing highlighted yet.<br>Press and hold a sentence and pick a color: it saves itself to your favorite quotes ✦')}</div>`}</div>`;
  }else{
    const q=P();
    p.innerHTML=`<h3>${L('Tu lectura','Your reading')}<span class="rd-x" data-a="pclose">✕</span></h3><div class="body rd-set">
     <label>${L('Papel','Paper')}</label><div class="rd-seg">${[['tema',L('Tema','Theme'),'var(--paper)'],['sepia','Sepia','#f1e3c4'],['blanco',L('Blanco','White'),'#fff'],['noche',L('Noche','Night'),'#17141b']].map(([k,n,c])=>`<button data-a="paper" data-v="${k}" class="${q.paper===k?'on':''}"><span class="rd-sw" style="background:${k==='tema'?'linear-gradient(90deg,#f5ebd3 50%,#fffcff 50%)':c}"></span>${n}</button>`).join('')}</div>
     ${R.pdfSrc?(()=>{const cm=R.kind==='epub'?'text':(R.pdf&&R.pdf.mode==='pag'?'page':'scrollp');return `<label>${L('Cómo leer este PDF','How to read this PDF')}</label><div class="rd-seg">${[['page','📄 '+L('Página','Page')],['scrollp','↓ '+L('Continuo','Scroll')],['text','Aa '+L('Texto','Text')]].map(([k,n])=>`<button data-a="pmode" data-v="${k}" class="${cm===k?'on':''}">${n}</button>`).join('')}</div><p class="rd-hint">${cm==='text'?L('Texto ajustable: se acomoda a tu pantalla y podés cambiar la letra. Se pierden imágenes y diseño del PDF.','Adjustable text: fits your screen and you can change the font. PDF images and layout are lost.'):L('Fiel al PDF original. Para agrandar la letra y que se acomode, elegí “Texto”.','Faithful to the original PDF. To enlarge the text and have it reflow, choose “Text”.')}</p>`})():''}
     ${R.kind==='epub'?`${R.pdfSrc?'':`<label>${L('Cómo leer','How to read')}</label>`}<div class="rd-seg">${[['pag','📖 '+L('Pasar hoja','Turn pages')],['scroll','↓ '+L('Hacia abajo','Scroll down')]].map(([k,n])=>`<button data-a="flow" data-v="${k}" class="${(scrollMode()?'scroll':'pag')===k?'on':''}">${n}</button>`).join('')}</div>
     <label>${L('Tamaño de letra','Text size')}</label><div class="rd-step"><button data-a="fs" data-d="-1">A−</button><output id="rdFsO">${q.fs}</output><button data-a="fs" data-d="1">A+</button></div>
     <label>${L('Tipografía','Typeface')}</label><div class="rd-seg">${[['serif','Serif',"font-family:Georgia,serif"],['elegante',L('Elegante','Elegant'),"font-family:var(--serif)"],['sans','Sans','font-family:system-ui']].map(([k,n,s])=>`<button data-a="font" data-v="${k}" class="${q.font===k?'on':''}" style="${s}">${n}</button>`).join('')}</div>
     <label>${L('Interlineado','Line spacing')}</label><div class="rd-seg">${[[1.4,L('Compacto','Tight')],[1.62,L('Cómodo','Comfy')],[1.9,L('Amplio','Airy')]].map(([v,n])=>`<button data-a="lh" data-v="${v}" class="${Math.abs(q.lh-v)<.05?'on':''}">${n}</button>`).join('')}</div>`
     :`<label>Zoom</label><div class="rd-step"><button data-a="zout">−</button><output>${Math.round(R.pdf.zoom*100)} %</output><button data-a="zin">+</button></div>`}
     <label>${L('Efectos','Effects')}</label><div class="rd-tog"><span>${L('Animación al pasar de página','Page-turn animation')}</span><input type="checkbox" data-a="anim" ${q.anim?'checked':''}></div>
     <label>${L('Este libro','This book')}</label><div class="rd-tog"><span>⏱ ${L('Tiempo leído','Time read')}</span><b>${fmtT(b.secs||0)}</b></div>
     <div class="rd-tog"><span>✦ ${L('Frases guardadas','Saved quotes')}</span><b>${b.dig.hl.length}</b></div></div>`;
  }
  el.appendChild(p);
}
function closePanel(){R.panel=null;$$('.rd-back,.rd-panel',R.el||document).forEach(n=>n.remove())}

/* ---------- eventos ---------- */
function onClick(e){
  const t=e.target,a=t.closest('[data-a]');
  const pop=$('#rdPop');if(pop&&!t.closest('#rdPop')&&!(a&&a.dataset.a==='hl'))closePop();
  const mk=t.closest('mark[data-id]')||t.closest('.pdf-hl[data-id]');
  if(mk&&!(a&&a.closest('.rd-panel'))){const s=getSelection();if(!s||s.isCollapsed){hlPop(mk.dataset.id,e);return}}
  if(!a){
    // toque en el papel: móvil = mostrar/ocultar barras, o pasar de página en los bordes
    if(t.closest('#rdPaper')||t.closest('#rdScroll')){
      const s=getSelection();if(s&&!s.isCollapsed)return;
      const link=t.closest('a[data-href]');if(link){gotoHref(link.dataset.href);return}
      const pgE=R.kind==='epub'&&!scrollMode(),pgP=R.kind==='pdf'&&R.pdf&&R.pdf.mode==='pag';
      if(pgE||pgP){const r=(pgE?$('#rdPaper'):$('#rdScroll')).getBoundingClientRect(),fx=(e.clientX-r.left)/r.width;
        if(fx<.24){turn(-1);return}if(fx>.76){turn(1);return}
        if(pgP){const now=Date.now();if(R.lt&&now-R.lt<320){R.lt=0;const S=R.pdf;S.zoom=S.zoom>1.02?1:2.2;pdfLayout();return}R.lt=now}}
      if(isPhone()||matchMedia('(pointer:coarse)').matches){R.el.classList.toggle('chromeoff')}
    }
    return;
  }
  const k=a.dataset.a;
  ({close:()=>close(),toc:()=>panel('toc'),hls:()=>panel('hls'),set:()=>panel('set'),pclose:closePanel,prev:()=>turn(-1),next:()=>turn(1),
   zin:()=>{pdfZoom(.15);if(R.panel==='set')panel('set')},zout:()=>{pdfZoom(-.15);if(R.panel==='set')panel('set')},
   hl:()=>makeHl(a.dataset.c,e,a.dataset.k),selmode:()=>{R.selMode=a.dataset.m;showSel(R.selRect)},selback:()=>{R.selMode=null;showSel(R.selRect)},stk:()=>makeHl(R.lastC||'h1',e,'h',a.dataset.st),selnote:()=>makeHl(R.lastC||'h1',e,'h',null,true),cpy:()=>{try{navigator.clipboard.writeText(curSel.toString())}catch(x){document.execCommand&&document.execCommand('copy')}hideSel();note(L('Copiado','Copied'))},
   goto:()=>{const x=R.E.toc[+a.dataset.i];closePanel();showCh(x.idx,x.frag?{anchor:x.frag}:'start')},gotoch:()=>{closePanel();showCh(+a.dataset.i,'start')},
   paper:()=>{setP('paper',a.dataset.v);applyType();panel('set')},font:()=>{setP('font',a.dataset.v);applyType();panel('set');relayout()},lh:()=>{setP('lh',+a.dataset.v);applyType();panel('set');relayout()},
   fs:()=>{const v=clamp(P().fs+(+a.dataset.d),14,32);setP('fs',v);applyType();$('#rdFsO').textContent=v;relayout()},
   anim:()=>{setP('anim',a.checked);applyType()},
   gohl:()=>{if(e.target.closest('[data-a=hdel2]'))return;const h=R.b.dig.hl.find(x=>x.id===a.dataset.id);closePanel();if(!h)return;
     if(h.p!=null){
       if(R.kind!=='pdf'){note(L('Esta frase se marcó en modo Página','This quote was marked in Page mode'));return}
       if(R.pdf.mode==='pag'){pdfShow(h.p);return}
       const el=R.pdf.els[h.p-1];$('#rdScroll').scrollTop=el.offsetTop+(h.r[0][1]*el.offsetHeight)-120;return}
     if(R.kind!=='epub'){note(L('Esta frase se marcó en modo Texto','This quote was marked in Text mode'));return}
     showCh(h.ch,{f:0}).then(()=>{const m=$('mark[data-id="'+h.id+'"]');if(m&&scrollMode()){$('#rdView').scrollTop=m.offsetTop-120;place(false)}else if(m){R.E.page=pageOfEl(m);place(false)}})},
   hdel2:()=>{delHl(a.dataset.id);panel('hls')},hdel:()=>{const id=$('#rdPop').dataset.id;closePop(false);delHl(id)},hok:()=>closePop(),
   recol:()=>{const p=$('#rdPop'),h=R.b.dig.hl.find(x=>x.id===p.dataset.id);if(h){h.c=a.dataset.c;$$('mark[data-id="'+h.id+'"]').forEach(m=>m.className=hcls(h));$$('.pdf-hl[data-id="'+h.id+'"]').forEach(m=>m.className='pdf-hl '+hcls(h));$$('[data-a=recol]',p).forEach(x=>x.classList.toggle('c',x===a));MB.save()}},
   rekind:()=>{const p=$('#rdPop'),h=R.b.dig.hl.find(x=>x.id===p.dataset.id);if(h){if(a.dataset.k==='u')h.k='u';else delete h.k;$$('mark[data-id="'+h.id+'"]').forEach(m=>m.className=hcls(h));$$('.pdf-hl[data-id="'+h.id+'"]').forEach(m=>m.className='pdf-hl '+hcls(h));$$('[data-a=rekind]',p).forEach(x=>x.classList.toggle('on',x===a));MB.save()}},
   restk:()=>{const p=$('#rdPop'),h=R.b.dig.hl.find(x=>x.id===p.dataset.id);if(h){if(a.dataset.st)h.st=a.dataset.st;else delete h.st;
     if(h.p!=null)pdfRemark(h.p-1);else{unwrapMark(h.id);wrapMark($('#rdFlow'),h)}$$('[data-a=restk]',p).forEach(x=>x.classList.toggle('on',x===a));MB.save()}},
   pmode:()=>switchPdfMode(a.dataset.v),
   nextch:()=>{if(R.E.ch<R.E.spine.length-1)showCh(R.E.ch+1,'start')},prevch:()=>{if(R.E.ch>0)showCh(R.E.ch-1,'start')},
   flow:()=>{R.lastFrac=chFrac();R.modeSwitch=true;R.forceScroll=false;setP('flow',a.dataset.v);applyType();panel('set');relayout()},
   fin:()=>finishBook(),finno:()=>$('.rd-fin').remove()}[k]||(()=>{}))();
}
function switchPdfMode(m){
  const b=R.b,d=b.dig,n=b.pages||(R.pdf&&R.pdf.n)||(R.E&&R.E.n)||1;
  const page=R.kind==='pdf'&&R.pdf?R.pdf.cur.p:Math.max(1,Math.round((R.prog||0)*n));
  if(m==='text'){const step=n>400?10:6;d.pos={ch:Math.floor((page-1)/step),f:((page-1)%step)/step};setP('pdfText',true)}
  else{d.pos={p:Math.min(page,n),y:0};setP('pdfText',false);setP('flow',m==='scrollp'?'scroll':'pag')}
  const id=b.id;close(true);read(id);
}
function gotoHref(h){
  const E=R.E,[path,frag]=h.split('#'),idx=E.pathIdx[path];
  if(idx==null)return;
  if(idx===E.ch&&frag){const t=$('#rdFlow [id="'+frag+'"]');if(t){if(scrollMode()){$('#rdView').scrollTop=t.offsetTop-12}else E.page=pageOfEl(t);place(true)}}
  else showCh(idx,frag?{anchor:frag}:'start');
}
function onKey(e){
  if(!R.open||/INPUT|TEXTAREA|SELECT/.test((e.target||{}).tagName||'')&&e.target.type!=='range')return;
  const k=e.key;
  if(k==='Escape'){if(R.panel)closePanel();else close();return}
  if(R.kind==='pdf'&&R.pdf&&R.pdf.mode==='pag'){if(k==='ArrowRight'||k==='PageDown'||k===' '){e.preventDefault();turn(1)}else if(k==='ArrowLeft'||k==='PageUp'){e.preventDefault();turn(-1)}}
  if(R.kind==='epub'){if(k==='ArrowRight'||k==='PageDown'||k===' '){e.preventDefault();turn(1)}else if(k==='ArrowLeft'||k==='PageUp'){e.preventDefault();turn(-1)}}
}
/* deslizar para pasar de página (móvil) */
const swOK=e=>R.open&&((R.kind==='epub'&&e.target.closest('#rdPaper'))||(R.kind==='pdf'&&R.pdf&&R.pdf.mode==='pag'&&R.pdf.zoom<=1.02&&e.target.closest('#rdScroll')));
document.addEventListener('touchstart',e=>{if(!swOK(e))return;const t=e.touches[0];R.tx=t.clientX;R.ty=t.clientY;R.tt=Date.now()},{passive:true});
document.addEventListener('touchend',e=>{if(!R.open||R.tx==null)return;const t=e.changedTouches[0],dx=t.clientX-R.tx,dy=t.clientY-R.ty;R.tx=null;
  const s=getSelection();if(s&&!s.isCollapsed)return;
  if(!(R.kind==='epub'&&scrollMode())&&Math.abs(dx)>46&&Math.abs(dx)>Math.abs(dy)*1.4&&Date.now()-R.tt<700){turn(dx<0?1:-1);R.swiped=Date.now()}},{passive:true});

/* ======================================================================
   VISTA "MI LECTOR" DENTRO DE LA APP
   ====================================================================== */
function weekSecs(){const S=MB.S,ls=S.logSecs||{};let t=0;for(let i=0;i<7;i++){t+=ls[MB.addDays(MB.today(),-i)]||0}return t}
function view(){
  const S=MB.S,books=digBooks().sort((a,b)=>(b.dig.last||0)-(a.dig.last||0));
  const total=S.books.reduce((a,b)=>a+(b.secs||0),0),today=(S.logSecs||{})[MB.today()]||0;
  const card=b=>{const d=b.dig,p=Math.round((d.max||0)*100);
    return `<div class="rdv-card"><div class="rd-cv" data-act="rdRead" data-id="${b.id}" style="background-image:url('${MB.coverOf(b)}')"></div>
     <div style="flex:1;min-width:0"><span class="kd">${d.kind.toUpperCase()}</span><h4>${esc(b.title)}</h4><p>${esc(b.author||'')}</p>
      <div class="bar"><i style="width:${p}%"></i></div><small>${p}% · ⏱ ${fmtT(b.secs||0)} · ✦ ${d.hl.length}</small>
      <div class="acts"><button class="mini" data-act="rdRead" data-id="${b.id}">${p>0&&p<98?L('▶ Continuar','▶ Continue'):b.status==='Terminado'?L('↻ Releer','↻ Reread'):L('📖 Leer','📖 Read')}</button>
      <button class="mini o" data-act="open" data-id="${b.id}">${L('Ficha','Details')}</button><button class="mini o" data-act="rdDetach" data-id="${b.id}" title="${L('Quitar el archivo (conserva el libro y tus citas)','Remove the file (keeps the book and your quotes)')}">🗑</button></div></div></div>`};
  return `<div class="notr" id="rdHost"><h1 class="pt">${L('Mi <em>lector</em>','My <em>reader</em>')}</h1><p class="sub">${L('Leé tus EPUB y PDF acá: se anota solo tu tiempo, tu progreso y tus frases favoritas.','Read your EPUB and PDF files here: your time, progress and favorite quotes are tracked for you.')}</p>
   ${MB.demo&&!books.some(x=>x.dig.example)?`<div class="mt rdv-drop" data-act="rdExample" style="border-style:solid"><span class="rd-big">📚</span><h3>${L('Probá el lector con un libro de ejemplo','Try the reader with a sample book')}</h3><p>${L('“El conde de Montecristo”, de Alejandro Dumas (dominio público). Tocá para abrirlo y ver cómo se lee, se subraya y se guarda tu progreso.','“The Count of Monte Cristo” by Alexandre Dumas (public domain). Tap to open it and see how reading, highlighting and progress work.')}</p></div>`:''}
   <div class="grid g3 mt"><div class="card rdv-stat"><div class="rdv-n">${fmtT(today)}</div><div class="rdv-l">${L('Hoy','Today')}</div></div><div class="card rdv-stat"><div class="rdv-n">${fmtT(weekSecs())}</div><div class="rdv-l">${L('Últimos 7 días','Last 7 days')}</div></div><div class="card rdv-stat"><div class="rdv-n">${fmtT(total)}</div><div class="rdv-l">${L('Tiempo total','Total time')}</div></div></div>
   <div class="mt rdv-drop" id="rdDrop" data-act="rdPick"><span class="rd-big">📖</span><h3>${L('Subí un libro para leerlo acá','Upload a book to read it here')}</h3><p>${L('EPUB o PDF · se guarda en este dispositivo y, si tenés cuenta, también en tu nube','EPUB or PDF · saved on this device and, if you have an account, in your cloud')}</p><button class="btn" data-act="rdPick">${L('＋ Elegir archivo','＋ Choose file')}</button></div>
   ${books.length?`<h3 class="mt" style="font:600 26px var(--serif);color:var(--h1)">${L('Tus libros digitales','Your digital books')}</h3><div class="rdv-grid mt">${books.map(card).join('')}</div>`:''}
   <p class="rdv-note">${L('Funciona con EPUB y PDF sin protección (DRM). Los libros de Kindle no se pueden abrir. Pensado para celular y tablet.','Works with EPUB and PDF files without DRM. Kindle books can\'t be opened. Made for phone and tablet.')}</p></div>`;
}
function wire(){
  const d=$('#rdDrop');if(!d)return;
  ['dragenter','dragover'].forEach(ev=>d.addEventListener(ev,e=>{e.preventDefault();d.classList.add('over')}));
  ['dragleave','drop'].forEach(ev=>d.addEventListener(ev,e=>{e.preventDefault();d.classList.remove('over')}));
  d.addEventListener('drop',e=>{const f=e.dataTransfer.files[0];if(f)attachFile(f)});
  // subir lo pendiente a la nube
  if(MB.SY.user)digBooks().forEach(async b=>{const r=await FS.get(b.id);if(r&&!r.up){const j=await srvUp(b.id,r.blob);if(j&&j.ok){r.up=true;FS.set(b.id,r)}}});
}
async function detach(id){
  const b=MB.S.books.find(x=>x.id===id);if(!b)return;
  if(!confirm(L('Se quita el archivo digital de este libro. El libro, tus citas y tu tiempo de lectura se conservan. ¿Seguimos?','The digital file will be removed from this book. The book, your quotes and reading time stay. Continue?')))return;
  await FS.del(id);try{if(MB.SY.user)await fetch(BASE+'api.php?a=file_del',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'reading-journal'},body:JSON.stringify({id})})}catch(e){}
  b.dig=null;MB.save();MB.render();
}

window.MBR={view,wire,read,pick,openExample,attach:pick,detach,refresh(){if(R.open){const id=R.b.id;close(true);read(id)}},
  applyTheme(){/* el tema se aplica solo por CSS (html[data-theme]) */},isOpen:()=>R.open,
  deleteFile:id=>{FS.del(id);if(MB.SY.user)fetch(BASE+'api.php?a=file_del',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'reading-journal'},body:JSON.stringify({id})}).catch(()=>{})}};
})();
