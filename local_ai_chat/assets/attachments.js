(()=>{
function renderMessageAttachments(messageEl,attachments=[]){
 messageEl.querySelector('.message-attachments')?.remove();
 if(!Array.isArray(attachments)||!attachments.length)return;
 const host=document.createElement('section');host.className='message-attachments';host.setAttribute('aria-label','Results and files');
 const heading=document.createElement('h3');heading.className='message-attachments-heading';heading.textContent='Results & Files';host.append(heading);
 const cards=document.createElement('div');cards.className='message-attachment-grid';host.append(cards);
 attachments.forEach(a=>{
  if(!a||!/^att_[a-f0-9]{24}$/.test(String(a.attachment_id||'')))return;
  const card=document.createElement('button');card.type='button';card.className='message-attachment-card';card.dataset.attachmentOpen=a.attachment_id;
  const icon=document.createElement('span');icon.className='material-icons-round';icon.textContent=({data_table:'table_chart',chart:'insert_chart',image:'image',document:'description',json_viewer:'data_object',code:'code',media:'perm_media'}[a.presentation]||'attach_file');
  const info=document.createElement('div');info.className='message-attachment-info';const title=document.createElement('strong');title.textContent=String(a.title||'Tool result');
  const meta=document.createElement('small');meta.textContent=[a.tool,a.presentation,a.format?.toUpperCase()].filter(Boolean).join(' · ');info.append(title,meta);
  const state=document.createElement('span');state.className='message-attachment-state';state.textContent='Saved';card.append(icon,info,state);cards.append(card);
 });
 if(cards.childElementCount){const body=messageEl.querySelector('.message-content');if(body)body.after(host);else messageEl.append(host);}
}
function renderAttachments(messagesRoot,attachments=[]){
 if(!messagesRoot)return;
 messagesRoot.querySelectorAll('.message-attachments').forEach(node=>node.remove());
 if(!Array.isArray(attachments))return;
 const byMessage=new Map();
 attachments.forEach(a=>{const id=String(a?.message_id||'');if(!id)return;if(!byMessage.has(id))byMessage.set(id,[]);byMessage.get(id).push(a);});
 messagesRoot.querySelectorAll('.message.assistant[data-message-id]').forEach(message=>{
  const list=byMessage.get(String(message.dataset.messageId))||[];
  if(list.length)renderMessageAttachments(message,list);
 });
}
function formatBytes(bytes){if(!Number.isFinite(bytes)||bytes<=0)return '';const units=['B','KB','MB','GB'];let n=bytes,i=0;while(n>=1024&&i<units.length-1){n/=1024;i++;}return n.toFixed(i?1:0)+' '+units[i];}
function renderAttachmentPreview(container,attachment,preview){
 const type=String(attachment?.presentation||'file');
 const addText=(tag,text,className='')=>{const el=document.createElement(tag);if(className)el.className=className;el.textContent=String(text??'');container.append(el);return el;};
 const renderers={
  data_table(p){const rows=Array.isArray(p?.rows)?p.rows:[];if(!rows.length){addText('p','No data rows are available in this preview.');return;}const keys=Array.from(new Set(rows.flatMap(r=>r&&typeof r==='object'?Object.keys(r):[]))).slice(0,16);const wrap=document.createElement('div');wrap.className='attachment-table-wrap';const table=document.createElement('table');const head=document.createElement('tr');keys.forEach(k=>{const th=document.createElement('th');th.textContent=k;head.append(th)});const thead=document.createElement('thead');thead.append(head);table.append(thead);const body=document.createElement('tbody');rows.forEach(row=>{const tr=document.createElement('tr');keys.forEach(k=>{const td=document.createElement('td');const v=row?.[k];td.textContent=typeof v==='object'?JSON.stringify(v):String(v??'');tr.append(td)});body.append(tr)});table.append(body);wrap.append(table);container.append(wrap);addText('small',`${p.returned_rows??rows.length} rows displayed · ${p.total_rows??'?'} total rows`,'attachment-preview-meta');},
  sources(p){const rows=p?.sources||p?.citations||[];if(!Array.isArray(rows)||!rows.length){addText('pre',JSON.stringify(p,null,2));return;}rows.slice(0,30).forEach((r,i)=>{const item=document.createElement('article');item.className='attachment-evidence-item';const title=document.createElement('strong');title.textContent=String(r.title||r.url||`Source ${i+1}`);item.append(title);const url=String(r.url||r.canonical_url||'');if(/^https?:\/\//i.test(url)){const link=document.createElement('a');link.href=url;link.target='_blank';link.rel='noopener noreferrer';link.textContent=url;item.append(link)}container.append(item)});},
  evidence(p){const rows=p?.claims||p?.evidence||[];if(!Array.isArray(rows)||!rows.length){addText('pre',JSON.stringify(p,null,2));return;}rows.slice(0,30).forEach(r=>{const item=document.createElement('article');item.className='attachment-evidence-item';const title=document.createElement('strong');title.textContent=String(r.text||r.claim||r.id||'Evidence');const detail=document.createElement('p');detail.textContent=String(r.assessment||r.status||r.quote||'');item.append(title,detail);container.append(item)});},
  math(p){addText('pre',String(p.latex||p.formula||p.result||JSON.stringify(p,null,2)),'attachment-math-preview');},
  model_report(p){const metrics=p.metrics&&typeof p.metrics==='object'?p.metrics:p;const grid=document.createElement('div');grid.className='attachment-metric-grid';Object.entries(metrics).slice(0,24).forEach(([k,v])=>{if(typeof v==='object')return;const cell=document.createElement('div');const label=document.createElement('small');label.textContent=k;const value=document.createElement('strong');value.textContent=String(v);cell.append(label,value);grid.append(cell)});container.append(grid);},
  document(p){addText('pre',String(p.text||p.content||p.extracted_text||JSON.stringify(p,null,2)),'attachment-document-preview');},
  code(p){addText('pre',String(p.code||p.source||p.content||JSON.stringify(p,null,2)),'attachment-code-preview');},
  chart(p){addText('p','Chart data has been saved. Structure preview:');addText('pre',JSON.stringify(p.chart_spec||p.chart||p,null,2));},
  image(p){if(typeof p?.url==='string'&&/^api\.php\?action=artifact_image&id=art_[a-f0-9]{24}$/.test(p.url)){const img=document.createElement('img');img.src=p.url;img.alt='Image generated by tool';img.loading='lazy';img.className='attachment-preview-image';container.append(img);}else addText('p','No valid image preview URL is available.');},
  media(p){addText('pre',JSON.stringify(p,null,2));},
  json_viewer(p){addText('pre',JSON.stringify(p,null,2),'attachment-preview-json');},
  file(p){addText('pre',JSON.stringify(p,null,2),'attachment-preview-json');}
 };
 (renderers[type]||renderers.file)(preview||{});
}

function refresh(getCurrent){
 const id=Number(getCurrent()||0);if(!id)return Promise.resolve();
 return fetch('api.php?action=history&id='+encodeURIComponent(id)).then(r=>r.json()).then(j=>{
  if(j&&Array.isArray(j.attachments))renderAttachments(document.querySelector('#messages'),j.attachments);
 }).catch(()=>{});
}
function init(getCurrent,notify){
 document.addEventListener('click',async e=>{
  const attachment=e.target.closest('[data-attachment-open]');if(!attachment)return;
  const conversationId=Number(getCurrent()||0);if(!conversationId)return;
  try{
   const r=await fetch('api.php?action=attachment_content&conversation_id='+conversationId+'&attachment_id='+encodeURIComponent(attachment.dataset.attachmentOpen),{cache:'no-store'});
   const j=await r.json();if(!r.ok)throw Error(j.error||'Could not open attachment');
   const modal=document.createElement('div');modal.className='artifact-image-modal';modal.setAttribute('role','dialog');modal.setAttribute('aria-modal','true');
   const panel=document.createElement('div');panel.className='attachment-preview-panel';
   const close=document.createElement('button');close.type='button';close.className='artifact-image-modal-close';close.textContent='×';close.onclick=()=>modal.remove();
   const heading=document.createElement('h3');heading.textContent=j.attachment?.title||'Attachment';
   const content=document.createElement('div');content.className='attachment-preview-content';renderAttachmentPreview(content,j.attachment,j.preview);
   panel.append(close,heading,content);modal.append(panel);modal.addEventListener('click',ev=>{if(ev.target===modal)modal.remove()});document.body.append(modal);
  }catch(err){notify(err.message||'Could not open attachment');}
 });
}
window.LACAttachmentUI={render:renderAttachments,renderMessage:renderMessageAttachments,refresh,init};
})();
