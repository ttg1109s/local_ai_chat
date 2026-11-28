(()=>{'use strict';
let $,getCurrent=()=>0,md=()=>'',typeset=()=>{},refresh=()=>{},stream=null,streamMessageId=0;
function init(deps={}){$=deps.$;getCurrent=deps.getCurrent||getCurrent;md=deps.md||md;typeset=deps.typeset||typeset;refresh=deps.refresh||refresh;}
function stopRecoveredStream(){if(stream){stream.close();stream=null}streamMessageId=0;}
function startRecoveredStream(messageId,body,label){
 if(stream&&streamMessageId===Number(messageId))return;
 stopRecoveredStream();streamMessageId=Number(messageId);
 const es=new EventSource('api.php?action=generation_watch&id='+encodeURIComponent(getCurrent())+'&message_id='+encodeURIComponent(messageId));
 stream=es;let rawContent=null;
 const render=()=>{if(body&&rawContent!==null)body.innerHTML=md(rawContent);typeset(body);};
 es.addEventListener('snapshot',e=>{try{const data=JSON.parse(e.data);rawContent=String(data.content??'');render();$('#messages').scrollTop=$('#messages').scrollHeight}catch(err){}});
 es.addEventListener('delta',e=>{try{const data=JSON.parse(e.data);if(rawContent===null)rawContent='';rawContent+=String(data.text??'');render();$('#messages').scrollTop=$('#messages').scrollHeight}catch(err){}});
 es.addEventListener('complete',e=>{try{const data=JSON.parse(e.data);if(data.content!==undefined)rawContent=String(data.content);render();}catch(err){}es.close();if(stream===es){stream=null;streamMessageId=0;}refresh();});
 es.onerror=()=>{/* EventSource reconnects automatically; generation continues independently on the backend. */};
}
window.LACChatGenerationRecovery={init,startRecoveredStream,stopRecoveredStream,get messageId(){return streamMessageId},get stream(){return stream}};
})();