(()=>{
'use strict';
function formatHMS(ms){let s=Math.floor(ms/1000),h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sec=s%60;return [h,m,sec].map(v=>String(v).padStart(2,'0')).join(':')}

function traceDuration(ms){const n=Math.max(0,Number(ms)||0);return n<1000?n.toFixed(3)+' ms':(n/1000).toFixed(3)+' s'}
function traceStamp(ev){
 const d=new Date(ev.timestamp||ev.at||Date.now());
 return [d.getHours(),d.getMinutes(),d.getSeconds()].map(x=>String(x).padStart(2,'0')).join(':')+'.'+String(d.getMilliseconds()).padStart(3,'0');
}
const TRACE_EVENT_LABELS={
 meta:'Chat session initialized',trace_configuration:'Trace configuration loaded',trace_stage:'Preparing execution',
 model_request:'Preparing model request',model_request_started:'Sending request to model',
 model_first_byte:'Model started responding',model_first_token:'First token received',
 model_request_completed:'Model response completed',model_request_failed:'Model request failed',
 generation_requested:'Response generation started',generation_context:'Preparing context',
 agent_started:'Agent started',agent_state:'Agent state',agent_model_round:'Model reasoning round',
 agent_tool_selection:'Tool selection',agent_tool_execution:'Tool execution',
 agent_tool_execution_error:'Tool execution error',agent_completed:'Agent completed',
 tool_start:'Tool started',tool_result:'Tool result received',tool_error:'Tool error',
 finish:'Generation finished',error:'Execution error'
};
function traceDuration(ms){const n=Math.max(0,Number(ms)||0);return n<1000?n.toFixed(1)+' ms':(n/1000).toFixed(2)+' s'}
function traceStamp(ev){
 const d=new Date(ev.timestamp||ev.at||Date.now());
 return [d.getHours(),d.getMinutes(),d.getSeconds()].map(x=>String(x).padStart(2,'0')).join(':')+'.'+String(d.getMilliseconds()).padStart(3,'0');
}
function traceLabel(ev){
 const raw=String(ev.event||ev.event_name||ev.name||ev.type||ev.operation||'trace_event');
 return TRACE_EVENT_LABELS[raw]||raw.replace(/_/g,' ').replace(/\b[a-z]/g,c=>c.toUpperCase());
}
function traceSourceLabel(ev){
 const event=String(ev.event||'').toLowerCase(), source=String(ev.source||'').toLowerCase();
 if(event==='meta')return 'SYSTEM';
 if(event==='trace_configuration'||event==='trace_stage')return 'PREPARE';
 if(event.startsWith('model_')||source==='model')return 'LLAMA';
 if(event.startsWith('agent_')||source==='agent')return 'AGENT';
 if(event.startsWith('tool_')||source==='tool')return 'TOOL';
 if(event.startsWith('generation_'))return 'GENERATION';
 if(event.includes('stop')||event.includes('cancel'))return 'STOP';
 return 'SYSTEM';
}
function traceLineText(ev){
 const event=String(ev.event||ev.event_name||'').toLowerCase();
 const data=ev.data&&typeof ev.data==='object'?ev.data:{};
 let message=traceLabel(ev);
 if(event==='meta')message='Process attached';
 else if(event==='trace_configuration')message='Trace configuration loaded';
 else if(event==='trace_stage')message=String(data.label||data.detail||'Preparing execution');
 else if(event==='model_request_started')message='REQUEST START';
 else if(event==='model_first_byte')message='FIRST BYTE';
 else if(event==='model_first_token')message='FIRST TOKEN';
 else if(event==='model_request_completed')message='REQUEST DONE';
 else if(event==='agent_state')message='state='+String(data.state||'unknown');
 const preferred=['message_id','run_id','request_id','round','state','action','mode','model','model_id','endpoint_host','endpoint','message_count','prompt_chars','max_tokens','timeout_seconds','ttfb_ms','ttft_ms','http_status','http_code','connect_ms','download_bytes','response_bytes','duration_ms','tool','tool_name','tool_call_id','reason','error_code'];
 const details=[];
 for(const key of preferred){
  const value=data[key];
  if(value===undefined||value===null||value===''||typeof value==='object')continue;
  let val=String(value).replace(/[\\r\\n\\t]+/g,' ').trim();
  if(val.length>72)val=val.slice(0,69)+'…';
  details.push(key+'='+val);
 }
 if(!details.length&&data.detail&&event!=='trace_stage')details.push(String(data.detail).replace(/[\\r\\n\\t]+/g,' ').slice(0,120));
 return '['+traceStamp(ev)+'] ['+traceSourceLabel(ev)+'] '+message+(details.length?' · '+details.join(' · '):'');
}

window.LACChatTraceUtils={formatHMS,traceDuration,traceStamp,traceLabel,traceSourceLabel,traceLineText};
})();
