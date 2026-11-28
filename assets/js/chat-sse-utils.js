(()=>{'use strict';
function parseBuffer(buffer){const normalized=String(buffer||'').replace(/\r\n/g,'\n').replace(/\r/g,'\n');const blocks=normalized.split('\n\n');const remainder=blocks.pop()||'';return {blocks,remainder};}
function parseBlock(block){const event=(block.match(/^event:\s*(.+)$/m)||[])[1]||'';const data=(block.match(/^data:\s*(.+)$/m)||[])[1]||'';return {event,data};}
window.LACChatSSE={parseBuffer,parseBlock};
})();