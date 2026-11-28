(()=>{
'use strict';
const $=window.LACChatCore.$;
let getCurrentId=()=>0,openConversation=()=>{},post=async()=>{},toast=()=>{},onDeleteCurrent=()=>{};
function init(deps={}){
 getCurrentId=typeof deps.getCurrentId==='function'?deps.getCurrentId:()=>0;
 openConversation=typeof deps.openConversation==='function'?deps.openConversation:()=>{};
 post=typeof deps.post==='function'?deps.post:async()=>{};
 toast=typeof deps.toast==='function'?deps.toast:()=>{};
 onDeleteCurrent=typeof deps.onDeleteCurrent==='function'?deps.onDeleteCurrent:()=>{};
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
   const row=document.createElement('div');row.className='history-row'+(Number(c.id)===getCurrentId()?' active':'');
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
    if(getCurrentId()===Number(c.id))onDeleteCurrent();
    await loadHistory();
   },'danger');
   row.append(open,more,menu);section.append(row);
  });
  host.append(section);
 }
}
document.addEventListener('click',e=>{if(!e.target.closest('.history-row')&&!e.target.closest('.history-context-menu'))document.querySelectorAll('.history-context-menu').forEach(m=>m.hidden=true)});
window.LACChatHistoryUI={init,loadHistory};
})();
