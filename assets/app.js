(()=>{const core=window.LACChatCore,$=core.$,csrf=core.csrf,post=core.post,toast=core.toast;let current=0,activeRun=null,activeMessageId=0,controller=null,lastAssistant=null,generationStarting=false,switchingModel=false;
LACAttachmentUI.init(()=>current,message=>toast(message));
async function refreshConversationAttachments(){return LACAttachmentUI.refresh(()=>current);}
const {typesetMath}=window.LACChatRender;
let activeCitationLinks=new Map();
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const inlineMd=(value)=>{
 let t=esc(value);const code=[];
 t=t.replace(/`([^`]+)`/g,(_,x)=>{code.push('<code>'+x+'</code>');return '\u0000'+(code.length-1)+'\u0000'});
 const chartUrl=(raw)=>{
  let u=String(raw||'').replace(/&amp;/gi,'&').replace(/\\&/g,'&').trim();
  // Repair Markdown emphasis tags that were inserted into the generated URL.
  // Handle both literal HTML and percent-encoded HTML tags.
  u=u.replace(/%3c(?:%2f|\/)?em%3e/gi,(tag)=>decodeURIComponent(tag));
  u=u.replace(/python\s*<\/?em>\s*output/ig,'python_output');
  u=u.replace(/chart\s*<\/?em>\s*(?=[a-f0-9]{32}\.(?:jpg|png))/ig,'chart_');
  u=u.replace(/<\/?em>/gi,'');
  // Some renderers split the two identifiers without preserving the tag.
  u=u.replace(/action=pythonoutput(?=&|$)/i,'action=python_output');
  u=u.replace(/file=chart([a-f0-9]{32}\.(?:jpg|png))/i,'file=chart_$1');
  const action=u.match(/[?&]action=python_output(?:&|$)/i);
  const file=u.match(/[?&]file=(chart_[a-f0-9]{32}\.(?:jpg|png))(?:&|$)/i);
  return action&&file?{url:u,file:file[1]}:null;
 };
 const renderChart=(alt,raw)=>{const c=chartUrl(raw);if(!c)return null;const src=esc(c.url);return '<span class="python-image-wrap" data-python-image-wrap><img alt="'+esc(alt||'Python chart')+'" src="'+src+'" loading="lazy"><button type="button" class="python-image-delete" data-python-image-delete="'+c.file+'" aria-label="Delete chart" title="Delete chart file">×</button></span>';};
 // Parse image/link destinations before emphasis so underscores in query strings
 // can never be interpreted as Markdown italics.
 t=t.replace(/!\[([^\]]*)\]\((https?:\/\/[^\s)]+|\/?[A-Za-z0-9_./?=&;%*\-]+)\)/g,(all,alt,url)=>{
  const chart=renderChart(alt,url);if(chart)return chart;
  return '<img alt="'+alt+'" src="'+url.replace(/&amp;/g,'&')+'" loading="lazy" style="max-width:100%">';
 });
 t=t.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,(all,label,url)=>{
  const chart=renderChart(label,url);if(chart)return chart;
  return '<a href="'+url+'" target="_blank" rel="noopener noreferrer">'+label+'</a>';
 });
 // Render numbered source markers as compact, clickable citation links.
 t=t.replace(/\[(\d{1,3})\]/g,(all,n)=>{const url=activeCitationLinks.get(Number(n));return url?'<sup class="citation-marker"><a href="'+esc(url)+'" target="_blank" rel="noopener noreferrer" aria-label="Open source '+n+'">['+n+']</a></sup>':all});
 // Also recognize a bare Python chart URL (without Markdown link syntax).
 t=t.replace(/https?:\/\/[^\s<>]+/g,(url)=>{const chart=renderChart('Python chart',url.replace(/[.,;]+$/,''));return chart||url;});
 t=t.replace(/\*\*([\s\S]+?)\*\*|__([\s\S]+?)__/g,'<sin>$1$2</sin>').replace(/~~([\s\S]+?)~~/g,'<del>$1</del>').replace(/(^|[^*])\*([^*\n]+)\*($|[^*])/g,'$1<em>$2</em>$3').replace(/(^|[^_])_([^_\n]+)_($|[^_])/g,'$1<em>$2</em>$3');
 t=t.replace(/\u0000(\d+)\u0000/g,(_,i)=>code[Number(i)]);return t
};
const md=(source,nested=false)=>{const codeParts=[];if(!nested)activeCitationLinks=new Map();let rawMarkdown=String(source??'');const sourceLines=[];let inSources=false;rawMarkdown=rawMarkdown.split(/\r?\n/).filter(line=>{if(/^\s{0,3}#{1,4}\s*(?:references|sources|references)\s*:?\s*$/i.test(line)){inSources=true;return false}if(inSources){if(!line.trim())return false;const m=line.match(/^\s*(?:[-*+]\s*)?\[?(\d{1,3})\]?\s*[.)]?\s+(.+?)\s*(?:[—–|:]\s*|\s+)(https?:\/\/\S+)\s*$/i)||line.match(/^\s*(?:[-*+]\s*)?\[?(\d{1,3})\]?\s*[.)]?\s+\[(.+?)\]\((https?:\/\/[^)]+)\)\s*$/i);if(m){const n=Number(m[1]),title=m[2].trim(),url=m[3].replace(/[),.;]+$/,'');if(n>0&&/^https?:\/\//i.test(url)&&!activeCitationLinks.has(n)){activeCitationLinks.set(n,url);sourceLines.push({n,title,url})}return false}if(/^\s*[-*+]\s+/.test(line)||/^\s*\[?\d+\]?/.test(line))return false;inSources=false}return true}).join('\n');let markdown=rawMarkdown.replace(/(^|\n)```([^\n]*)\n([\s\S]*?)\n```[ \t]*(?=\n|$)/g,(all,before,lang,code)=>{const safeLang=(lang.trim()||'none').toLowerCase().replace(/[^a-z0-9_+-]/g,'');const aliases={js:'javascript',ts:'typescript',py:'python',sh:'bash',shell:'bash',html:'markup',xml:'markup',svg:'markup',md:'markdown'};const grammar=aliases[safeLang]||safeLang;const labels={js:'JavaScript',ts:'TypeScript',py:'Python',sh:'Bash',html:'HTML',xml:'XML',sql:'SQL',php:'PHP',json:'JSON',css:'CSS',bash:'Bash',python:'Python',javascript:'JavaScript',typescript:'TypeScript',c:'C',cpp:'C++',java:'Java',markdown:'Markdown',markup:'HTML'};const label=labels[safeLang]||((safeLang==='none'||!safeLang)?'Code':safeLang.toUpperCase());const codeId='code-'+Math.random().toString(36).slice(2,10);const html='<section class=\"code-card\" data-code-card><header class=\"code-card-head\"><span class=\"code-card-lang\">'+esc(label)+'</span><span class=\"code-card-actions\"><button type=\"button\" data-code-copy aria-label=\"Copy code\" title=\"Copy code\">Copy</button><button type=\"button\" data-code-download aria-label=\"Download code\" title=\"Download code\">Download</button><button type=\"button\" data-code-collapse aria-label=\"Collapse code\" title=\"Collapse code\">Collapse</button></span></header><pre class=\"line-numbers\"><code id=\"'+codeId+'\" class=\"language-'+esc(grammar||'none')+'\">'+esc(code)+'</code></pre></section>';const token='\u0001CODE'+codeParts.length+'\u0001';codeParts.push(html);return before+token});const mathParts=[];let prepared=markdown.replace(/\\\[([\s\S]*?)\\\]|\$\$([\s\S]*?)\$\$|\\\(([\s\S]*?)\\\)|(?<!\\)\$([^$\n]+)\$/g,(all,a,b,c,d)=>{const display=a!==undefined||b!==undefined,latex=a??b??c??d??'';const token='@@MATH'+mathParts.length+'@@';mathParts.push(renderMathML(latex,display));return token});const lines=prepared.replace(/\r\n?/g,'\n').split('\n'),out=[];let i=0;const isTable=(n)=>n+1<lines.length&&/^\s*\|?.+\|.+\|?\s*$/.test(lines[n])&&/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(lines[n+1]);const cells=x=>x.trim().replace(/^\|/,'').replace(/\|$/,'').split('|').map(v=>v.trim());while(i<lines.length){let line=lines[i];const codeMarker=line.match(/^\u0001CODE(\d+)\u0001$/);if(codeMarker){out.push(codeParts[Number(codeMarker[1])]||'');i++;continue}if(!line.trim()){i++;continue}if(/^```/.test(line.trim())){let lang=line.trim().slice(3).trim(),buf=[];i++;while(i<lines.length&&!/^```/.test(lines[i].trim()))buf.push(lines[i++]);i++;const codeText=buf.join('\n'),safeLang=(lang||'code').toLowerCase().replace(/[^a-z0-9_+-]/g,'');const label=safeLang==='js'?'JavaScript':safeLang==='ts'?'TypeScript':safeLang.toUpperCase();const codeId='code-'+Math.random().toString(36).slice(2,10);out.push('<section class="code-card" data-code-card><header class="code-card-head"><span class="code-card-lang">'+esc(label)+'</span><span class="code-card-actions"><button type="button" data-code-copy aria-label="Copy code" title="Copy code">Copy</button><button type="button" data-code-download aria-label="Download code" title="Download code">Download</button><button type="button" data-code-collapse aria-label="Collapse code" title="Collapse code">Collapse</button></span></header><pre><code id="'+codeId+'"'+(safeLang?' class="language-'+esc(safeLang)+'"':'')+'>'+esc(codeText)+'</code></pre></section>');continue}if(isTable(i)){let h=cells(lines[i]),align=cells(lines[i+1]);out.push('<div class="md-table-wrap"><table><thead><tr>'+h.map((v,k)=>'<th>'+inlineMd(v)+'</th>').join('')+'</tr></thead><tbody>');i+=2;while(i<lines.length&&lines[i].includes('|')&&lines[i].trim()){out.push('<tr>'+cells(lines[i]).map(v=>'<td>'+inlineMd(v)+'</td>').join('')+'</tr>');i++}out.push('</tbody></table></div>');continue}let hm=line.match(/^(#{1,6})\s+(.+)$/);if(hm){let n=hm[1].length;out.push('<h'+n+'>'+inlineMd(hm[2])+'</h'+n+'>');i++;continue}if(/^\s*(---+|___+|\*\*\*+)\s*$/.test(line)){out.push('<hr>');i++;continue}if(/^\s*>/.test(line)){let q=[];while(i<lines.length&&/^\s*>/.test(lines[i]))q.push(lines[i++].replace(/^\s*>\s?/,''));out.push('<blockquote>'+md(q.join('\n'),true)+'</blockquote>');continue}if(/^\s*([-*+]|\d+[.)])\s+/.test(line)){let ordered=/^\s*\d+[.)]/.test(line),tag=ordered?'ol':'ul',items=[];while(i<lines.length&&/^\s*([-*+]|\d+[.)])\s+/.test(lines[i])){let m=lines[i++].match(/^\s*(?:[-*+]|\d+[.)])\s+(.*)$/);items.push('<li>'+inlineMd(m[1])+'</li>')}out.push('<'+tag+'>'+items.join('')+'</'+tag+'>');continue}let para=[line.trim()];i++;while(i<lines.length&&lines[i].trim()&&!/^(#{1,6}\s|```|\s*>|\s*([-*+]|\d+[.)])\s+)/.test(lines[i])&&!isTable(i)&&! /^\s*(---+|___+|\*\*\*+)\s*$/.test(lines[i]))para.push(lines[i++].trim());out.push('<p>'+para.map(inlineMd).join('<br>')+'</p>')}if(sourceLines.length){out.push('<section class=\"citation-sources\"><h4>References</h4><ol>'+sourceLines.sort((a,b)=>a.n-b.n).map(x=>'<li id=\"citation-source-'+x.n+'\"><a href=\"'+esc(x.url)+'\" target=\"_blank\" rel=\"noopener noreferrer\">'+esc(x.title||x.url)+'</a><span>'+esc(x.url)+'</span></li>').join('')+'</ol></section>')}return out.join('').replace(/@@MATH(\d+)@@/g,(_,n)=>mathParts[Number(n)]||'')};
let currentModelAlias='',generationModelLabel='Model';function modelLabel(){return generationModelLabel||currentModelAlias||'Model'}
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
   const r=await fetch('api.php?action=generation_trace&id='+encodeURIComponent(current)+'&message_id='+encodeURIComponent(messageId)+'&after_sequence='+encodeURIComponent(panel._traceLastSequence),{cache:'no-store'});
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
async function loadHistory(){
 let r=await fetch('api.php?action=conversations'),j=await r.json();
 if(!r.ok)throw Error(j.error||'Could not load history');
 document.querySelectorAll('.history-context-menu').forEach(menu=>menu.remove());
 const host=$('#history');host.innerHTML='';
 const items=j.items||[];
 const dateKey=value=>{const d=new Date(String(value||'').replace(' ','T'));if(Number.isNaN(d.getTime()))return 'unknown';return d.toLocaleDateString('en-CA')};
 const today=dateKey(new Date().toISOString()),yesterday=dateKey(new Date(Date.now()-86400000).toISOString());
 const groups=new Map();
 items.forEach(c=>{const key=dateKey(c.created_at||c.updated_at);if(!groups.has(key))groups.set(key,[]);groups.get(key).push(c)});
 const groupLabel=key=>key==='unknown'?'Earlier':key===today?'Today':key===yesterday?'Yesterday':new Date(key+'T12:00:00').toLocaleDateString(undefined,{month:'long',day:'numeric',year:'numeric'});
 for(const [key,conversations] of groups){
  const section=document.createElement('section');section.className='history-group';
  const heading=document.createElement('div');heading.className='history-group-title';heading.textContent=groupLabel(key);section.append(heading);
  conversations.forEach(c=>{
   const row=document.createElement('div');row.className='history-row'+(Number(c.id)===current?' active':'');
   const open=document.createElement('button');open.type='button';open.className='history-item';open.title=c.title;
   const status=document.createElement('span');status.className='material-icons-round lock-mini'+(Number(c.locked)?' is-locked':'');status.textContent=Number(c.locked)?'lock':'lock_open';status.setAttribute('aria-label',Number(c.locked)?'Password protected':'No password');
   const title=document.createElement('span');title.className='history-title';title.textContent=c.title;
   open.append(status,title);open.onclick=()=>openConversation(Number(c.id),!!Number(c.locked));
   const more=document.createElement('button');more.type='button';more.className='history-more';more.title='More options';more.setAttribute('aria-label','More options');more.innerHTML='<span class="material-icons-round">more_horiz</span>';
   const menu=document.createElement('div');menu.className='history-context-menu';menu.hidden=true;document.body.append(menu);
   const action=(icon,label,fn,extra='')=>{const btn=document.createElement('button');btn.type='button';btn.className='history-context-action '+extra;btn.innerHTML='<span class="material-icons-round">'+icon+'</span><span>'+label+'</span>';btn.onclick=async e=>{e.stopPropagation();menu.hidden=true;try{await fn()}catch(err){toast(err.message)}};menu.append(btn);return btn};
   more.onclick=e=>{e.stopPropagation();document.querySelectorAll('.history-context-menu').forEach(m=>{if(m!==menu)m.hidden=true});menu.hidden=!menu.hidden;if(!menu.hidden){const r=more.getBoundingClientRect();menu.style.position='fixed';menu.style.left=Math.max(8,Math.min(window.innerWidth-213,r.right-205))+'px';menu.style.top=Math.min(window.innerHeight- menu.offsetHeight-8,r.bottom+4)+'px';}};
   action('edit','Rename',async()=>{
    if(c.locked){const pw=prompt('Enter conversation password');if(pw===null)return;await post('conversation_password',{id:c.id,password:pw,operation:'unlock'})}
    const name=prompt('Conversation title',c.title);if(name&&name.trim())await post('rename',{id:c.id,title:name.trim()});await loadHistory();
   });
   action('key','Set / change password',async()=>{
    let current_password='';
    if(c.locked){current_password=prompt('Enter current conversation password');if(current_password===null)return}
    const password=prompt('Set a conversation password (at least 8 characters)');if(password===null)return;
    await post('conversation_password',{id:c.id,password,current_password,operation:'set'});toast('Conversation password saved');await loadHistory();
   });
   if(Number(c.locked))action('lock_open','Remove password',async()=>{
    const password=prompt('Enter current conversation password');if(password===null)return;
    await post('conversation_password',{id:c.id,password,operation:'remove'});toast('Conversation password removed');await loadHistory();
   });
   action('delete','Delete conversation',async()=>{
    if(!confirm('Permanently delete this conversation?'))return;
    if(c.locked){const pw=prompt('Enter the conversation password to confirm deletion');if(pw===null)return;await post('conversation_password',{id:c.id,password:pw,operation:'unlock'})}
    await post('delete',{id:c.id});
    if(current===Number(c.id)){current=0;$('#messages').innerHTML='';$('#chatTitle').textContent='New chat'}
    await loadHistory();
   },'danger');
   row.append(open,more,menu);section.append(row);
  });
  host.append(section);
 }
}
document.addEventListener('click',e=>{if(!e.target.closest('.history-row')&&!e.target.closest('.history-context-menu'))document.querySelectorAll('.history-context-menu').forEach(m=>m.hidden=true)});
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
 if(switchingModel){toast('Switching model. Please wait for llama-server to become ready.');return;}if(controller||generationStarting||recoveredGeneration){toast('This conversation is still generating. Please wait for the model to finish.');return;}
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
const TOOL_PREF_KEY='lac_agent_tools_v1';let agentToolCatalog=[];let enabledAgentTools=new Set(JSON.parse(localStorage.getItem(TOOL_PREF_KEY)||'[]'));
function getEnabledAgentTools(){return [...enabledAgentTools]}
let agentToolsSavePromise=Promise.resolve();function saveAgentTools(){localStorage.setItem(TOOL_PREF_KEY,JSON.stringify(getEnabledAgentTools()));const n=enabledAgentTools.size;$('#enabledToolCount').textContent=String(n);$('#toolWebsites').checked=enabledAgentTools.has('manage_websites');$('#webSearchToggle').checked=enabledAgentTools.has('search_web');const f=new FormData();f.append('_csrf',csrf);f.append('permissions',JSON.stringify(getEnabledAgentTools()));agentToolsSavePromise=fetch('api.php?action=agent_permissions_save',{method:'POST',body:f}).then(async r=>{const j=await r.json();if(!r.ok)throw Error(j.error||'Could not save tool permissions');return j}).catch(e=>{toast(e.message);throw e});}
function renderAgentTools(){const grid=$('#toolPermissionGrid');if(!grid)return;const groups={};agentToolCatalog.forEach(t=>(groups[t.group]??=[]).push(t));grid.innerHTML=Object.entries(groups).map(([group,tools])=>'<section class="tool-permission-group"><h4>'+esc(group)+'</h4>'+tools.map(t=>'<label class="tool-permission-row"><span class="tool-permission-copy"><b>'+esc(t.label)+'</b></span><input type="checkbox" data-agent-tool="'+esc(t.name)+'" '+(enabledAgentTools.has(t.name)?'checked':'')+'></label>').join('')+'</section>').join('');grid.querySelectorAll('[data-agent-tool]').forEach(el=>el.addEventListener('change',()=>{const id=el.dataset.agentTool;if(el.checked)enabledAgentTools.add(id);else enabledAgentTools.delete(id);saveAgentTools();}));saveAgentTools();}
async function loadAgentTools(){try{const [r,p]=await Promise.all([fetch('api.php?action=tool_catalog',{cache:'no-store'}),fetch('api.php?action=agent_permissions',{cache:'no-store'})]);const j=await r.json(),pj=await p.json();if(!r.ok||!p.ok)throw Error(j.error||pj.error||'Could not load tools');agentToolCatalog=j.tools||[];enabledAgentTools=new Set(pj.permissions||[]);localStorage.setItem(TOOL_PREF_KEY,JSON.stringify(getEnabledAgentTools()));renderAgentTools();}catch(e){$('#toolPermissionGrid').textContent=e.message;}}
$('#toolPanelToggle')?.addEventListener('click',()=>{const p=$('#toolPermissionPanel'),open=p.hidden;p.hidden=!open;$('#toolPanelToggle').setAttribute('aria-expanded',String(open));});
$('#toolReset')?.addEventListener('click',()=>{enabledAgentTools.clear();renderAgentTools();});
loadAgentTools();
const updateToolMode=()=>{const el=$('#chatMode');if(el){const n=selectedDocIds.size;el.innerHTML='<span class="mode-label">Chat</span><span class="mode-rag '+(n?'is-on':'')+'"><i></i>RAG'+(n?' · '+n+' files':'')+'</span>';}$('#attachedFileCount').textContent=String(selectedDocIds.size);$('#attachedFileCount').hidden=!selectedDocIds.size;};
const renderFilePicker=()=>{const q=($('#filePickerSearch').value||'').trim().toLocaleLowerCase();const rows=pickerDocuments.filter(d=>String(d.name||'').toLocaleLowerCase().includes(q));$('#filePickerSummary').textContent=stagedDocIds.size+' selected · '+pickerDocuments.length+' documents';$('#filePickerList').innerHTML=rows.map(d=>{const id=Number(d.id),ready=d.status==='completed',checked=stagedDocIds.has(id);return '<label class="file-picker-row '+(ready?'':'is-pending')+'"><input type="checkbox" data-file-id="'+id+'" '+(checked?'checked':'')+' '+(ready?'':'disabled')+'><span class="material-icons-round file-icon">description</span><span class="file-picker-meta"><b>'+esc(d.name)+'</b><small>'+Number(d.chunks||0)+' chunks · '+esc(({queued:'Queued',extracting:'Extracting',chunking:'Chunking',embedding:'Embedding',completed:'Ready',failed:'Error'}[d.status]||d.status||'Unknown'))+'</small></span>'+(ready?'<span class="file-ready">Ready</span>':'<span class="file-pending">Not indexed</span>')+'</label>'}).join('')||'<div class="file-picker-empty">No matching documents found.</div>';$('#filePickerList').querySelectorAll('[data-file-id]').forEach(input=>input.addEventListener('change',()=>{const id=Number(input.dataset.fileId);if(input.checked)stagedDocIds.add(id);else stagedDocIds.delete(id);$('#filePickerSummary').textContent=stagedDocIds.size+' selected · '+pickerDocuments.length+' documents';}));};
const openFilePicker=async()=>{stagedDocIds=new Set(selectedDocIds);const dialog=$('#filePickerDialog');dialog.hidden=false;document.body.classList.add('file-picker-open');$('#filePickerList').innerHTML='<div class="file-picker-empty">Loading library…</div>';try{const r=await fetch('api.php?action=documents',{cache:'no-store'}),j=await r.json();if(!r.ok)throw Error(j.error||'Could not load library');pickerDocuments=(j.items||[]);renderFilePicker();}catch(e){$('#filePickerList').innerHTML='<div class="file-picker-empty">'+esc(e.message)+'</div>';}};
const closeFilePicker=()=>{$('#filePickerDialog').hidden=true;document.body.classList.remove('file-picker-open');updateToolMode();};
$('#addFilesBtn').onclick=openFilePicker;$('#closeFilePicker').onclick=closeFilePicker;$('#filePickerDialog').addEventListener('click',e=>{if(e.target===$('#filePickerDialog'))closeFilePicker()});$('#filePickerSearch').addEventListener('input',renderFilePicker);$('#clearAttachedFiles').onclick=()=>{stagedDocIds.clear();renderFilePicker();};$('#applyAttachedFiles').onclick=()=>{selectedDocIds.clear();stagedDocIds.forEach(id=>selectedDocIds.add(id));closeFilePicker();};
$('#prompt').addEventListener('input',()=>{$('#prompt').style.height='auto';$('#prompt').style.height=Math.min($('#prompt').scrollHeight,180)+'px'});
$('#newChat').onclick=createChat;$('#overlay').onclick=()=>$('#sidebar').classList.remove('open');$('#menu').onclick=()=>$('#sidebar').classList.add('open');$('#closeSide').onclick=()=>$('#sidebar').classList.remove('open');
$('#theme').onclick=()=>{let d=document.documentElement;d.dataset.theme=d.dataset.theme==='dark'?'light':'dark';localStorage.setItem('theme',d.dataset.theme)};document.documentElement.dataset.theme=localStorage.getItem('theme')||'light';


async function getModelAlias(model){try{const r=await fetch('api.php?action=model_alias_get&model='+encodeURIComponent(model),{cache:'no-store'}),j=await r.json();return (j.alias||'').trim()}catch{return ''}}
function defaultModelAlias(model,index){if(/bge[-_]?m3/i.test(model))return 'BGE-M3';return 'Model '+(index+1)}
async function loadChatModels(){const sel=$('#chatModel');if(!sel)return;sel.disabled=true;sel.innerHTML='<option value="">Loading models…</option>';try{const r=await fetch('api.php?action=models&_='+Date.now(),{cache:'no-store',headers:{'Accept':'application/json'}});let j;try{j=await r.json()}catch{throw Error('Model list API returned invalid JSON (HTTP '+r.status+')')}if(!r.ok||j.error)throw Error(j.error||('Could not access model list (HTTP '+r.status+')'));if(!Array.isArray(j.models))throw Error('API did not return a models array');const list=j.models.filter(m=>{const id=String(m?.id||'').trim();if(!id||/(bge[-_]?m3|embedding)/i.test(id))return false;return true});const aliases=await Promise.all(list.map(m=>getModelAlias(m.id)));const saved=localStorage.getItem('chat_model')||'';sel.innerHTML='';list.forEach((m,i)=>{const o=document.createElement('option');o.value=m.id;const alias=(aliases[i]||'').trim();o.textContent=(!alias||alias===m.id)?defaultModelAlias(m.id,i):alias;o.title='Full Name: '+m.id;o.dataset.fullName=m.id;o.dataset.alias=o.textContent;sel.append(o)});if(saved&&list.some(m=>m.id===saved))sel.value=saved;else if(list.some(m=>m.id===window.APP?.defaultModel))sel.value=window.APP.defaultModel;else if(list.length)sel.selectedIndex=0;sel.disabled=!list.length;if(!list.length){sel.innerHTML='<option value="">No chat model found on Llama Server</option>';sel.title='Check /v1/models and make sure a chat model is loaded.';return}sel.dataset.activeModel=(list.find(m=>m.source==='server')?.id)||sel.value;await updateChatModelAlias();}catch(e){console.error('loadChatModels:',e);const msg=String(e.message||e).replace(/[<>]/g,'');sel.innerHTML='<option value="">Model loading error: '+msg+'</option>';sel.title=msg;sel.disabled=true;toast('Could not load model: '+msg);}}
async function updateChatModelAlias(){const sel=$('#chatModel'),model=sel?.value||'';if(!model)return;localStorage.setItem('chat_model',model);const aliasEl=$('#chatModelAlias'),option=sel?.selectedOptions?.[0];currentModelAlias=(option?.dataset?.alias||option?.textContent||'Model').trim()||'Model';if(aliasEl){aliasEl.textContent=currentModelAlias;aliasEl.title='Full Name: '+model;}try{const alias=(await getModelAlias(model)).trim();if(alias){currentModelAlias=alias;if(option){option.dataset.alias=alias;option.textContent=alias;}if(aliasEl)aliasEl.textContent=alias;}}catch{/* retain the option label instead of blanking the alias */}}
async function switchChatModel(){const sel=$('#chatModel'),model=sel?.value||'',previous=sel?.dataset.activeModel||'';if(!model||model===previous)return;if(controller||generationStarting||recoveredGeneration){sel.value=previous;toast('Cannot switch models while a response is being generated.');return;}switchingModel=true;sel.disabled=true;toast('Requesting Manager to load the new model…',0);try{const result=await post('chat_model_switch',{model});if(result.already_active){sel.dataset.activeModel=model;localStorage.setItem('chat_model',model);await updateChatModelAlias();toast(result.message||'The model is already active; the default configuration is synchronized.',4000);return;}const tx=result.transaction_id;if(!tx)throw Error('Manager did not return a transaction ID.');let finalState=null;for(let i=0;i<330;i++){await new Promise(r=>setTimeout(r,2000));const state=await post('model_switch_status',{id:tx});if(state.state&&state.state!=='pending'){finalState=state;break;}if(i%5===0)toast('Loading and verifying the new model… '+Math.floor((i+1)*2/60)+' min',0);}if(!finalState)throw Error('Timed out. The transaction may still be running; check Manager before retrying.');if(finalState.state==='rolled_back')throw Error(finalState.message||'Loading the new model failed; the previous model was restored.');if(finalState.state==='rollback_failed')throw Error(finalState.message||'Both the new model and rollback failed. Check the Manager log.');if(finalState.state!=='committed')throw Error(finalState.message||'The transaction ended with an unknown state.');sel.dataset.activeModel=model;localStorage.setItem('chat_model',model);await updateChatModelAlias();toast('Model switched and verified successfully.',4500);}catch(e){sel.value=previous;toast('Model switch failed: '+e.message,6500);}finally{switchingModel=false;sel.disabled=false;}}
async function init(){try{await loadHistory();let j=await(await fetch('api.php?action=status')).json();$('#status').classList.toggle('offline',!j.online);$('#status').innerHTML='<i></i> '+(j.online?'Online':'Offline');}catch(e){toast(e.message)}}$('#chatModel')?.addEventListener('change',switchChatModel);loadChatModels().then(()=>{const sel=$('#chatModel');if(sel?.value&&sel.dataset.activeModel&&sel.value!==sel.dataset.activeModel)switchChatModel();});init();
})();document.addEventListener('gesturestart',e=>e.preventDefault(),{passive:false});document.addEventListener('gesturechange',e=>e.preventDefault(),{passive:false});document.addEventListener('gestureend',e=>e.preventDefault(),{passive:false});document.addEventListener('touchmove',e=>{if(e.touches&&e.touches.length>1)e.preventDefault()},{passive:false});
