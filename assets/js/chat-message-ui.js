(()=>{'use strict';
let $,md=()=>'',typeset=()=>{},formatHMS=()=>'',toggleTrace=()=>{},ensureTrace=()=>{},attachmentRender=()=>{},notify=()=>{},gen=async()=>{},isGenerating=()=>false,setLastAssistant=()=>{},hidePreviousContinue=()=>{},syncActions=()=>{};
function init(deps={}){$=deps.$;md=deps.md||md;typeset=deps.typeset||typeset;formatHMS=deps.formatHMS||formatHMS;toggleTrace=deps.toggleTrace||toggleTrace;ensureTrace=deps.ensureTrace||ensureTrace;attachmentRender=deps.attachmentRender||attachmentRender;notify=deps.notify||notify;gen=deps.generate||gen;isGenerating=deps.isGenerating||isGenerating;setLastAssistant=deps.setLastAssistant||setLastAssistant;hidePreviousContinue=deps.hidePreviousContinue||hidePreviousContinue;syncActions=deps.syncActions||syncActions;}
function addMessage(role,text,opts={}){
 if(role==='user'){hidePreviousContinue();syncActions(true);}
 const d=document.createElement('article');d.className='message '+role;d.dataset.role=role;
 if(opts.message_id)d.dataset.messageId=String(opts.message_id);
 const body=document.createElement('div');body.className='message-content';body.innerHTML=md(text);d.append(body);$('#messages').append(d);typeset(body);
 if(role==='assistant'){
  if(opts.running)d.classList.add('is-generating');
  if(Array.isArray(opts.attachments)&&opts.attachments.length&&window.LACAttachmentUI)attachmentRender(d,opts.attachments);
  const status=document.createElement('div');status.className='model-status';status.title='Expand / collapse log';status.style.cursor='pointer';
  status.addEventListener('click',()=>toggleTrace(d));
  status.innerHTML='<span class="model-state"></span><span class="generation-hint" hidden>Preparing response…</span><span class="elapsed">00:00:00</span>';
  status.querySelector('.model-state').textContent=opts.model_label?(opts.model_label+(opts.status_label?' · '+opts.status_label:'')):'';status.querySelector('.elapsed').textContent=formatHMS((Number(opts.elapsed_seconds)||0)*1000);
  ensureTrace(d);
  const tools=document.createElement('div');tools.className='message-tools';
  tools.innerHTML='<button data-act="copy" title="Copy" aria-label="Copy"><span class="material-icons-round">content_copy</span></button><button data-act="regenerate" title="Regenerate" aria-label="Regenerate"><span class="material-icons-round">refresh</span></button><button data-act="continue" title="Continue" aria-label="Continue" hidden><span class="material-icons-round">play_arrow</span></button><span class="token-meta"></span>';
  const bottom=document.createElement('div');bottom.className='message-meta-bottom';bottom.append(status,tools);d.append(bottom);
  tools.querySelector('[data-act=copy]').onclick=()=>{navigator.clipboard?.writeText(body.innerText);notify('Copied')};
  tools.querySelector('[data-act=regenerate]').onclick=()=>gen('regenerate');
  tools.querySelector('[data-act=continue]').onclick=()=>{const b=tools.querySelector('[data-act=continue]');if(b.hidden||isGenerating())return;b.hidden=true;b.disabled=true;generate('continue').finally(()=>{b.disabled=false})};
  if(opts.continue)tools.querySelector('[data-act=continue]').hidden=false;
  if(opts.usage)tools.querySelector('.token-meta').textContent='Tokens: '+(opts.usage.total_tokens??'No metrics');
  else if(opts.token_count!==null&&opts.token_count!==undefined)tools.querySelector('.token-meta').textContent='Tokens: '+opts.token_count;
  d.querySelector('[data-act="regenerate"]').hidden=true;setLastAssistant(d);
 }
 $('#messages').scrollTop=$('#messages').scrollHeight;return body;
}
window.LACChatMessageUI={init,addMessage};
})();