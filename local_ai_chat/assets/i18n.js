/* English-only interface localization. */
(()=>{'use strict';
const KEY='local_ai_language';
function translate(value){return value==null?value:String(value)}
function apply(root=document){
 const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);let node;
 while((node=walker.nextNode())){
  if(node.parentElement?.closest('script,style,code,pre,textarea,[data-no-i18n]'))continue;
  node.nodeValue=translate(node.nodeValue);
 }
}
function setLanguage(){localStorage.setItem(KEY,'en');document.documentElement.lang='en';document.documentElement.dataset.language='en';location.reload()}
function mount(){
 localStorage.setItem(KEY,'en');document.documentElement.lang='en';document.documentElement.dataset.language='en';
 const select=document.createElement('select');select.className='language-switch';select.setAttribute('aria-label','Language');
 select.innerHTML='<option value="en" selected>English</option>';select.value='en';select.disabled=true;
 document.body.append(select);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',mount,{once:true});else mount();
window.LACI18N={t:translate,language:()=> 'en',setLanguage,apply};
})();
