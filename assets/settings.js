(()=>{'use strict';
const $=s=>document.querySelector(s),csrf=window.APP?.csrf||'';
// Settings are split into focused child pages while retaining one URL and the existing design system.
const settingsPages=['general','models','infrastructure','system','websites'];
function showSettingsPage(name){if(name==='prompt'||name==='language')name='general';if(!settingsPages.includes(name))name='general';document.querySelectorAll('[data-settings-page]').forEach(b=>b.classList.toggle('active',b.dataset.settingsPage===name));document.querySelectorAll('[data-settings-panel]').forEach(p=>p.classList.toggle('active',p.dataset.settingsPanel===name));history.replaceState(null,'','#'+name);}
document.querySelectorAll('[data-settings-page]').forEach(b=>b.addEventListener('click',()=>showSettingsPage(b.dataset.settingsPage)));
showSettingsPage(location.hash.replace('#','')||'general');

document.documentElement.dataset.theme=localStorage.getItem('theme')||'light';
function toast(message){const el=$('#toast');if(!el)return;el.textContent=message;el.classList.add('show');setTimeout(()=>el.classList.remove('show'),2600)}
async function post(action,data={}){const f=new FormData();f.append('_csrf',csrf);Object.entries(data).forEach(([k,v])=>f.append(k,v));const r=await fetch('api.php?action='+encodeURIComponent(action),{method:'POST',body:f});const j=await r.json();if(!r.ok)throw Error(j.error||'Request failed');return j}
function escapeHtml(value){return String(value??'').replace(/[&<>\"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[ch]))}
function modelFallbackLabel(index){return 'Model '+(index+1)}
function modelShortName(id){const value=String(id||'').replace(/\\/g,'/');return value.split('/').pop()||value}
let discoveredModels=[];
function renderModelMetadata(){const model=$('#model')?.value,status=$('#modelMetadataStatus'),details=$('#modelMetadataDetails');if(!status||!details)return;const item=discoveredModels.find(m=>m.id===model);if(!item){status.textContent='No model selected.';details.innerHTML='<span class="hint">Select a local GGUF model to view its metadata.</span>';return}if(item.metadata_status==='error'){status.textContent='Metadata could not be read';details.innerHTML='<span class="hint">'+escapeHtml(item.metadata_error||'The GGUF metadata is invalid or unsupported.')+'</span>';return}const m=item.metadata;if(!m){status.textContent=item.source==='server'?'Runtime-reported model; no local GGUF metadata is available.':'Metadata is not available for this model.';details.innerHTML='<span class="hint">'+escapeHtml(status.textContent)+'</span>';return}status.textContent='Metadata read from the local GGUF file. No model weights were loaded.';const rows=[['Architecture',m.architecture],['Parameters',m.parameter_count?Number(m.parameter_count).toLocaleString():'Not declared'],['Quantization',m.quantization_label??m.file_type??'Not declared'],['File size',m.file_size_bytes?((m.file_size_bytes/1073741824).toFixed(2)+' GB'):'Not available'],['Context length',m.context_length?Number(m.context_length).toLocaleString()+' tokens':'Not declared'],['Transformer layers',m.block_count??'Not declared'],['Embedding length',m.embedding_length??'Not declared'],['Tokenizer',m.tokenizer_model??'Not declared'],['Chat template',m.chat_template_present?'Embedded':'Not found'],['Tensor count',m.tensor_count??'Not available'],['GGUF version',m.gguf_version??'Unknown']];details.innerHTML=rows.map(([k,v])=>'<div class="model-metadata-item"><span>'+escapeHtml(k)+'</span><strong>'+escapeHtml(String(v??'Not declared'))+'</strong></div>').join('')}
async function models(){try{const r=await fetch('api.php?action=models',{cache:'no-store'}),j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Could not retrieve model list');const s=$('#model'),old=s.value,list=j.models.filter(m=>!/(bge[-_]?m3|embedding)/i.test(m.id));discoveredModels=list;const aliases=await Promise.all(list.map(async m=>{try{const a=await(await fetch('api.php?action=model_alias_get&model='+encodeURIComponent(m.id),{cache:'no-store'})).json();return (a.alias||'').trim()}catch{return ''}}));s.innerHTML='';list.forEach((m,i)=>{const o=document.createElement('option');o.value=m.id;const alias=aliases[i];o.textContent=modelShortName(m.id);o.title='Model: '+modelShortName(m.id)+' | ID: '+m.id+(alias?' | Alias: '+alias:'');o.dataset.fullName=modelShortName(m.id);o.dataset.alias=alias;s.append(o)});if(old&&list.some(m=>m.id===old))s.value=old;else if(list.length&&list.some(m=>m.id===window.APP?.defaultModel))s.value=window.APP.defaultModel;await loadAlias();renderModelMetadata()}catch(e){const status=$('#modelMetadataStatus');if(status)status.textContent='Automatic model discovery failed: '+e.message;toast(e.message)}}
async function loadAlias(){const model=$('#model').value,select=$('#model'),option=select?.selectedOptions?.[0],aliasInput=$('#model_alias');if(!model){if(aliasInput)aliasInput.value='';return}try{const r=await fetch('api.php?action=model_config_get&model='+encodeURIComponent(model),{cache:'no-store'}),j=await r.json();if(!r.ok||j.error)throw Error(j.error||'Could not load model configuration');if(aliasInput)aliasInput.value=(j.alias||'').trim();const p=j.generation_params||{};['temperature','top_p','max_tokens','timeout','context_size','gpu_layers'].forEach(k=>{const el=$('#'+k);if(el&&p[k]!==undefined&&p[k]!==null)el.value=String(p[k])});if(option){option.dataset.alias=(j.alias||'').trim();option.title='Model: '+modelShortName(model)+' | ID: '+model+(j.alias?' | Alias: '+j.alias:'')}}catch(e){if(aliasInput)aliasInput.value=''}}
$('#model').addEventListener('change',()=>{loadAlias();renderModelMetadata()});
const systemPrompt=$('#system_prompt'),systemPromptCount=$('#systemPromptCount'),systemPromptStatus=$('#systemPromptStatus');
function updateSystemPromptCount(){if(systemPromptCount)systemPromptCount.textContent=systemPrompt.value.length.toLocaleString('en-US')+' / 30,000';}
systemPrompt?.addEventListener('input',()=>{updateSystemPromptCount();if(systemPromptStatus)systemPromptStatus.textContent='Unsaved changes';});
updateSystemPromptCount();
$('#clearSystemPrompt')?.addEventListener('click',()=>{if(!systemPrompt)return;systemPrompt.value='';systemPrompt.dispatchEvent(new Event('input',{bubbles:true}));systemPrompt.focus();});
$('#saveSystemPrompt')?.addEventListener('click',async()=>{const b=$('#saveSystemPrompt');b.disabled=true;if(systemPromptStatus)systemPromptStatus.textContent='Saving…';try{await post('settings_save',{system_prompt:systemPrompt.value});if(systemPromptStatus)systemPromptStatus.textContent='All changes saved';toast('System Prompt saved.')}catch(e){if(systemPromptStatus)systemPromptStatus.textContent=e.message;toast(e.message)}finally{b.disabled=false}});
$('#saveSettings').onclick=async()=>{const b=$('#saveSettings'),status=$('#saveResult');b.disabled=true;if(status)status.textContent='Saving configuration…';try{const model=$('#model').value;if(!model)throw Error('Select a model before saving.');const params={};['temperature','top_p','max_tokens','timeout','context_size','gpu_layers'].forEach(k=>params[k]=$('#'+k).value);const configSave=await post('model_config_save',{model,alias:$('#model_alias').value,generation_params:JSON.stringify(params)});const r=await post('settings_save',{model});const tx=r.transaction_id||configSave.transaction_id;if(tx){toast('Loading the new model; the current default remains active until verification succeeds.');if(status)status.textContent='Loading the new model; the current default remains active.';let result=null;for(let i=0;i<330;i++){await new Promise(resolve=>setTimeout(resolve,2000));const state=await post('model_switch_status',{id:tx});if(state.state&&state.state!=='pending'){result=state;break;}if(status&&i%5===0)status.textContent='Loading and verifying the new model… '+Math.floor((i+1)*2/60)+' min';}if(!result)throw Error('Timed out. The transaction may still be running; check Manager before retrying.');if(result.state==='rolled_back')throw Error(result.message||'Model loading failed; the previous model was restored.');if(result.state==='rollback_failed')throw Error(result.message||'Could not restore the previous model. Check the Manager log.');if(result.state!=='committed')throw Error(result.message||'The transaction ended with an unknown state.');if(status)status.textContent='Default Model saved and the new model was verified.';toast('Default Model saved and the new model was verified.');refreshInfra();}else{toast(configSave.warning||r.message||r.warning||'Model settings saved');if(configSave.restart_queued)refreshInfra()}}catch(e){if(status)status.textContent=e.message;toast(e.message)}finally{b.disabled=false}};
$('#saveInfrastructureSettings')?.addEventListener('click',async()=>{const b=$('#saveInfrastructureSettings');b.disabled=true;try{const r=await post('infrastructure_settings_save',{endpoint:$('#endpoint').value,models_endpoint:$('#models_endpoint').value,model_dir:$('#model_dir').value});$('#infrastructureSaveResult').textContent=r.message||'Infrastructure settings saved';toast(r.message||'Infrastructure settings saved')}catch(e){$('#infrastructureSaveResult').textContent=e.message;toast(e.message)}finally{b.disabled=false}});
document.querySelectorAll('[data-server]').forEach(b=>b.onclick=async()=>{b.disabled=true;try{const j=await post('server_control',{command:b.dataset.server});$('#serverResult').textContent=j.message;toast(j.message)}catch(e){toast(e.message)}finally{b.disabled=false}});
const serviceOrder=['qdrant','bge','qwen','ragworker'];
// Core services define the master switch. Qdrant/BGE are optional, demand-driven RAG services.
const coreServiceKeys=['qwen','ragworker'];
const serviceNames={qdrant:'Qdrant · Vector Database',bge:'Llama Server · BGE-M3 Embedding',qwen:'Llama Server · Chat Model',ragworker:'RAG Worker'};
let infraBusy=false,infraSnapshot=null,infraRefreshBusy=false;
function renderInfra(j){
 infraSnapshot=j;
 const services=j.services||{};
 const keys=serviceOrder.filter(k=>services[k]);
 const requiredKeys=coreServiceKeys.filter(k=>services[k]);
 const allHealthy=requiredKeys.length===coreServiceKeys.length&&requiredKeys.every(k=>services[k].running&&services[k].healthy);
 const managerRunning=keys.some(k=>services[k].running&&services[k].owner==='manager');
 const anyRunning=keys.some(k=>services[k].running);
 const externalKeys=keys.filter(k=>services[k].running&&services[k].owner==='external');
 // The master switch represents processes controlled by this Manager, not
 // unrelated XAMPP/application processes that the Manager is forbidden to stop.
 $('#infraToggle').checked=managerRunning&&allHealthy;
 $('#infraToggle').disabled=infraBusy||!j.installed||!j.manager_online;
 $('#infraStateDot').className='infra-state-dot '+(allHealthy?'is-on':anyRunning?'is-partial':'is-off');
 $('#infraStateLabel').textContent=infraBusy?'Executing infrastructure command…':
   !j.manager_online?'Manager OFFLINE · Live service states detected from Windows':
   managerRunning&&allHealthy?'All services are ready':
   managerRunning?'Manager-managed services are not ready':
   externalKeys.length?'Manager is off; '+externalKeys.length+' external services are still running':
   'Manager-managed infrastructure is off';$('#infraServices').innerHTML=keys.map(k=>{const v=services[k],ok=!!v.running&&!!v.healthy;const isLlama=(k==='qwen'||k==='bge');const state=isLlama?(!v.running?'OFF · Stopped':v.healthy?'ON · Ready':'ON · Running, not healthy'):(!v.running?'Stopped':v.healthy?'Ready':'Running · Unhealthy');const owner=v.owner==='external'?'External to Manager':v.owner==='manager'?'Managed by Manager':'Unknown';return `<div class="infra-service"><span class="infra-service-led ${ok?'is-ok':v.running?'is-warn':'is-off'}"></span><div class="infra-service-copy"><sin>${serviceNames[k]||v.name||k}</sin><small>${state} · ${owner}${v.pid?' · PID '+v.pid:''}</small></div><span class="infra-service-port">${v.port?' :'+v.port:''}</span></div>`}).join('')||'<div class="infra-service muted">Could not retrieve the service list.</div>';const external=keys.filter(k=>services[k].owner==='external'&&services[k].running).length;$('#infraResult').textContent=(j.message||'')+(external?` Still ${external} services are managed by another program; they remain running and will not be stopped by the Manager.`:'');
 const bge=services.bge;const bgeButton=$('#toggleBge');
 if(bgeButton){bgeButton.disabled=infraBusy||!j.installed;bgeButton.textContent=bge&&bge.running?'Stop BGE-M3 to free RAM':'Start BGE-M3 (embeddings/RAG)';}
 const bgeResult=$('#bgeControlResult');if(bgeResult&&bge){bgeResult.textContent=bge.running?(bge.healthy?'BGE-M3 is running on port 8081.':'BGE-M3 has a running process but has not passed its health check.'):'BGE-M3 is stopped and will start automatically when RAG needs embeddings. It stops automatically after 300 seconds of inactivity.';}
}
async function refreshInfra(){
 if(infraRefreshBusy)return infraSnapshot;
 infraRefreshBusy=true;
 try{
  const r=await fetch('api.php?action=infrastructure_status',{cache:'no-store'});
  const j=await r.json();
  if(!r.ok)throw Error(j.error||'Could not read status');
  if(!j.installed){
   $('#infraToggle').disabled=true;
   $('#infraStateLabel').textContent='Unified Manager is not installed';
   $('#infraServices').innerHTML='<div class="infra-service muted">'+(j.message||'Manager not found')+'</div>';
   $('#infraResult').textContent='';
   return j;
  }
  renderInfra(j);return j;
 }catch(e){
  $('#infraStateLabel').textContent='Could not read status';
  $('#infraResult').textContent=e.message;return null;
 }finally{infraRefreshBusy=false}
}
const infraLogSeen=new Map();
function openInfraProgress(turnOn){
 const modal=$('#infraProgressModal'),log=$('#infraProgressLog');
 log.innerHTML='';infraLogSeen.clear();
 $('#infraProgressTitle').textContent=turnOn?'Starting Local AI infrastructure':'Stopping Local AI infrastructure';
 $('#infraProgressSubtitle').textContent=turnOn?'The Manager starts Qwen and the RAG Worker. Qdrant/BGE-M3 are on-demand services and are not required for completion.':'The Manager stops core services. Any RAG stack with an active lease remains available until its task completes.';
 $('#infraProgressSpinner').classList.remove('is-done','is-error');
 $('#infraProgressBar').style.width='8%';
 $('#infraProgressStatus').textContent='Sending request…';
 $('#infraProgressClose').disabled=true;modal.hidden=false;document.body.classList.add('infra-progress-open');
 addInfraLog('info',turnOn?'Infrastructure start requested.':'Infrastructure stop requested.');
 addInfraLog('info',turnOn?'Connecting to Unified Manager to start services.':'Connecting to Unified Manager to stop services.');
}
function addInfraLog(type,message){
 const log=$('#infraProgressLog');if(!log)return;
 const empty=log.querySelector('.infra-log-empty');if(empty)empty.remove();
 const row=document.createElement('div');row.className='infra-log-row '+(type||'info');
 const time=document.createElement('time');time.textContent=new Date().toLocaleTimeString('en-US',{hour12:false});
 const mark=document.createElement('span');mark.className='infra-log-mark';mark.textContent=type==='success'?'✓':type==='error'?'!':'›';
 const text=document.createElement('span');text.textContent=message;
 row.append(time,mark,text);log.append(row);log.scrollTop=log.scrollHeight;
}
function logInfraSnapshot(j){
 const services=j?.services||{};
 serviceOrder.forEach(k=>{
  const v=services[k];if(!v)return;
  const state=!v.running?'OFF':v.healthy?'ON · Healthy':'ON · Not healthy';
  const signature=state+'|'+(v.owner||'')+'|'+(v.pid||'');
  if(infraLogSeen.get(k)===signature)return;
  infraLogSeen.set(k,signature);
  const label=serviceNames[k]||k;
  const kind=!v.running?'info':v.healthy?'success':'info';
  addInfraLog(kind,`${label}: ${state}${v.pid?' (PID '+v.pid+')':''}.`);
 });
}
function finishInfraProgress(kind,message){
 $('#infraProgressSpinner').classList.add(kind==='error'?'is-error':'is-done');
 $('#infraProgressBar').style.width=kind==='error'?'100%':'100%';
 $('#infraProgressStatus').textContent=message;
 $('#infraProgressClose').disabled=false;
 $('#infraProgressClose').focus();
}
$('#infraProgressClose').addEventListener('click',()=>{
 if(infraBusy)return;
 $('#infraProgressModal').hidden=true;document.body.classList.remove('infra-progress-open');
});
async function waitForInfraState(turnOn,timeoutMs=120000,baselineTimestamp=0){
 const started=Date.now();
 while(Date.now()-started<timeoutMs){
  const j=await refreshInfra();
  if(j){logInfraSnapshot(j);$('#infraProgressBar').style.width='65%';$('#infraProgressStatus').textContent='Verifying actual service status…'}
  const statusTimestamp=Date.parse(j?.updated_at||'')||0;
  const services=j?.services||{};
  const keys=serviceOrder.filter(k=>services[k]);
  const managerRunning=keys.some(k=>services[k].running&&services[k].owner==='manager');
  const requiredKeys=coreServiceKeys.filter(k=>services[k]);
 const allHealthy=requiredKeys.length===coreServiceKeys.length&&requiredKeys.every(k=>services[k].running&&services[k].healthy);
  const llamaStillRunning=['qwen','bge'].some(k=>services[k]?.running);
  if(statusTimestamp>baselineTimestamp&&(turnOn?allHealthy:(!managerRunning&&!llamaStillRunning)))return j;
  await new Promise(resolve=>setTimeout(resolve,2500));
 }
 return await refreshInfra();
}
$('#infraToggle').addEventListener('change',async()=>{
 if(infraBusy)return;
 const turnOn=$('#infraToggle').checked;
 const question=turnOn
  ?'Start core Local AI infrastructure (Qwen and RAG Worker)? Qdrant/BGE-M3 start only when a RAG task requires them.'
  :'Stop core Local AI infrastructure? Qwen will stop; the RAG stack will stop only when no tasks are using it.';
 if(!confirm(question)){renderInfra(infraSnapshot||{});return}
 infraBusy=true;openInfraProgress(turnOn);
 $('#infraToggle').disabled=true;
 $('#infraStateLabel').textContent=turnOn?'Starting services…':'Stopping services…';
 $('#infraResult').textContent='In progress. See the operation log in the status window.';
 try{
  const baselineTimestamp=Date.parse(infraSnapshot?.updated_at||'')||0;
  $('#infraProgressBar').style.width='20%';
  const j=await post('infrastructure_control',{command:turnOn?'start-all':'stop-all'});
  addInfraLog('success',j.message||'Unified Manager has accepted the command.');
  addInfraLog('info',turnOn?'Waiting for services to start and become healthy.':'Waiting for processes to stop; Llama Server on ports 8080 and 8081 will be checked again.');
  $('#infraProgressBar').style.width='38%';
  const finalState=await waitForInfraState(turnOn,120000,baselineTimestamp);
  if(!finalState)throw Error('Could not read the completion status from Manager.');
  logInfraSnapshot(finalState);
  const services=finalState.services||{};
  const keys=serviceOrder.filter(k=>services[k]);
  const managerRunning=keys.some(k=>services[k].running&&services[k].owner==='manager');
  const llamaStillRunning=['qwen','bge'].some(k=>services[k]?.running);
  const requiredKeys=coreServiceKeys.filter(k=>services[k]);
 const allHealthy=requiredKeys.length===coreServiceKeys.length&&requiredKeys.every(k=>services[k].running&&services[k].healthy);
  if(turnOn&&!allHealthy){
   const message='Manager did not start all services. Check each service status and manager.log.';
   $('#infraResult').textContent=message;addInfraLog('error',message);finishInfraProgress('error','Startup did not complete.');
  }else if(!turnOn&&(managerRunning||llamaStillRunning)){
   const message='Shutdown is incomplete: a Manager or Llama Server process is still running on port 8080/8081. Check the PID and manager.log.';
   $('#infraResult').textContent=message;addInfraLog('error',message);finishInfraProgress('error','Some processes are still running.');
  }else{
   const message=turnOn?'Local AI services are confirmed ready.':'Manager and Qwen/BGE Llama Server processes on ports 8080/8081 are confirmed stopped.';
   $('#infraResult').textContent=message;addInfraLog('success',message);finishInfraProgress('success',turnOn?'Startup complete.':'Local AI infrastructure stopped.');
  }
  toast(turnOn?(allHealthy?'Infrastructure is ready':'Startup did not complete'):((managerRunning||llamaStillRunning)?'Not all services have stopped':'Local AI and Llama Server stopped'));
 }catch(e){
  $('#infraResult').textContent=e.message;addInfraLog('error','Error: '+e.message);finishInfraProgress('error','Could not verify completion.');toast(e.message);
 }finally{
  infraBusy=false;
  await refreshInfra();
 }
});
$('#refreshInfra').onclick=refreshInfra;
$('#toggleBge')?.addEventListener('click',async()=>{
 const b=$('#toggleBge');const running=!!infraSnapshot?.services?.bge?.running;b.disabled=true;
 try{const j=await post('bge_control',{command:running?'stop':'start'});$('#bgeControlResult').textContent=j.message||'BGE-M3 command sent.';toast(j.message||'BGE-M3 command sent.');setTimeout(refreshInfra,1800);}
 catch(e){$('#bgeControlResult').textContent=e.message;toast(e.message);}
 finally{b.disabled=false;}
});
refreshInfra();setInterval(refreshInfra,10000);
const escHtml=v=>String(v??'').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c]));
const fmtBytes=v=>{if(v===null||v===undefined||!Number.isFinite(Number(v)))return 'Unknown';let n=Number(v),u=['B','KB','MB','GB','TB'],i=0;while(n>=1024&&i<u.length-1){n/=1024;i++}return (i===0?Math.round(n):n.toFixed(2))+' '+u[i]};
const infoCard=(label,value,sub='')=>`<div class="host-metric"><span>${escHtml(label)}</span><sin>${escHtml(value??'Unknown')}</sin>${sub?`<small>${escHtml(sub)}</small>`:''}</div>`;
const renderHost=j=>{const os=j.os||{},cpu=j.cpu||{},mem=j.memory||{};let html=`<div class="host-overview"><div><span>Server</span><sin>${escHtml(j.hostname||'Unknown')}</sin><small>${escHtml(j.local_ip?'Private IP: '+j.local_ip:'Private IP: Unknown')}</small></div><div><span>Operating system</span><sin>${escHtml([os.name,os.release,os.build?'Build '+os.build:''].filter(Boolean).join(' · ')||'Unknown')}</sin><small>${escHtml((os.architecture||'')+' '+(os.family||''))}</small></div></div><div class="host-grid">${infoCard('CPU',cpu.name||'Unknown',`${cpu.physical_cores??'?'} physical cores · ${cpu.logical_processors??'?'} threads${cpu.max_clock_mhz?' · '+cpu.max_clock_mhz+' MHz':''}`)}${infoCard('Total RAM',fmtBytes(mem.total_bytes),`Used ${fmtBytes(mem.used_bytes)} · Available ${fmtBytes(mem.available_bytes)}`)}${infoCard('Uptime',j.uptime_seconds===null?'Unknown':Math.floor(j.uptime_seconds/86400)+' days '+Math.floor(j.uptime_seconds%86400/3600)+' hours')}</div>`;
html+='<section class="host-section"><h4>GPU / VRAM</h4>'+(j.gpus?.length?j.gpus.map(g=>`<div class="host-detail"><sin>${escHtml(g.name||'Unknown GPU')}</sin><span>${escHtml((g.vendor||'')+(g.driver?' · Driver '+g.driver:''))}</span><small>Total VRAM: ${fmtBytes(g.vram_total_bytes)}${g.vram_used_bytes!==undefined?' · Used: '+fmtBytes(g.vram_used_bytes)+' · Available: '+fmtBytes(g.vram_free_bytes):''}${g.note?' · '+escHtml(g.note):''}</small></div>`).join(''):'<p class="hint">No GPU detected or GPU information is unavailable.</p>')+'</section>';
html+='<section class="host-section"><h4>Drives</h4>'+(j.disks?.length?j.disks.map(d=>`<div class="host-detail"><sin>${escHtml(d.name||'Drives')}</sin><span>${escHtml(d.filesystem||'')}</span><small>Total: ${fmtBytes(d.total_bytes)} · Used: ${fmtBytes(d.used_bytes)} · Available: ${fmtBytes(d.free_bytes)}</small></div>`).join(''):'<p class="hint">Could not read drive information.</p>')+'</section>';
if(j.collection_warning)html+=`<p class="hint">${escHtml(j.collection_warning)}</p>`;html+=`<p class="host-footnote">Source: operating system · Collected at ${escHtml(j.collected_at||'—')}. ${escHtml(j.note||'')}</p>`;$('#serverInfo').innerHTML=html;};
$('#refreshServerInfo').onclick=async()=>{const b=$('#refreshServerInfo');b.disabled=true;$('#serverInfoStatus').textContent='Reading operating system information…';try{const r=await fetch('api.php?action=server_info',{cache:'no-store'}),j=await r.json();if(!r.ok||!j.ok)throw Error(j.error||'Could not read server information');renderHost(j);$('#serverInfoStatus').textContent='Updated from operating system'}catch(e){$('#serverInfoStatus').textContent=e.message;$('#serverInfo').innerHTML=`<p class="hint">${escHtml(e.message)}</p>`}finally{b.disabled=false}};
async function refreshLoadedModel(){const status=$('#loadedModelStatus'),target=$('#currentlyLoadedModel');if(!target)return;status.textContent='Querying Llama Server…';try{const r=await fetch('api.php?action=llama_info',{cache:'no-store'}),j=await r.json(),v=j.server||{};const model=v.model_id||'No model reported';const path=v.model_path||'Path not reported';target.innerHTML='<div><strong>'+escHtml(model)+'</strong></div><div class="hint">'+escHtml(path)+'</div><div class="hint">Runtime HTTP: '+escHtml(String(v.health_http??'unknown'))+'</div>';status.textContent=j.ok?'Updated from runtime':'Llama Server is unavailable or returned partial information'}catch(e){target.textContent=e.message;status.textContent=e.message}}
$('#refreshLoadedModel')?.addEventListener('click',refreshLoadedModel);if($('#currentlyLoadedModel'))refreshLoadedModel();
$('#refreshLlamaInfo').onclick=async()=>{const b=$('#refreshLlamaInfo');b.disabled=true;$('#llamaInfoStatus').textContent='Querying Llama Server…';try{const r=await fetch('api.php?action=llama_info',{cache:'no-store'}),j=await r.json();$('#llamaInfo').textContent=JSON.stringify(j,null,2);$('#llamaInfoStatus').textContent=j.ok?'Updated':'Llama Server did not return complete information'}catch(e){$('#llamaInfoStatus').textContent=e.message}finally{b.disabled=false}};
// Websites connector settings: each website has its own switch; no global switch or manual test panel.
let websiteItems=Array.isArray(window.APP?.websites)?window.APP.websites:[];
function renderWebsitesItems(){
 const list=$('#websiteList');if(!list)return;
 $('#websiteCount').textContent=websiteItems.length+' website';list.innerHTML='';
 if(!websiteItems.length){list.innerHTML='<p class="hint">No websites have been added. Add a base URL to get started.</p>';return;}
 websiteItems.forEach((site,index)=>{
  const row=document.createElement('div');row.className='website-row';
  const details=document.createElement('div');details.className='website-row-main';
  const title=document.createElement('sin');title.textContent=site.name;
  const url=document.createElement('small');url.textContent=site.url;
  details.append(title,url);
  const actions=document.createElement('div');actions.className='website-row-actions';
  const switchLabel=document.createElement('label');switchLabel.className='site-switch';switchLabel.title=site.enabled?'Disable website':'Enable website';
  const toggle=document.createElement('input');toggle.type='checkbox';toggle.role='switch';toggle.checked=!!site.enabled;toggle.setAttribute('aria-label',(site.enabled?'Disable ':'Enable ')+site.name);
  const track=document.createElement('span');track.className='site-switch-track';switchLabel.append(toggle,track);
  toggle.onchange=async()=>{const previous=site.enabled;site.enabled=toggle.checked;toggle.disabled=true;try{await saveWebsitesConfig()}catch(e){site.enabled=previous;renderWebsitesItems()}};
  const test=document.createElement('button');test.className='secondary website-test-button';test.type='button';test.textContent='Test';test.title='Test website access and view extracted content';
  test.onclick=async()=>{test.disabled=true;const panel=$('#websiteTestResult');panel.hidden=false;panel.textContent='Opening website with Python + Playwright + Microsoft Edge…';try{const j=await post('website_test',{id:site.id,query:''});const pages=j.result?.pages||[];panel.replaceChildren();const heading=document.createElement('sin');heading.textContent=pages.some(page=>(page.text||'').trim().length>0)?'Website content extracted: '+site.name:'Website opened, but readable content was not extracted: '+site.name;panel.append(heading);pages.forEach(page=>{const h=document.createElement('div');h.className='website-test-page-title';h.textContent=(page.title||'Untitled')+' — '+page.url;const body=document.createElement('p');body.textContent=(page.text||'').slice(0,1800)||'No text could be extracted.';panel.append(h,body)});if(!pages.some(page=>(page.text||'').trim().length>0))panel.append(document.createTextNode('The connection succeeded, but the reader returned no usable text. Check the extraction diagnostics; this is not reported as a successful read.'));}catch(e){panel.textContent='Test failed: '+e.message;}finally{test.disabled=false}};
  const remove=document.createElement('button');remove.className='website-delete-icon';remove.type='button';remove.title='Delete website';remove.setAttribute('aria-label','Delete website '+site.name);
  const icon=document.createElement('span');icon.className='material-icons-round';icon.textContent='delete';remove.append(icon);
  remove.onclick=async()=>{if(!confirm('Delete website '+site.name+' from the list?'))return;const previous=websiteItems;websiteItems=websiteItems.filter((_,i)=>i!==index);await saveWebsitesConfig(previous)};
  actions.append(test,switchLabel,remove);row.append(details,actions);list.append(row);
 });
}
async function saveWebsitesConfig(previousItems=null){
 const status=$('#websiteSaveStatus');if(status)status.textContent='Saving…';
 try{
  const j=await post('websites_save',{enabled:'1',websites:JSON.stringify(websiteItems)});
  websiteItems=j.websites||websiteItems;window.APP.websites=websiteItems;window.APP.websitesEnabled=true;
  renderWebsitesItems();if(status)status.textContent=j.message||'Saved';
 }catch(e){if(previousItems)websiteItems=previousItems;renderWebsitesItems();if(status)status.textContent=e.message;toast(e.message);throw e}
}
$('#addWebsites')?.addEventListener('click',async()=>{
 const name=$('#websiteName').value.trim(),url=$('#websiteUrl').value.trim(),search=$('#websiteSearchUrl').value.trim();
 if(!name||!url){toast('Enter the website name and base URL.');return}
 websiteItems.push({id:'site_'+Date.now().toString(36)+'_'+Math.random().toString(36).slice(2,7),name,url,search_url:search,enabled:true});
 const b=$('#addWebsites');b.disabled=true;try{await saveWebsitesConfig();$('#websiteName').value='';$('#websiteUrl').value='';$('#websiteSearchUrl').value=''}finally{b.disabled=false}
});
renderWebsitesItems();
models();
// Periodically discover newly added or replaced GGUF files while Settings remains open.
setInterval(()=>{if(!document.hidden)models()},120000);
})();
// Disable accidental multi-touch page zoom on mobile browsers where the platform permits it.
document.addEventListener('gesturestart',e=>e.preventDefault(),{passive:false});
document.addEventListener('gesturechange',e=>e.preventDefault(),{passive:false});
document.addEventListener('gestureend',e=>e.preventDefault(),{passive:false});
document.addEventListener('touchmove',e=>{if(e.touches&&e.touches.length>1)e.preventDefault()},{passive:false});

// Interface language is a PHP-rendered preference; it does not constrain AI responses.
$('#saveInterfaceLanguage')?.addEventListener('click',async()=>{
 const button=$('#saveInterfaceLanguage'); const status=$('#languageSaveStatus');
 button.disabled=true; if(status) status.textContent='Saving…';
 try { await post('settings_save',{interface_language:'en'}); window.location.reload(); }
 catch(error) { if(status) status.textContent=error.message; toast(error.message); }
 finally { button.disabled=false; }
});
