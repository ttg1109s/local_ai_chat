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

