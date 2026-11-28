(()=>{'use strict';
let $,generate=()=>{},isGenerating=()=>false;
function init(deps={}){$=deps.$;generate=deps.generate||generate;isGenerating=deps.isGenerating||isGenerating;bind();}
function bind(){
 $('#chatForm').onsubmit=e=>{e.preventDefault();if(!isGenerating())generate('send')};
 $('#send').addEventListener('click',()=>{
  if(!$('#prompt').value.trim()||$('#send').disabled)return;
  const icon=$('#send .material-icons-round');if(!icon)return;
  const source=icon.getBoundingClientRect(),area=$('#messages').getBoundingClientRect();
  const startX=source.left+source.width/2,startY=source.top+source.height/2;
  const endX=area.left+area.width/2,endY=area.top+Math.min(112,Math.max(56,area.height*.2));
  const ghost=icon.cloneNode(true);ghost.className='material-icons-round send-flight';ghost.textContent='arrow_upward';ghost.setAttribute('aria-hidden','true');ghost.style.left=startX+'px';ghost.style.top=startY+'px';ghost.style.setProperty('--fly-x',(endX-startX)+'px');ghost.style.setProperty('--fly-y',(endY-startY)+'px');
  document.body.append(ghost);const remove=()=>{if(ghost.isConnected)ghost.remove()};ghost.addEventListener('animationend',remove,{once:true});window.setTimeout(remove,1200);
 });
 $('#prompt').addEventListener('input',()=>{$('#prompt').style.height='auto';$('#prompt').style.height=Math.min($('#prompt').scrollHeight,180)+'px';});
}
window.LACChatComposerUI={init};
})();