(()=>{'use strict';
let md=()=>'',post=async()=>{},toast=()=>{},setState=()=>{},startTrace=()=>{},scroll=()=>{},confirmAction=()=>false;
function init(deps={}){md=deps.md||md;post=deps.post||post;toast=deps.toast||toast;setState=deps.setState||setState;startTrace=deps.startTrace||startTrace;scroll=deps.scroll||scroll;confirmAction=deps.confirmAction||confirmAction;}
async function handle(ev,obj,ctx){
 if(ev==='meta'){
  ctx.activeRun=obj.run_id;
  if(obj.message_id){const traceMessage=ctx.assistantBody?.closest('.message');traceMessage?.setAttribute('data-message-id',String(obj.message_id));if(traceMessage)startTrace(traceMessage,Number(obj.message_id));}
  ctx.generationModelLabel=(obj.model_label&&String(obj.model_label).trim())?String(obj.model_label).trim():(ctx.generationModelLabel||ctx.currentModelAlias||'Model');
  setState(ctx.assistantBody,ctx.generationModelLabel,'preparing',true);
  if(ctx.pendingStop)post('stop_generation',{run_id:ctx.activeRun}).catch(()=>{});
 }else if(ev==='clear_delta'){
  ctx.full='';ctx.assistantBody.innerHTML=md(ctx.baseText);setState(ctx.assistantBody,ctx.generationModelLabel,'preparing',true);
 }else if(ev==='delta'){
  setState(ctx.assistantBody,ctx.generationModelLabel,'typing',false);ctx.full+=obj.text;ctx.assistantBody.innerHTML=md(ctx.baseText+ctx.full);scroll();
 }else if(ev==='approval_required'){
  const pending=Array.isArray(obj.pending)?obj.pending:(obj.pending?[obj.pending]:[]),first=pending[0]||{};
  const detail=(first.tool||'action')+' · '+(first.required_approvals||first.risks||[]).join(', ')+' · arguments='+JSON.stringify(first.arguments||{}).slice(0,700);
  const approved=confirmAction('The Agent completed the safe steps. Approval is required before this action: '+detail+'.\n\nApprove this action?');
  if(approved){try{const result=await post('agent_task_approve',{run_id:obj.run_id||ctx.activeRun});toast(result.state==='waiting_approval'?'Approved step executed; another action still requires approval.':'Task resumed.');}catch(e){toast('Could not resume task: '+e.message);}}
  else{try{await post('agent_task_reject',{run_id:obj.run_id||ctx.activeRun});toast('Action rejected; task stopped.');}catch(e){toast('Could not record rejection: '+e.message);}}
 }else if(ev==='tool_start'||ev==='tool_result'||ev==='tool_error'){/* Runtime trace owns tool activity presentation. */}
 else if(ev==='usage')ctx.usage=obj;
 else if(ev==='finish')ctx.finishReason=obj.reason;
 else if(ev==='error')throw Error(obj.message||'Model error');
 else if(ev==='done'){ctx.stopped=!!obj.stopped;ctx.usage=obj.usage||ctx.usage;ctx.finishReason=obj.finish_reason||ctx.finishReason;ctx.assistantMessageId=Number(obj.message_id)||0;}
 return ctx;
}
window.LACChatGenerationEvents={init,handle};
})();