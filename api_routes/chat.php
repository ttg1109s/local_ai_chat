<?php
/** API route family: chat */
if($action==='chat'){
 require_once LAC_ROOT.'/app/agent/model_transport.php';
 require_csrf();$id=(int)($_POST['id']??0);$message=trim((string)($_POST['message']??''));if(!$id||$message==='')out(['error'=>'Conversation ID or message content is missing'],422);
 $conv= lac_require_conversation_unlocked($pdo,$id);$q=$pdo->prepare('SELECT * FROM conversations WHERE id=?');$q->execute([$id]);$conv=$q->fetch();
 $requestNow=new DateTimeImmutable('now',new DateTimeZone($config['timezone']));$requestTimestamp=$requestNow->format(DateTimeInterface::ATOM);
 $pdo->prepare("INSERT INTO messages(conversation_id,role,content,request_timestamp) VALUES(?,'user',?,?)")->execute([$id,$message,$requestTimestamp]);
 $q=$pdo->prepare('SELECT role,content FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 30');$q->execute([$id]);$history=array_reverse($q->fetchAll());
 $system='You are a helpful and accurate AI assistant. Respond in English only. State clearly when you are uncertain.';

$customSystemPrompt=trim(setting($pdo,'system_prompt',''));if($customSystemPrompt!==''){$system.="\n\nCUSTOM SYSTEM INSTRUCTIONS (administrator-provided):\n".$customSystemPrompt;}
  // Sandbox deliberately has no RAG/document retrieval and only receives this conversation's own messages.
 if(!(int)$conv['sandbox']){
   require_once LAC_ROOT.'/app/rag_engine.php';
   $selectedDocIds=[];
   $decodedDocIds=json_decode((string)($_POST['selected_doc_ids']??'[]'),true);
   if(is_array($decodedDocIds)){$selectedDocIds=array_values(array_unique(array_filter(array_map('intval',$decodedDocIds),static fn($v)=>$v>0)));if(count($selectedDocIds)>100)$selectedDocIds=array_slice($selectedDocIds,0,100);}
   $ragContext="\n\nNo RAG files are attached to this chat turn. No internal documents were retrieved.";
   if($selectedDocIds){
     $marks=implode(',',array_fill(0,count($selectedDocIds),'?'));
     $valid=$pdo->prepare("SELECT COUNT(DISTINCT canonical_chunk_id) FROM rag_document_chunk_map WHERE document_id IN ($marks)");$valid->execute($selectedDocIds);
     if((int)$valid->fetchColumn()>0){
       $leaseKey='chat:'.bin2hex(random_bytes(16));rag_lease_acquire($pdo,$leaseKey,'retrieval',900);
       try{lac_bge_ensure_for_rag($pdo,240);$ragResult=rag_retrieve_result($pdo,$message,5,$selectedDocIds);$ragContext=rag_prompt_context($ragResult);}
       finally{rag_lease_release($pdo,$leaseKey);}
     }else{$ragContext="\n\nThe selected files have no indexed knowledge chunks. No RAG content was retrieved.";}
   }
   $system.=$ragContext;
 }else{$ragContext="\n\nSandbox: RAG and website access are disabled.";}
 $endpoint=setting($pdo,'endpoint',$config['ai']['endpoint']);$model=trim((string)($_POST['model']??setting($pdo,'model',$config['ai']['model'])));$modelConfig=lac_get_model_config($pdo,$model);$generation=$modelConfig['generation_params'];
 if(!(int)$conv['sandbox']){require_once LAC_ROOT.'/app/website_connector.php';$system.=wc_context_for_query($pdo,$message,$ragContext,$endpoint,$model,(int)setting($pdo,'timeout',(string)$config['ai']['timeout']));}
if(preg_match('/bge[-_]?m3|embedding/i',$model))out(['error'=>'BGE-M3 is an embedding model and cannot be used for chat responses.'],422);$payload=['model'=>$model?:'local-model','metadata'=>['timestamp'=>$requestTimestamp],'messages'=>array_merge([['role'=>'system','content'=>$system]],$history),'temperature'=>(float)$generation['temperature'],'top_p'=>(float)$generation['top_p'],'max_tokens'=>(int)$generation['max_tokens'],'stream'=>false];
 $ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>(int)$generation['timeout']]);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($raw===false||$code>=400)out(['error'=>$err?:("llama-server HTTP error ".$code.': '.substr((string)$raw,0,1000))],502);
 $data=json_decode($raw,true);$normalized=is_array($data)?lac_agent_completion_payload($data):['content'=>'','recognized'=>false];$reply=(string)($normalized['content']??'');if($reply==='')out(['error'=>'Model returned empty content or an unsupported completion response.','response_format'=>'openai_compatible_chat_completion','raw'=>$data],502);
 $pdo->prepare("INSERT INTO messages(conversation_id,role,content,model_id,request_timestamp) VALUES(?,'assistant',?,?,?)")->execute([$id,$reply,$model?:'local-model',$requestTimestamp]);
 $title=trim((string)preg_replace('/\\s+/u',' ',strip_tags($message)));$title=mb_substr($title,0,65);$pdo->prepare("UPDATE conversations SET title=IF(title IN ('New chat','New Conversation','New chat','New sandbox'),?,title) WHERE id=?")->execute([$title,$id]);
 out(['reply'=>$reply,'usage'=>$normalized['usage']??null]);
}
