(()=>{const core=window.LACChatCore,$=core.$,csrf=core.csrf,post=core.post,toast=core.toast;let current=0,activeRun=null,activeMessageId=0,controller=null,lastAssistant=null,generationStarting=false;
LACAttachmentUI.init(()=>current,message=>toast(message));
window.LACChatHistoryUI.init({getCurrentId:()=>current,openConversation,post,toast,onDeleteCurrent:()=>{current=0;$('#messages').innerHTML='';$('#chatTitle').textContent='New chat'}});
async function refreshConversationAttachments(){return LACAttachmentUI.refresh(()=>current);}
const {typesetMath}=window.LACChatRender;
const {md,esc}=window.LACChatMarkdown;
let currentModelAlias='',generationModelLabel='Model';function modelLabel(){return generationModelLabel||currentModelAlias||'Model'}
const {formatHMS,traceDuration,traceStamp,traceLabel,traceSourceLabel,traceLineText}=window.LACChatTraceUtils;
window.LACChatTraceUI.init(()=>current);
const {ensureMessageTrace,renderMessageTrace,showTraceEventDetail,startMessageTrace,toggleMessageTrace}=window.LACChatTraceUI;
function addMessage(role,text,opts={}){
 if(role==='user'){hidePreviousContinueActions();syncAssistantActions(true);}
 const d=document.createElement('article');d.className='message '+role;d.dataset.role=role;
 if(opts.message_id)d.dataset.messageId=String(opts.message_id);
 const body=document.createElement('div');body.className='message-content';body.innerHTML=md(text);d.append(body);$('#messages').append(d);typesetMath(body);
 if(role==='assistant'){
  if(opts.running)d.classList.add('is-generating');
  if(Array.isArray(opts.attachments)&&opts.attachments.length&&window.LACAttachmentUI)window.LACAttachmentUI.renderMessage(d,opts.attachments);
  const status=document.createElement('div');status.className='model-status';status.title='Expand / collapse log';status.style.cursor='pointer';
  status.addEventListener('click',()=>toggleMessageTrace(d));
  status.innerHTML='<span class="model-state"></span><span class="generation-hint" hidden>Preparing response…</span><span class="elapsed">00:00:00</span>';
  status.querySelector('.model-state').textContent=opts.model_label?(opts.model_label+(opts.status_label?' · '+opts.status_label:'')):'';status.querySelector('.elapsed').textContent=formatHMS((Number(opts.elapsed_seconds)||0)*1000);
  ensureMessageTrace(d);
  const tools=document.createElement('div');tools.className='message-tools';
  tools.innerHTML='<button data-act="copy" title="Copy" aria-label="Copy"><span class="material-icons-round">content_copy</span></button><button data-act="regenerate" title="Regenerate" aria-label="Regenerate"><span class="material-icons-round">refresh</span></button><button data-act="continue" title="Continue" aria-label="Continue" hidden><span class="material-icons-round">play_arrow</span></button><span class="token-meta"></span>';
  const bottom=document.createElement('div');bottom.className='message-meta-bottom';bottom.append(status,tools);d.append(bottom);
  tools.querySelector('[data-act=copy]').onclick=()=>{navigator.clipboard?.writeText(body.innerText);toast('Copied')};
  tools.querySelector('[data-act=regenerate]').onclick=()=>generate('regenerate');
  tools.querySelector('[data-act=continue]').onclick=()=>{const b=tools.querySelector('[data-act=continue]');if(b.hidden||controller)return;b.hidden=true;b.disabled=true;generate('continue').finally(()=>{b.disabled=false})};
  if(opts.continue)tools.querySelector('[data-act=continue]').hidden=false;
  if(opts.usage)tools.querySelector('.token-meta').textContent='Tokens: '+(opts.usage.total_tokens??'No metrics');
  else if(opts.token_count!==null&&opts.token_count!==undefined)tools.querySelector('.token-meta').textContent='Tokens: '+opts.token_count;
  d.querySelector('[data-act="regenerate"]').hidden=true;lastAssistant=d;
 }
 $('#messages').scrollTop=$('#messages').scrollHeight;return body;
}
function syncAssistantActions(generating=false){
 const messages=[...$('#messages').querySelectorAll('.message.assistant')];
 messages.forEach((msg,index)=>{const regen=msg.querySelector('[data-act="regenerate"]');if(regen)regen.hidden=generating||msg.classList.contains('is-generating')||index!==messages.length-1;});
}
function hidePreviousContinueActions(){
 $('#messages').querySelectorAll('.message.assistant [data-act="continue"]').forEach(button=>{button.hidden=true;button.disabled=true;});
}
async function loadHistory(){return window.LACChatHistoryUI.loadHistory();}
let recoveryTimer=null,recoveryBusy=false,recoveredGeneration=false,recoveredElapsedBase=0,recoveredElapsedAt=0,recoveredStream=null,recoveredStreamMessageId=0;
function stopRecoveredStream(){if(recoveredStream){recoveredStream.close();recoveredStream=null}recoveredStreamMessageId=0;}
function stopRecovery(){if(recoveryTimer){clearInterval(recoveryTimer);recoveryTimer=null}stopRecoveredStream();recoveryBusy=false;recoveredGeneration=false;recoveredElapsedBase=0;recoveredElapsedAt=0;const p=$('#prompt');if(p)p.disabled=false;const s=$('#send');if(s)s.disabled=false;}
function startRecoveredStream(messageId,body,label){
 if(recoveredStream&&recoveredStreamMessageId===Number(messageId))return;
 stopRecoveredStream();recoveredStreamMessageId=Number(messageId);
 const es=new EventSource('api.php?action=generation_watch&id='+encodeURIComponent(current)+'&message_id='+encodeURIComponent(messageId));
 recoveredStream=es;let rawContent=null;
 const render=()=>{if(body&&rawContent!==null)body.innerHTML=md(rawContent);typesetMath(body);};
 es.addEventListener('snapshot',e=>{try{const data=JSON.parse(e.data);rawContent=String(data.content??'');render();$('#messages').scrollTop=$('#messages').scrollHeight}catch(err){}});
 es.addEventListener('delta',e=>{try{const data=JSON.parse(e.data);if(rawContent===null)rawContent='';rawContent+=String(data.text??'');render();$('#messages').scrollTop=$('#messages').scrollHeight}catch(err){}});
 es.addEventListener('complete',e=>{try{const data=JSON.parse(e.data);if(data.content!==undefined)rawContent=String(data.content);render();}catch(err){}es.close();if(recoveredStream===es){recoveredStream=null;recoveredStreamMessageId=0;}refreshGenerationSnapshot();});
 es.onerror=()=>{/* EventSource reconnects automatically; generation continues independently on the backend. */};
}
async function refreshGenerationSnapshot(){
 if(!current||recoveryBusy||controller)return;recoveryBusy=true;
 try{
  const conversationId=current;
  const r=await fetch('api.php?action=generation_snapshot&id='+encodeURIComponent(conversationId)+'&_='+Date.now(),{cache:'no-store'});
  if(!r.ok)return;
  const snap=await r.json(),m=snap.message;if(!m)return;
  if(conversationId!==current)return;
  const assistants=[...$('#messages').querySelectorAll('.message.assistant')];
  let msg=assistants[assistants.length-1];
  if(!msg||Number(msg.dataset.messageId||0)!==Number(m.id)){
   if(!recoveryReloading){recoveryReloading=true;try{await openConversation(conversationId,false)}finally{recoveryReloading=false}}
   return;
  }
  msg.dataset.messageId=String(m.id);
  if(snap.running)startMessageTrace(msg,Number(m.id));
  const body=msg.querySelector('.message-content');
  // Snapshot remains the authoritative fallback if the watcher reconnects or is unavailable.
  if(body&&body.innerHTML!==md(m.content))body.innerHTML=md(m.content);
  const label=m.model_label||'Model';
  if(snap.running){
   if(!recoveredGeneration){recoveredElapsedBase=Number(m.elapsed_seconds)||0;recoveredElapsedAt=Date.now();}
   else {
    const locallyElapsed=recoveredElapsedBase+Math.floor((Date.now()-recoveredElapsedAt)/1000);
    const serverElapsed=Number(m.elapsed_seconds)||0;
    if(serverElapsed>locallyElapsed){recoveredElapsedBase=serverElapsed;recoveredElapsedAt=Date.now();}
   }
   recoveredGeneration=true;
   setAssistantGenerationState(body,label,(m.status_label==='preparing'?'preparing':'typing'),m.status_label==='preparing');
   msg.classList.add('is-generating');
   const cont=msg.querySelector('[data-act=continue]');if(cont){cont.hidden=true;cont.disabled=true}
   const regen=msg.querySelector('[data-act=regenerate]');if(regen){regen.hidden=true;regen.disabled=true}
   const p=$('#prompt');if(p)p.disabled=true;
   const send=$('#send');if(send)send.disabled=true;
   const stop=$('#stop');if(stop){stop.hidden=false;stop.disabled=false;stop.querySelector('.material-icons-round').textContent='stop';}
   setGenerating(true);
   const elapsed=msg.querySelector('.elapsed');
   if(elapsed)elapsed.textContent=formatHMS((recoveredElapsedBase+Math.floor((Date.now()-recoveredElapsedAt)/1000))*1000);
   startRecoveredStream(m.id,body,label);
  }else{
   stopRecoveredStream();
   recoveredGeneration=false;
   setAssistantGenerationState(body,label,m.status_label==='complete'?'complete':(m.status_label||'complete'),false);
   msg.classList.remove('is-generating');
   const elapsed=msg.querySelector('.elapsed');if(elapsed)elapsed.textContent=formatHMS((Number(m.elapsed_seconds)||0)*1000);
   const cont=msg.querySelector('[data-act=continue]');if(cont){cont.hidden=!(Number(m.interrupted)===1);cont.disabled=false}
   const regen=msg.querySelector('[data-act=regenerate]');if(regen){regen.hidden=false;regen.disabled=false}
   const token=msg.querySelector('.token-meta');if(token&&m.token_count!==null)token.textContent='Token: '+m.token_count;
   stopRecovery();const prompt=$('#prompt');if(prompt)prompt.disabled=false;const send=$('#send');if(send)send.disabled=false;msg.classList.remove('is-generating');setGenerating(false);
   const stop=$('#stop');if(stop)stop.hidden=true;
   syncAssistantActions(false);
  }
 }catch(e){/* Retry on next poll. */}
 finally{recoveryBusy=false}
}
function startRecovery(){if(recoveryTimer)clearInterval(recoveryTimer);recoveryTimer=null;recoveryBusy=false;refreshGenerationSnapshot();recoveryTimer=setInterval(refreshGenerationSnapshot,1000)}
let recoveryReloading=false;
async function openConversation(id,locked){if(controller)return;try{if(locked){let pw=prompt('This conversation is protected. Enter the password to access it:');if(pw===null)return;await post('conversation_password',{id,password:pw,operation:'unlock'})}let r=await fetch('api.php?action=history&id='+id),j=await r.json();if(!r.ok)throw Error(j.error||'Could not open conversation');stopRecovery();current=id;$('#chatTitle').textContent=j.conversation.title;updateToolMode();$('#messages').innerHTML='';lastAssistant=null;j.messages.forEach(m=>addMessage(m.role,m.content,{continue:Number(m.interrupted)===1&&!['preparing','typing','generating','continuing'].includes(m.status_label),running:['preparing','typing','generating','continuing'].includes(m.status_label),message_id:m.role==='assistant'?m.id:undefined,model_label:m.model_label,model_id:m.model_id,status_label:m.status_label,elapsed_seconds:m.elapsed_seconds,token_count:m.token_count,attachments:m.attachments||[]}));syncAssistantActions(false);loadHistory();$('#sidebar').classList.remove('open');await refreshGenerationSnapshot();startRecovery()}catch(e){toast(e.message)}}
async function createChat(){stopRecovery();let j=await post('new');current=j.id;$('#messages').innerHTML='';$('#chatTitle').textContent='New chat';updateToolMode();lastAssistant=null;await loadHistory()}
function setGenerating(on){$('#send').hidden=on;$('#stop').hidden=!on;$('#send').disabled=on;$('#stop').disabled=false;$('#stop .material-icons-round').textContent='stop';syncAssistantActions(!!on);}
function selectedModelDisplay(){const sel=$('#chatModel'),opt=sel?.selectedOptions?.[0];const label=(opt?.dataset?.alias||opt?.textContent||currentModelAlias||'').trim();currentModelAlias=label||'Model';return currentModelAlias}
function setAssistantGenerationState(body,label,state,preparing=false){if(!body)return;const msg=body.closest('.message');const safeLabel=(String(label||msg?.dataset?.modelAlias||currentModelAlias||selectedModelDisplay()||'Model').trim()||'Model');if(msg)msg.dataset.modelAlias=safeLabel;const node=msg?.querySelector('.model-state');if(node)node.textContent=safeLabel+' · '+state;const hint=msg?.querySelector('.generation-hint');if(hint){hint.hidden=true;hint.textContent='';}if(state==='typing'||state==='preparing')msg?.classList.add('is-generating');else msg?.classList.remove('is-generating');}
async function generate(mode='send'){
 if(window.LACChatModelUI.isSwitching()){toast('Switching model. Please wait for llama-server to become ready.');return;}if(controller||generationStarting||recoveredGeneration){toast('This conversation is still generating. Please wait for the model to finish.');return;}
 let message=$('#prompt').value.trim();
 if(mode==='send'&&!message)return;
 generationStarting=true;
 generationModelLabel=selectedModelDisplay()||currentModelAlias||'Model';
 try{if(!current)await createChat()}catch(e){generationStarting=false;toast(e.message);return}
 setGenerating(true);
 if(mode==='send'){$('#prompt').value='';$('#prompt').style.height='auto';addMessage('user',message)}
 let assistantBody=null,full='',baseText='',usage=null,finishReason=null,stopped=false,startedAt=Date.now(),timer=null,pendingStop=false,assistantMessageId=0;
 if(mode==='continue'&&lastAssistant){
   assistantBody=lastAssistant.querySelector('.message-content');
   baseText=assistantBody.innerText||'';
   lastAssistant.querySelector('[data-act=continue]').hidden=true;
   lastAssistant.querySelector('[data-act=continue]').disabled=true;
 }else if(mode==='regenerate'&&lastAssistant){
   // Regenerate replaces the existing assistant bubble; do not append a duplicate bubble.
   assistantBody=lastAssistant.querySelector('.message-content');
   assistantBody.innerHTML='';
   const tools=lastAssistant.querySelector('.message-tools');
   if(tools){tools.querySelector('.token-meta').textContent='';tools.querySelector('[data-act=continue]').hidden=true;}
   const elapsed=lastAssistant.querySelector('.elapsed');if(elapsed)elapsed.textContent='00:00:00';
 }else{
   assistantBody=addMessage('assistant','');
 }
 setAssistantGenerationState(assistantBody,generationModelLabel,'preparing',true);
 controller=new AbortController();let attachmentsSubmitted=false;
 timer=setInterval(()=>{const elapsed=assistantBody?.parentElement?.querySelector('.elapsed');if(elapsed)elapsed.textContent=formatHMS(Date.now()-startedAt)},250);
 try{
   await agentToolsSavePromise;let f=new FormData();f.append('_csrf',csrf);f.append('id',current);f.append('mode',mode);f.append('message',message);
   if($('#chatModel')?.value)f.append('model',$('#chatModel').value);
   const webToggle=$('#webSearchToggle');f.append('web_search',webToggle?.checked?'1':'0');f.append('tool_website',$('#toolWebsites')?.checked?'1':'0');f.append('selected_doc_ids',JSON.stringify([...selectedDocIds]));
   let response=await fetch('api.php?action=chat_stream',{method:'POST',body:f,signal:controller.signal});
   if(!response.ok){let j=await response.json();throw Error(j.error||'Could not send request')}
   attachmentsSubmitted=true;
   let reader=response.body.getReader(),decoder=new TextDecoder(),buffer='';
   while(true){
     let part=await reader.read();if(part.done)break;
     buffer+=decoder.decode(part.value,{stream:true});
     buffer=buffer.replace(/\r\n/g,'\n').replace(/\r/g,'\n');
     let blocks=buffer.split('\n\n');buffer=blocks.pop();
     for(let block of blocks){
       let ev=(block.match(/^event:\s*(.+)$/m)||[])[1],data=(block.match(/^data:\s*(.+)$/m)||[])[1];if(!data)continue;
       let obj;try{obj=JSON.parse(data)}catch{continue}
       if(ev==='meta'){
         activeRun=obj.run_id;if(obj.message_id){const traceMessage=assistantBody?.closest('.message');traceMessage?.setAttribute('data-message-id',String(obj.message_id));if(traceMessage)startMessageTrace(traceMessage,Number(obj.message_id));}
         generationModelLabel=(obj.model_label&&String(obj.model_label).trim())?String(obj.model_label).trim():(generationModelLabel||currentModelAlias||'Model');
         setAssistantGenerationState(assistantBody,generationModelLabel,'preparing',true);
         if(pendingStop)post('stop_generation',{run_id:activeRun}).catch(()=>{});
       }else if(ev==='clear_delta'){
         full='';
         assistantBody.innerHTML=md(baseText);
         setAssistantGenerationState(assistantBody,generationModelLabel,'preparing',true);
       }else if(ev==='delta'){
         setAssistantGenerationState(assistantBody,generationModelLabel,'typing',false);
         full+=obj.text;
         assistantBody.innerHTML=md(baseText+full);
         $('#messages').scrollTop=$('#messages').scrollHeight;
       }else if(ev==='approval_required'){
         const pending=Array.isArray(obj.pending)?obj.pending:(obj.pending?[obj.pending]:[]);
         const first=pending[0]||{};
         const detail=(first.tool||'action')+' · '+(first.required_approvals||first.risks||[]).join(', ')+' · arguments='+JSON.stringify(first.arguments||{}).slice(0,700);
         const approved=window.confirm('The Agent completed the safe steps. Approval is required before this action: '+detail+'.\n\nApprove this action?');
         if(approved){try{const result=await post('agent_task_approve',{run_id:obj.run_id||activeRun});toast(result.state==='waiting_approval'?'Approved step executed; another action still requires approval.':'Task resumed.');}catch(e){toast('Could not resume task: '+e.message);}}
         else {try{await post('agent_task_reject',{run_id:obj.run_id||activeRun});toast('Action rejected; task stopped.');}catch(e){toast('Could not record rejection: '+e.message);}}
       }else if(ev==='tool_start'||ev==='tool_result'||ev==='tool_error'){/* Tool activity remains in runtime traces; do not render per-message tool boxes. */}else if(ev==='usage')usage=obj;
       else if(ev==='finish')finishReason=obj.reason;
       else if(ev==='error')throw Error(obj.message||'Model error');
       else if(ev==='done'){stopped=!!obj.stopped;usage=obj.usage||usage;finishReason=obj.finish_reason||finishReason;assistantMessageId=Number(obj.message_id)||0}
     }
   }
   if(assistantBody){
     clearInterval(timer);
     let msg=assistantBody.parentElement;
     setAssistantGenerationState(assistantBody,generationModelLabel,'complete',false);
     msg.querySelector('.elapsed').textContent=formatHMS(Date.now()-startedAt);
     let tools=msg.querySelector('.message-tools');
     if(usage)tools.querySelector('.token-meta').textContent='Token: '+(usage.total_tokens??'no metrics');
     let interrupted=stopped||finishReason==='length'||finishReason==='max_tokens';
     tools.querySelector('[data-act=continue]').hidden=!interrupted;
     tools.querySelector('[data-act=continue]').disabled=false;
   }
   if(assistantMessageId){if(assistantBody?.parentElement)assistantBody.parentElement.dataset.messageId=String(assistantMessageId);await refreshConversationAttachments(assistantMessageId);}
   await loadHistory();
 }catch(e){
   const transient=(e.name==='TypeError'||e.name==='NetworkError'||/network|fetch|load failed|failed to fetch/i.test(String(e.message||'')));
   if(e.name!=='AbortError'&&!transient)toast(e.message);
   if(assistantBody&&!transient){
     clearInterval(timer);
     let msg=assistantBody.parentElement;
     setAssistantGenerationState(assistantBody,generationModelLabel,'complete',false);
     msg.querySelector('.elapsed').textContent=formatHMS(Date.now()-startedAt);
     let t=msg.querySelector('[data-act=continue]');t.hidden=false;t.disabled=false;
   }else if(assistantBody&&transient){
     // Browser may suspend/close a streaming connection when the tab is backgrounded.
     // Keep the bubble in preparing/typing state; server-side snapshot/SSE watcher is authoritative.
     const msg=assistantBody.parentElement;msg.classList.add('is-generating');
     setAssistantGenerationState(assistantBody,generationModelLabel,full?'typing':'preparing',!full);
   }
 }finally{
   if(timer)clearInterval(timer);
   controller=null;activeRun=null;activeMessageId=0;pendingStop=false;generationStarting=false;
   if(attachmentsSubmitted){selectedDocIds.clear();updateToolMode();}
   if(assistantBody){setGenerating(false);startRecovery();}
   else setGenerating(false);
 }
}
$('#chatForm').onsubmit=e=>{e.preventDefault();if(!controller)generate('send')};$('#send').addEventListener('click',()=>{if(!$('#prompt').value.trim()||$('#send').disabled)return;const icon=$('#send .material-icons-round');if(!icon)return;const source=icon.getBoundingClientRect(),area=$('#messages').getBoundingClientRect();const startX=source.left+source.width/2,startY=source.top+source.height/2;const endX=area.left+area.width/2,endY=area.top+Math.min(112,Math.max(56,area.height*.2));const ghost=icon.cloneNode(true);ghost.className='material-icons-round send-flight';ghost.textContent='arrow_upward';ghost.setAttribute('aria-hidden','true');ghost.style.left=startX+'px';ghost.style.top=startY+'px';ghost.style.setProperty('--fly-x',(endX-startX)+'px');ghost.style.setProperty('--fly-y',(endY-startY)+'px');document.body.append(ghost);const remove=()=>{if(ghost.isConnected)ghost.remove()};ghost.addEventListener('animationend',remove,{once:true});window.setTimeout(remove,1200)});$('#stop').onclick=async()=>{
 if(!controller&&recoveredGeneration&&recoveredStreamMessageId){
   $('#stop').disabled=true;$('#stop .material-icons-round').textContent='hourglass_top';
   try{await post('stop_generation',{message_id:recoveredStreamMessageId,conversation_id:current});toast('Stop request sent…');}
   catch(e){$('#stop').disabled=false;$('#stop .material-icons-round').textContent='stop';toast(e.message)}
   return;
 }
 if(!controller)return;
 $('#stop').disabled=true;$('#stop .material-icons-round').textContent='hourglass_top';
 if(!activeRun){
    pendingStop=true;
    if(activeMessageId){
      try{await post('stop_generation',{message_id:activeMessageId,conversation_id:current});toast('Stopping response…');}
      catch(e){$('#stop').disabled=false;$('#stop .material-icons-round').textContent='stop';toast(e.message)}
    }else toast('Sending stop request…');
    return;
  }
 try{await post('stop_generation',{run_id:activeRun});toast('Stopping response…');}
 catch(e){$('#stop').disabled=false;$('#stop .material-icons-round').textContent='stop';toast(e.message)}
};
const selectedDocIds=new Set();let stagedDocIds=new Set(),pickerDocuments=[];
const {getEnabledAgentTools,saveAgentTools}=window.LACChatAgentToolsUI;
window.LACChatAgentToolsUI.bind();
const updateToolMode=()=>{const el=$('#chatMode');if(el){const n=selectedDocIds.size;el.innerHTML='<span class="mode-label">Chat</span><span class="mode-rag '+(n?'is-on':'')+'"><i></i>RAG'+(n?' · '+n+' files':'')+'</span>';}$('#attachedFileCount').textContent=String(selectedDocIds.size);$('#attachedFileCount').hidden=!selectedDocIds.size;};
const renderFilePicker=()=>{const q=($('#filePickerSearch').value||'').trim().toLocaleLowerCase();const rows=pickerDocuments.filter(d=>String(d.name||'').toLocaleLowerCase().includes(q));$('#filePickerSummary').textContent=stagedDocIds.size+' selected · '+pickerDocuments.length+' documents';$('#filePickerList').innerHTML=rows.map(d=>{const id=Number(d.id),ready=d.status==='completed',checked=stagedDocIds.has(id);return '<label class="file-picker-row '+(ready?'':'is-pending')+'"><input type="checkbox" data-file-id="'+id+'" '+(checked?'checked':'')+' '+(ready?'':'disabled')+'><span class="material-icons-round file-icon">description</span><span class="file-picker-meta"><b>'+esc(d.name)+'</b><small>'+Number(d.chunks||0)+' chunks · '+esc(({queued:'Queued',extracting:'Extracting',chunking:'Chunking',embedding:'Embedding',completed:'Ready',failed:'Error'}[d.status]||d.status||'Unknown'))+'</small></span>'+(ready?'<span class="file-ready">Ready</span>':'<span class="file-pending">Not indexed</span>')+'</label>'}).join('')||'<div class="file-picker-empty">No matching documents found.</div>';$('#filePickerList').querySelectorAll('[data-file-id]').forEach(input=>input.addEventListener('change',()=>{const id=Number(input.dataset.fileId);if(input.checked)stagedDocIds.add(id);else stagedDocIds.delete(id);$('#filePickerSummary').textContent=stagedDocIds.size+' selected · '+pickerDocuments.length+' documents';}));};
const openFilePicker=async()=>{stagedDocIds=new Set(selectedDocIds);const dialog=$('#filePickerDialog');dialog.hidden=false;document.body.classList.add('file-picker-open');$('#filePickerList').innerHTML='<div class="file-picker-empty">Loading library…</div>';try{const r=await fetch('api.php?action=documents',{cache:'no-store'}),j=await r.json();if(!r.ok)throw Error(j.error||'Could not load library');pickerDocuments=(j.items||[]);renderFilePicker();}catch(e){$('#filePickerList').innerHTML='<div class="file-picker-empty">'+esc(e.message)+'</div>';}};
const closeFilePicker=()=>{$('#filePickerDialog').hidden=true;document.body.classList.remove('file-picker-open');updateToolMode();};
$('#addFilesBtn').onclick=openFilePicker;$('#closeFilePicker').onclick=closeFilePicker;$('#filePickerDialog').addEventListener('click',e=>{if(e.target===$('#filePickerDialog'))closeFilePicker()});$('#filePickerSearch').addEventListener('input',renderFilePicker);$('#clearAttachedFiles').onclick=()=>{stagedDocIds.clear();renderFilePicker();};$('#applyAttachedFiles').onclick=()=>{selectedDocIds.clear();stagedDocIds.forEach(id=>selectedDocIds.add(id));closeFilePicker();};
$('#prompt').addEventListener('input',()=>{$('#prompt').style.height='auto';$('#prompt').style.height=Math.min($('#prompt').scrollHeight,180)+'px'});
$('#newChat').onclick=createChat;$('#overlay').onclick=()=>$('#sidebar').classList.remove('open');$('#menu').onclick=()=>$('#sidebar').classList.add('open');$('#closeSide').onclick=()=>$('#sidebar').classList.remove('open');
$('#theme').onclick=()=>{let d=document.documentElement;d.dataset.theme=d.dataset.theme==='dark'?'light':'dark';localStorage.setItem('theme',d.dataset.theme)};document.documentElement.dataset.theme=localStorage.getItem('theme')||'light';


async function init(){try{await loadHistory();let j=await(await fetch('api.php?action=status')).json();$('#status').classList.toggle('offline',!j.online);$('#status').innerHTML='<i></i> '+(j.online?'Online':'Offline');}catch(e){toast(e.message)}}window.LACChatModelUI.init({post,toast,isBusy:()=>!!(controller||generationStarting||recoveredGeneration),setAlias:value=>{currentModelAlias=value;}});window.LACChatModelUI.bind();init();
})();document.addEventListener('gesturestart',e=>e.preventDefault(),{passive:false});document.addEventListener('gesturechange',e=>e.preventDefault(),{passive:false});document.addEventListener('gestureend',e=>e.preventDefault(),{passive:false});document.addEventListener('touchmove',e=>{if(e.touches&&e.touches.length>1)e.preventDefault()},{passive:false});
