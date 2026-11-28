(()=>{
'use strict';
const mathEsc=s=>String(s).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&apos;"}[c]));
const renderMathML=(source,display=false)=>{
 const greek={alpha:"α",beta:"β",gamma:"γ",delta:"δ",epsilon:"ε",theta:"θ",lambda:"λ",mu:"μ",pi:"π",rho:"ρ",sigma:"σ",phi:"φ",omega:"ω",Gamma:"Γ",Delta:"Δ",Theta:"Θ",Lambda:"Λ",Pi:"Π",Sigma:"Σ",Phi:"Φ",Omega:"Ω"};
 const symbols={times:"×",cdot:"·",div:"÷",pm:"±",mp:"∓",le:"≤",leq:"≤",ge:"≥",geq:"≥",neq:"≠",approx:"≈",infty:"∞",partial:"∂",nabla:"∇",rightarrow:"→",leftarrow:"←",Rightarrow:"⇒",sum:"∑",prod:"∏",int:"∫",oint:"∮",in:"∈",notin:"∉",forall:"∀",exists:"∃",land:"∧",lor:"∨"};
 let i=0,s=String(source).replace(/\\left|\\right/g,"").replace(/\\,/g," ").replace(/\\;/g," ");
 const mi=x=>'<mi>'+mathEsc(x)+'</mi>', mo=x=>'<mo>'+mathEsc(x)+'</mo>', mn=x=>'<mn>'+mathEsc(x)+'</mn>', row=x=>'<mrow>'+x+'</mrow>';
 const group=()=>{if(s[i]==='{'){i++;let v=seq('}');if(s[i]==='}')i++;return row(v)}return atom()};
 const atom=()=>{
  if(i>=s.length)return '';
  let c=s[i++];
  if(c==='{') {let v=seq('}');if(s[i]==='}')i++;return row(v)}
  if(c==='\\'){
   let m=s.slice(i).match(/^[A-Za-z]+/);
   if(m){let cmd=m[0];i+=cmd.length;
    if(cmd==='frac'){let a=group(),b=group();return '<mfrac>'+a+b+'</mfrac>'}
    if(cmd==='sqrt'){let a=group();return '<msqrt>'+a+'</msqrt>'}
    if(cmd==='text'||cmd==='mathrm'||cmd==='mathbf'||cmd==='mathit'){let a=group();return '<mstyle mathvariant="'+(cmd==='mathbf'?'bold':cmd==='mathit'?'italic':'normal')+'">'+a+'</mstyle>'}
    if(greek[cmd])return mi(greek[cmd]);
    if(symbols[cmd])return mo(symbols[cmd]);
    if(cmd==='quad'||cmd==='qquad')return '<mspace width="'+(cmd==='quad'?'1em':'2em')+'"/>';
    if(['sin','cos','tan','cot','sec','csc','log','ln','lim','max','min','det'].includes(cmd))return '<mi mathvariant="normal">'+cmd+'</mi>';
    if(cmd==='overline'||cmd==='bar')return '<mover>'+group()+'<mo>¯</mo></mover>';
    return mi(cmd);
   }
   if(i<s.length){let x=s[i++];return x==='%'?mo('%'):mi(x)}
   return '';
  }
  if(/\s/.test(c))return '<mspace width="0.25em"/>';
  if(/[0-9]/.test(c)){let n=c;while(i<s.length&&/[0-9.,]/.test(s[i]))n+=s[i++];return mn(n)}
  if(/[A-Za-z]/.test(c)){let n=c;while(i<s.length&&/[A-Za-z]/.test(s[i]))n+=s[i++];return mi(n)}
  if(c==='&')return mo('&');
  if('+-=<>()[\],.:;|'.includes(c))return mo(c);
  return mi(c);
 };
 const seq=end=>{let out='';while(i<s.length&&s[i]!==end){if(s[i]==='}')break;let a=atom();if(!a){i++;continue}let sub=null,sup=null;while(i<s.length&&(s[i]==='^'||s[i]==='_')){let op=s[i++],v=group();if(op==='^')sup=v;else sub=v}if(sub&&sup)a='<msubsup>'+a+sub+sup+'</msubsup>';else if(sub)a='<msub>'+a+sub+'</msub>';else if(sup)a='<msup>'+a+sup+'</msup>';out+=a}return out};
 const body=seq('');
 return '<math xmlns="http://www.w3.org/1998/Math/MathML" display="'+(display?'block':'inline')+'">'+row(body)+'</math>';
};
const inferCodeLanguage=(code)=>{
 const source=String(code||'').trim();
 if(!source)return '';
 const has=(re)=>re.test(source);
 if(has(/^<!doctype\s+html|^<html\b|<(?:head|body|div|section|script|style|form|h[1-6])\b/i))return 'markup';
 if(has(/^<\?php\b|\$[A-Za-z_][\w]*\s*=|\b(?:echo|print_r|mysqli_query)\s*\(/i))return 'php';
 if(has(/^(?:from\s+\w+\s+import|import\s+\w+|def\s+\w+\s*\(|class\s+\w+\s*[:(])/m)||has(/\b(?:print|elif|None|True|False)\s*\(?/))return 'python';
 if(has(/^(?:const|let|var)\s+\w+|\bfunction\s+\w+\s*\(|\bconsole\.(?:log|error)\s*\(/m)||has(/\bdocument\.getElementById\s*\(/))return 'javascript';
 if(has(/^\s*[.#]?[\w-]+(?:\s*,\s*[.#]?[\w-]+)*\s*\{[\s\S]*?:\s*[^;]+;/m))return 'css';
 try { JSON.parse(source); return 'json'; } catch (_) {}
 if(has(/\b(?:SELECT|INSERT\s+INTO|UPDATE\s+\w+\s+SET|CREATE\s+TABLE|DELETE\s+FROM)\b/i))return 'sql';
 if(has(/^\s*#!/)||has(/\b(?:sudo|apt-get|npm|composer|pip)\s+(?:install|update|run)\b/))return 'bash';
 return '';
};
const typesetMath=(root)=>{
 if(!root||!window.Prism)return;
 root.querySelectorAll('.code-card pre code, pre code[class*=\"language-\"]').forEach(code=>{
  if(code.dataset.prismDone==='1')return;
  const pre=code.closest('pre');
  let language=(code.className.match(/(?:^|\s)language-([\w-]+)/)||[])[1]||'';
  if(!language||['none','text','plaintext','code'].includes(language)){
   language=inferCodeLanguage(code.textContent);
   if(language){code.className=(' '+code.className+' ').replace(/\s+language-(?:none|text|plaintext|code)\s+/g,' ').trim();code.classList.add('language-'+language);}
  }
  if(pre&&pre.closest('.code-card'))pre.classList.add('line-numbers');
  const grammar=language&&window.Prism.languages[language];
  if(grammar){window.Prism.highlightElement(code);code.dataset.prismDone='1';
   const card=code.closest('.code-card'),label=card?.querySelector('.code-card-lang');
   if(label&&/^(?:Code|NONE)$/i.test(label.textContent.trim())){const names={markup:'HTML',javascript:'JavaScript',typescript:'TypeScript',python:'Python',php:'PHP',css:'CSS',json:'JSON',sql:'SQL',bash:'Bash'};label.textContent=names[language]||language.toUpperCase();}
  }else{code.dataset.prismDone='1';}
 });
};

window.LACChatRender={typesetMath};
})();
