const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.resolve(__dirname,'../..');
function loadWindowModule(relative){
 const window={};
 vm.runInNewContext(fs.readFileSync(path.join(root,relative),'utf8'),{window,console});
 return window;
}
test('SSE parser normalizes CRLF and retains incomplete block',()=>{
 const w=loadWindowModule('assets/js/chat-sse-utils.js');
 const parsed=w.LACChatSSE.parseBuffer('event: delta\r\ndata: {"text":"a"}\r\n\r\nevent: done\r\ndata: {"stopped":true}');
 assert.equal(parsed.blocks.length,1);
 assert.equal(parsed.remainder,'event: done\ndata: {"stopped":true}');
 assert.deepEqual({...w.LACChatSSE.parseBlock(parsed.blocks[0])},{event:'delta',data:'{"text":"a"}'});
});
test('meta event records run/message IDs and honors pending stop',async()=>{
 const w=loadWindowModule('assets/js/chat-generation-events.js');
 const calls=[],states=[],traces=[];
 w.LACChatGenerationEvents.init({post:async(...args)=>{calls.push(args);return{}},setState:(...args)=>states.push(args),startTrace:(...args)=>traces.push(args)});
 const body={closest:()=>({setAttribute:(k,v)=>calls.push(['attribute',k,v])})};
 const ctx={assistantBody:body,baseText:'',full:'',pendingStop:true,currentModelAlias:'Alias'};
 await w.LACChatGenerationEvents.handle('meta',{run_id:'run-1',message_id:42,model_label:'Test model'},ctx);
 assert.equal(ctx.activeRun,'run-1');assert.equal(ctx.activeMessageId,42);assert.equal(ctx.generationModelLabel,'Test model');
 assert.equal(calls.some(c=>c[0]==='stop_generation'&&c[1].run_id==='run-1'),true);
 assert.equal(traces.length,1);assert.equal(states.length,1);
});
test('delta, clear_delta, usage, finish and done update context',async()=>{
 const w=loadWindowModule('assets/js/chat-generation-events.js');
 const states=[];let scrolls=0;
 w.LACChatGenerationEvents.init({md:s=>'MD:'+s,setState:(...a)=>states.push(a),scroll:()=>scrolls++});
 const body={innerHTML:''},ctx={assistantBody:body,baseText:'base',full:'',usage:null,finishReason:null,stopped:false};
 await w.LACChatGenerationEvents.handle('delta',{text:'x'},ctx);
 assert.equal(ctx.full,'x');assert.equal(body.innerHTML,'MD:basex');assert.equal(scrolls,1);
 await w.LACChatGenerationEvents.handle('clear_delta',{},ctx);
 assert.equal(ctx.full,'');assert.equal(body.innerHTML,'MD:base');
 await w.LACChatGenerationEvents.handle('usage',{total_tokens:5},ctx);assert.equal(ctx.usage.total_tokens,5);
 await w.LACChatGenerationEvents.handle('finish',{reason:'length'},ctx);assert.equal(ctx.finishReason,'length');
 await w.LACChatGenerationEvents.handle('done',{stopped:true,usage:{total_tokens:6},finish_reason:'stop',message_id:7},ctx);
 assert.equal(ctx.stopped,true);assert.equal(ctx.assistantMessageId,7);assert.equal(ctx.usage.total_tokens,6);assert.equal(ctx.finishReason,'stop');
});
test('error event throws model error',async()=>{
 const w=loadWindowModule('assets/js/chat-generation-events.js');
 await assert.rejects(()=>w.LACChatGenerationEvents.handle('error',{message:'bad model'},{}),/bad model/);
});

test('generation orchestrator completes send stream and cleans state',async()=>{
 const window={};const calls=[];
 const sse=fs.readFileSync(path.join(root,'assets/js/chat-sse-utils.js'),'utf8');
 const events=fs.readFileSync(path.join(root,'assets/js/chat-generation-events.js'),'utf8');
 const orchestrator=fs.readFileSync(path.join(root,'assets/js/chat-generation-orchestrator.js'),'utf8');
 class FakeFormData{constructor(){this.values=[]}append(k,v){this.values.push([k,v])}}
 const stream='event: meta\\ndata: {"run_id":"run-1","message_id":10,"model_label":"Model"}\\n\\nevent: delta\\ndata: {"text":"Hello"}\\n\\nevent: usage\\ndata: {"total_tokens":4}\\n\\nevent: done\\ndata: {"stopped":false,"usage":{"total_tokens":4},"finish_reason":"stop","message_id":10}\\n\\n';
 const context={window,console,FormData:FakeFormData,AbortController,TextDecoder,TextEncoder,setInterval:()=>1,clearInterval:()=>{},fetch:async()=>({ok:true,body:{getReader:()=>{let sent=false;return{read:async()=>sent?({done:true}):(sent=true,{done:false,value:new TextEncoder().encode(stream)})}}}}),setTimeout,};
 vm.runInNewContext(sse,context);vm.runInNewContext(events,context);
 const prompt={value:'hello',style:{height:'auto'}},model={value:''},web={checked:false},website={checked:false};
 const elapsed={textContent:''},token={textContent:''},continueBtn={hidden:false,disabled:false};
 const tools={querySelector(sel){if(sel==='.token-meta')return token;if(sel==='[data-act=continue]')return continueBtn;return null}};
 const msg={dataset:{},classList:{add(){},remove(){}},querySelector(sel){if(sel==='.elapsed')return elapsed;if(sel==='.message-tools')return tools;return null}};
 const body={innerHTML:'',parentElement:msg,closest:()=>msg,innerText:'',};
 const selectors={'#prompt':prompt,'#chatModel':model,'#webSearchToggle':web,'#toolWebsites':website};
 const $=sel=>selectors[sel]||{};
 const state={current:0,controller:null,activeRun:null,activeMessageId:0,pendingStop:false,generationStarting:false,lastAssistant:null,modelAlias:'Alias',label:'Model'};
 const st={getCurrent:()=>state.current,setCurrent:v=>state.current=v,getController:()=>state.controller,setController:v=>state.controller=v,getActiveRun:()=>state.activeRun,setActiveRun:v=>state.activeRun=v,getActiveMessageId:()=>state.activeMessageId,setActiveMessageId:v=>state.activeMessageId=v,getPendingStop:()=>state.pendingStop,setPendingStop:v=>state.pendingStop=v,getGenerationStarting:()=>state.generationStarting,setGenerationStarting:v=>state.generationStarting=v,getLastAssistant:()=>state.lastAssistant,setLastAssistant:v=>state.lastAssistant=v,getCurrentModelAlias:()=>state.modelAlias,getGenerationModelLabel:()=>state.label,setGenerationModelLabel:v=>state.label=v};
 window.LACChatGenerationEvents.init({md:s=>s,post:async()=>({}),toast:m=>calls.push(['toast',m]),setState:()=>{},startTrace:()=>{},scroll:()=>{},confirmAction:()=>false});
 window.LACChatGenerationOrchestrator.init({$: $,state:st,isModelSwitching:()=>false,isRecoveredGeneration:()=>false,selectedModelDisplay:()=>state.modelAlias,createChat:async()=>{state.current=123},setGenerating:v=>calls.push(['generating',v]),addMessage:(role,text)=>{calls.push(['message',role,text]);return role==='assistant'?body:undefined},setAssistantGenerationState:()=>{},formatHMS:()=> '00:00:01',csrf:'csrf',selectedDocIds:new Set(),updateToolMode:()=>{},agentToolsSavePromise:async()=>{},refreshConversationAttachments:async id=>calls.push(['attachments',id]),loadHistory:async()=>calls.push(['history']),startRecovery:()=>calls.push(['recovery']),toast:m=>calls.push(['toast',m])});
 await window.LACChatGenerationOrchestrator.generate('send');
 assert.equal(state.controller,null);assert.equal(state.activeRun,null);assert.equal(state.generationStarting,false);
 assert.equal(body.innerHTML,'Hello');assert.equal(token.textContent,'Token: 4');assert.equal(continueBtn.hidden,true);
 assert.equal(calls.some(c=>c[0]==='history'),true);assert.equal(calls.some(c=>c[0]==='recovery'),true);assert.equal(calls.some(c=>c[0]==='attachments'&&c[1]===10),true);
});
