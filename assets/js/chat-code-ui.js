(()=>{
'use strict';
const toast=window.LACChatCore?.toast||(()=>{});
// Delegated controls keep code-card UI independent from Markdown and chat orchestration.
document.addEventListener('click',async e=>{
 const button=e.target.closest('[data-code-copy],[data-code-download],[data-code-collapse]');if(!button)return;
 const card=button.closest('[data-code-card]'),code=card?.querySelector('pre code');if(!code)return;
 if(button.hasAttribute('data-code-copy')){try{await navigator.clipboard.writeText(code.textContent);button.textContent='Copied';setTimeout(()=>{if(button.isConnected)button.textContent='Copy'},1400)}catch(_){toast('Could not copy code in this browser')}}
 else if(button.hasAttribute('data-code-download')){const blob=new Blob([code.textContent],{type:'text/plain;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='code-'+(code.className.match(/language-([\\w+-]+)/)?.[1]||'txt')+'.txt';a.click();URL.revokeObjectURL(url)}
 else {const pre=card.querySelector('pre'),collapsed=pre.hidden;pre.hidden=!collapsed;button.textContent=collapsed?'Collapse':'Expand'}
});
})();
