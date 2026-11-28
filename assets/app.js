(()=>{const core=window.LACChatCore,$=core.$,csrf=core.csrf,post=core.post,toast=core.toast;let current=0,activeRun=null,activeMessageId=0,controller=null,lastAssistant=null,generationStarting=false,pendingStop=false;
LACAttachmentUI.init(()=>current,message=>toast(message));
window.LACChatHistoryUI.init({getCurrentId:()=>current,openConversation,post,toast,onDeleteCurrent:()=>{current=0;$('#messages').innerHTML='';$('#chatTitle').textContent='New chat'}});
async function refreshConversationAttachments(){return LACAttachmentUI.refresh(()=>current);}
const {typesetMath}=window.LACChatRender;
const {md,esc}=window.LACChatMarkdown;
let currentModelAlias='',generationModelLabel='Model';function modelLabel(){return generationModelLabel||currentModelAlias||'Model'}
window.LACChatGenerationUI.init({$: $,getAlias:()=>currentModelAlias,getSelectedModel:selectedModelDisplay});
const {formatHMS,traceDuration,traceStamp,traceLabel,traceSourceLabel,traceLineText}=window.LACChatTraceUtils;
window.LACChatTraceUI.init(()=>current);
const {ensureMessageTrace,renderMessageTrace,showTraceEventDetail,startMessageTrace,toggleMessageTrace}=window.LACChatTraceUI;
const addMessage=(role,text,opts={})=>window.LACChatMessageUI.addMessage(role,text,opts);
const generationUI=window.LACChatGenerationUI;
const syncAssistantActions=(generating=false)=>generationUI.syncAssistantActions(generating);const hidePreviousContinueActions=()=>generationUI.hidePreviousContinueActions();
window.LACChatMessageUI.init({$: $,md,typeset:typesetMath,formatHMS,toggleTrace:toggleMessageTrace,ensureTrace:ensureMessageTrace,attachmentRender:(node,items)=>window.LACAttachmentUI.renderMessage(node,items),notify:toast,generate,isGenerating:()=>!!controller,setLastAssistant:value=>{lastAssistant=value},hidePreviousContinue:hidePreviousContinueActions,syncActions:syncAssistantActions});
async function loadHistory(){return window.LACChatHistoryUI.loadHistory();}
let recoveryTimer=null,recoveryBusy=false,recoveredGeneration=false,recoveredElapsedBase=0,recoveredElapsedAt=0,recoveredStream=null,recoveredStreamMessageId=0;
window.LACChatGenerationRecovery.init({$: $,getCurrent:()=>current,md,typeset:typesetMath,refresh:refreshGenerationSnapshot});
window.LACChatConversationUI.init({$: $,getCurrent:()=>current,setCurrent:id=>{current=id},isGenerating:()=>!!controller,getLastAssistant:()=>lastAssistant,setLastAssistant:value=>{lastAssistant=value},post,stopRecovery,updateToolMode,addMessage,syncAssistantActions,loadHistory,refreshGenerationSnapshot,startRecovery,toast});

const recoveryUI=window.LACChatGenerationRecovery;
const stopRecoveredStream=()=>{recoveryUI.stopRecoveredStream();recoveredStreamMessageId=0;};function stopRecovery(){if(recoveryTimer){clearInterval(recoveryTimer);recoveryTimer=null}stopRecoveredStream();recoveryBusy=false;recoveredGeneration=false;recoveredElapsedBase=0;recoveredElapsedAt=0;const p=$('#prompt');if(p)p.disabled=false;const s=$('#send');if(s)s.disabled=false;}
const startRecoveredStream=(messageId,body,label)=>{recoveredStreamMessageId=Number(messageId);return recoveryUI.startRecoveredStream(messageId,body,label);};async function refreshGenerationSnapshot(){
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
async function openConversation(id,locked){return window.LACChatConversationUI.openConversation(id,locked)}
async function createChat(){return window.LACChatConversationUI.createChat()}
const setGenerating=(on)=>generationUI.setGenerating(on);function selectedModelDisplay(){const sel=$('#chatModel'),opt=sel?.selectedOptions?.[0];const label=(opt?.dataset?.alias||opt?.textContent||currentModelAlias||'').trim();currentModelAlias=label||'Model';return currentModelAlias}
const setAssistantGenerationState=(body,label,state,preparing=false)=>generationUI.setAssistantGenerationState(body,label,state,preparing);window.LACChatGenerationEvents.init({md,post,toast,setState:setAssistantGenerationState,startTrace:startMessageTrace,scroll:()=>{$('#messages').scrollTop=$('#messages').scrollHeight},confirmAction:message=>window.confirm(message)});function generate(mode='send'){return window.LACChatGenerationOrchestrator.generate(mode)}
window.LACChatGenerationOrchestrator.init({$: $,state:{getCurrent:()=>current,setCurrent:id=>{current=id},getController:()=>controller,setController:value=>{controller=value},getActiveRun:()=>activeRun,setActiveRun:value=>{activeRun=value},getActiveMessageId:()=>activeMessageId,setActiveMessageId:value=>{activeMessageId=value},getPendingStop:()=>pendingStop,setPendingStop:value=>{pendingStop=value},getGenerationStarting:()=>generationStarting,setGenerationStarting:value=>{generationStarting=value},getLastAssistant:()=>lastAssistant,setLastAssistant:value=>{lastAssistant=value},getCurrentModelAlias:()=>currentModelAlias,getGenerationModelLabel:()=>generationModelLabel,setGenerationModelLabel:value=>{generationModelLabel=value}},isModelSwitching:()=>window.LACChatModelUI.isSwitching(),isRecoveredGeneration:()=>recoveredGeneration,selectedModelDisplay,createChat,setGenerating,addMessage,setAssistantGenerationState,formatHMS,csrf,selectedDocIds,updateToolMode,agentToolsSavePromise:()=>window.LACChatAgentToolsUI.getSavePromise(),refreshConversationAttachments,loadHistory,startRecovery,toast});window.LACChatComposerUI.init({$: $,generate,isGenerating:()=>!!controller});$('#stop').onclick=async()=>{
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
const updateToolMode=()=>window.LACChatFilePickerUI.updateToolMode();
window.LACChatFilePickerUI.init({$: $,esc,selectedDocIds});
$('#newChat').onclick=createChat;$('#overlay').onclick=()=>$('#sidebar').classList.remove('open');$('#menu').onclick=()=>$('#sidebar').classList.add('open');$('#closeSide').onclick=()=>$('#sidebar').classList.remove('open');
$('#theme').onclick=()=>{let d=document.documentElement;d.dataset.theme=d.dataset.theme==='dark'?'light':'dark';localStorage.setItem('theme',d.dataset.theme)};document.documentElement.dataset.theme=localStorage.getItem('theme')||'light';


async function init(){try{await loadHistory();let j=await(await fetch('api.php?action=status')).json();$('#status').classList.toggle('offline',!j.online);$('#status').innerHTML='<i></i> '+(j.online?'Online':'Offline');}catch(e){toast(e.message)}}window.LACChatModelUI.init({post,toast,isBusy:()=>!!(controller||generationStarting||recoveredGeneration),setAlias:value=>{currentModelAlias=value;}});window.LACChatModelUI.bind();init();
})();document.addEventListener('gesturestart',e=>e.preventDefault(),{passive:false});document.addEventListener('gesturechange',e=>e.preventDefault(),{passive:false});document.addEventListener('gestureend',e=>e.preventDefault(),{passive:false});document.addEventListener('touchmove',e=>{if(e.touches&&e.touches.length>1)e.preventDefault()},{passive:false});
