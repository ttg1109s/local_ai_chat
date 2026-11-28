(()=>{
'use strict';
const {traceLineText}=window.LACChatTraceUtils;
let getConversationId=()=>0;
function init(getter){getConversationId=typeof getter==='function'?getter:()=>0;}
function ensureMessageTrace(messageEl){
 if(!messageEl)return null;
 let panel=messageEl.querySelector('.message-trace');
 if(panel)return panel;
 panel=document.createElement('section');panel.className='message-trace is-live';panel.hidden=true;
 panel.innerHTML='<div class="message-trace-events" aria-live="polite"></div>';
 const attachmentList=messageEl.querySelector('.message-attachments');
 const body=messageEl.querySelector('.message-content');
 if(attachmentList)attachmentList.after(panel);else if(body)body.after(panel);else messageEl.append(panel);
  return panel;
}
function renderMessageTrace(panel){
 const eventsNode=panel.querySelector('.message-trace-events');
 if(!eventsNode)return;
 const all=panel._traceEvents||[];
 // Collapsed Preparing/Typing view shows one rolling newest line.
 // Expanded view keeps the complete log in a five-row independently scrollable viewport.
 const wasNearBottom=(eventsNode.scrollHeight-eventsNode.scrollTop-eventsNode.clientHeight)<36;
 const visible=panel._showAll?all:all.slice(-1);
 eventsNode.replaceChildren();
 for(const ev of visible){
  const row=document.createElement('div');
  const level=String(ev.level||ev.severity||'info').toLowerCase();
  row.className='message-trace-line level-'+(level==='warning'?'warn':level);
  const line=traceLineText(ev);
  row.textContent=line;
  row.title=line+'\n\n'+JSON.stringify(ev,null,2);
  eventsNode.append(row);
 }
 if(panel._showAll&&wasNearBottom)eventsNode.scrollTop=eventsNode.scrollHeight;
}
function showTraceEventDetail(panel,ev){
 let detail=panel.querySelector('.message-trace-selected');
 if(!detail){detail=document.createElement('pre');detail.className='message-trace-selected';panel.append(detail)}
 let raw;try{raw=JSON.stringify(ev,null,2)}catch(_){raw=String(ev)}
 detail.textContent=raw||String(ev);
}
function startMessageTrace(messageEl,messageId){
 const panel=ensureMessageTrace(messageEl);if(!panel||!messageId)return;
 if(panel.dataset.messageId===String(messageId)&&panel._poll)return;
 if(panel._poll)clearInterval(panel._poll);
 panel.dataset.messageId=String(messageId);panel.hidden=false;panel.classList.add('is-live');
 panel._traceEvents=[];panel._traceLastSequence=0;panel._traceFinished=false;panel._showAll=false;panel.classList.remove('show-all');
 const poll=async()=>{
  if(panel._traceBusy||panel._traceFinished||!document.body.contains(panel))return;
  panel._traceBusy=true;
  try{
   const r=await fetch('api.php?action=generation_trace&id='+encodeURIComponent(getConversationId())+'&message_id='+encodeURIComponent(messageId)+'&after_sequence='+encodeURIComponent(panel._traceLastSequence),{cache:'no-store'});
   const j=await r.json();if(!r.ok)throw Error(j.error||'Could not load trace');
   for(const ev of (j.events||[])){
    const seq=Number(ev.sequence)||panel._traceLastSequence+1;
    panel._traceLastSequence=Math.max(panel._traceLastSequence,seq);
    if(ev.event==='delta')continue;
    panel._traceEvents.push(ev);
    if(ev.event==='finish')panel._traceFinished=true;
   }
   renderMessageTrace(panel);
   if(panel._traceFinished){
    panel.classList.remove('is-live');
    clearInterval(panel._poll);panel._poll=null;
    // Hide automatically after completion; clicking the model status reopens it.
    setTimeout(()=>{if(!panel._keepOpen)panel.hidden=true},1200);
   }
  }catch(e){
   const live=panel.querySelector('.message-trace-live');
   if(live)live.textContent='Could not load trace · '+String(e.message||e);
  }finally{panel._traceBusy=false}
 };
 poll();panel._poll=setInterval(poll,300);
}
function toggleMessageTrace(messageEl){
 const panel=ensureMessageTrace(messageEl);if(!panel)return;
 if(panel.hidden){
  panel.hidden=false;panel._keepOpen=true;
  if(messageEl.dataset.messageId&&!panel._traceFinished)startMessageTrace(messageEl,Number(messageEl.dataset.messageId));
  panel._showAll=true;
  panel.classList.add('show-all');
  renderMessageTrace(panel);
  const list=panel.querySelector('.message-trace-events');
  if(list)list.scrollTop=list.scrollHeight;
 }else{
  panel.hidden=true;panel._showAll=false;panel._keepOpen=false;
  panel.classList.remove('show-all');
 }
}


window.LACChatTraceUI={init,ensureMessageTrace,renderMessageTrace,showTraceEventDetail,startMessageTrace,toggleMessageTrace};
})();
