(()=>{
'use strict';
const core=window.LACChatCore,post=core.post,toast=core.toast;
// Hide stale chart markup after its PNG has been deleted (including after a page reload).
document.addEventListener('error',e=>{const img=e.target;if(img instanceof HTMLImageElement&&img.closest('[data-python-image-wrap]'))img.closest('[data-python-image-wrap]').remove();},true);
// Generated Python chart controls: closing the image also deletes its PNG on the server.
document.addEventListener('click',async e=>{
 const button=e.target.closest('[data-python-image-delete]');if(!button)return;
 const file=button.dataset.pythonImageDelete;if(!/^chart_[a-f0-9]{32}\.(?:jpg|png)$/.test(file))return;
 if(button.disabled)return;button.disabled=true;button.textContent='…';
 const messageId=Number(button.closest('.message[data-message-id]')?.dataset.messageId||0);
 try{await post('python_output_delete',{file,message_id:messageId});const wrap=button.closest('[data-python-image-wrap]');if(wrap)wrap.remove();toast('Chart removed from the message. The file is retained if it is still used elsewhere.');}
 catch(err){button.disabled=false;button.textContent='×';toast(err.message||'Could not delete the chart');}
});

})();
