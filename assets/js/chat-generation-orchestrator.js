(()=>{'use strict';
let $,deps={};
function init(d){deps=d;$=d.$;}
const s=()=>deps.state;
async function generate(mode='send'){
 const st=s();
 if(deps.isModelSwitching()){deps.toast('Switching model. Please wait for llama-server to become ready.');return;}
 if(st.getController()||st.getGenerationStarting()||deps.isRecoveredGeneration()){deps.toast('This conversation is still generating. Please wait for the model to finish.');return;}
 let message=$('#prompt').value.trim();
 if(mode==='send'&&!message)return;
 st.setGenerationStarting(true);
 st.setGenerationModelLabel(deps.selectedModelDisplay()||st.getCurrentModelAlias()||'Model');
 try{if(!st.getCurrent())await deps.createChat();}catch(e){st.setGenerationStarting(false);deps.toast(e.message);return;}
 deps.setGenerating(true);
 if(mode==='send'){$('#prompt').value='';$('#prompt').style.height='auto';deps.addMessage('user',message);}
 let assistantBody=null,full='',baseText='',usage=null,finishReason=null,stopped=false,startedAt=Date.now(),timer=null,assistantMessageId=0;
 if(mode==='continue'&&st.getLastAssistant()){
  assistantBody=st.getLastAssistant().querySelector('.message-content');baseText=assistantBody.innerText||'';
  st.getLastAssistant().querySelector('[data-act=continue]').hidden=true;st.getLastAssistant().querySelector('[data-act=continue]').disabled=true;
 }else if(mode==='regenerate'&&st.getLastAssistant()){
  assistantBody=st.getLastAssistant().querySelector('.message-content');assistantBody.innerHTML='';
  const tools=st.getLastAssistant().querySelector('.message-tools');if(tools){tools.querySelector('.token-meta').textContent='';tools.querySelector('[data-act=continue]').hidden=true;}
  const elapsed=st.getLastAssistant().querySelector('.elapsed');if(elapsed)elapsed.textContent='00:00:00';
 }else{assistantBody=deps.addMessage('assistant','');}
 deps.setAssistantGenerationState(assistantBody,st.getGenerationModelLabel(),'preparing',true);
 st.setController(new AbortController());let attachmentsSubmitted=false;
 timer=setInterval(()=>{const elapsed=assistantBody?.parentElement?.querySelector('.elapsed');if(elapsed)elapsed.textContent=deps.formatHMS(Date.now()-startedAt)},250);
 try{
  await deps.agentToolsSavePromise();let f=new FormData();f.append('_csrf',deps.csrf);f.append('id',st.getCurrent());f.append('mode',mode);f.append('message',message);
  if($('#chatModel')?.value)f.append('model',$('#chatModel').value);
  const webToggle=$('#webSearchToggle');f.append('web_search',webToggle?.checked?'1':'0');f.append('tool_website',$('#toolWebsites')?.checked?'1':'0');f.append('selected_doc_ids',JSON.stringify([...deps.selectedDocIds]));
  let response=await fetch('api.php?action=chat_stream',{method:'POST',body:f,signal:st.getController().signal});
  if(!response.ok){let j=await response.json();throw Error(j.error||'Could not send request');}
  attachmentsSubmitted=true;
  let reader=response.body.getReader(),decoder=new TextDecoder(),buffer='';
  while(true){let part=await reader.read();if(part.done)break;buffer+=decoder.decode(part.value,{stream:true});const parsed=window.LACChatSSE.parseBuffer(buffer);buffer=parsed.remainder;
   for(let block of parsed.blocks){const {event:ev,data}=window.LACChatSSE.parseBlock(block);if(!data)continue;let obj;try{obj=JSON.parse(data)}catch{continue}
    const eventContext={assistantBody,baseText,full,usage,finishReason,stopped,assistantMessageId,activeRun:st.getActiveRun(),activeMessageId:st.getActiveMessageId(),pendingStop:st.getPendingStop(),generationModelLabel:st.getGenerationModelLabel(),currentModelAlias:st.getCurrentModelAlias()};
    await window.LACChatGenerationEvents.handle(ev,obj,eventContext);
    ({full,usage,finishReason,stopped,assistantMessageId}=eventContext);
    st.setActiveRun(eventContext.activeRun);st.setActiveMessageId(eventContext.activeMessageId);st.setGenerationModelLabel(eventContext.generationModelLabel);st.setPendingStop(eventContext.pendingStop);
   }
  }
  if(assistantBody){clearInterval(timer);let msg=assistantBody.parentElement;deps.setAssistantGenerationState(assistantBody,st.getGenerationModelLabel(),'complete',false);msg.querySelector('.elapsed').textContent=deps.formatHMS(Date.now()-startedAt);let tools=msg.querySelector('.message-tools');if(usage)tools.querySelector('.token-meta').textContent='Token: '+(usage.total_tokens??'no metrics');let interrupted=stopped||finishReason==='length'||finishReason==='max_tokens';tools.querySelector('[data-act=continue]').hidden=!interrupted;tools.querySelector('[data-act=continue]').disabled=false;}
  if(assistantMessageId){if(assistantBody?.parentElement)assistantBody.parentElement.dataset.messageId=String(assistantMessageId);await deps.refreshConversationAttachments(assistantMessageId);}
  await deps.loadHistory();
 }catch(e){
  const transient=(e.name==='TypeError'||e.name==='NetworkError'||/network|fetch|load failed|failed to fetch/i.test(String(e.message||'')));
  if(e.name!=='AbortError'&&!transient)deps.toast(e.message);
  if(assistantBody&&!transient){clearInterval(timer);let msg=assistantBody.parentElement;deps.setAssistantGenerationState(assistantBody,st.getGenerationModelLabel(),'complete',false);msg.querySelector('.elapsed').textContent=deps.formatHMS(Date.now()-startedAt);let t=msg.querySelector('[data-act=continue]');t.hidden=false;t.disabled=false;}
  else if(assistantBody&&transient){const msg=assistantBody.parentElement;msg.classList.add('is-generating');deps.setAssistantGenerationState(assistantBody,st.getGenerationModelLabel(),full?'typing':'preparing',!full);}
 }finally{
  if(timer)clearInterval(timer);st.setController(null);st.setActiveRun(null);st.setActiveMessageId(0);st.setPendingStop(false);st.setGenerationStarting(false);
  if(attachmentsSubmitted){deps.selectedDocIds.clear();deps.updateToolMode();}
  if(assistantBody){deps.setGenerating(false);deps.startRecovery();}else deps.setGenerating(false);
 }
}
window.LACChatGenerationOrchestrator={init,generate};
})();