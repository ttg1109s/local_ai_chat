<?php
require_once LAC_ROOT.'/app/agent/artifact_manager.php';
require_once LAC_ROOT.'/app/agent/attachment_manager.php';
require_once LAC_ROOT.'/app/agent/result_store.php';
/** API route family: conversations */
if($action==='conversations'){
 $rows=$pdo->query('SELECT id,title,sandbox,(password_hash IS NOT NULL) AS locked,created_at,updated_at FROM conversations ORDER BY updated_at DESC,id DESC')->fetchAll();out(['items'=>$rows]);
}
if($action==='new'){
 require_csrf();$pdo->prepare('INSERT INTO conversations(title,sandbox) VALUES(?,0)')->execute(['New chat']);out(['id'=>(int)$pdo->lastInsertId()]);
}
if($action==='history'){
 $id=(int)($_GET['id']??0);$c=lac_require_conversation_unlocked($pdo,$id);$q=$pdo->prepare('SELECT id,title,sandbox,(password_hash IS NOT NULL) AS locked FROM conversations WHERE id=?');$q->execute([$id]);$c=$q->fetch();$q=$pdo->prepare("SELECT m.id,m.role,m.content,m.created_at,m.request_timestamp,m.interrupted,COALESCE(NULLIF(a.alias,''),m.model_id,'') AS model_label,m.model_id,m.elapsed_seconds,m.status_label,m.token_count FROM messages m LEFT JOIN model_aliases a ON a.model_id=m.model_id WHERE m.conversation_id=? ORDER BY m.id");$q->execute([$id]);$messages=$q->fetchAll();$attachments=lac_attachment_list((string)$id,null);foreach($messages as &$message){$message['attachments']=$message['role']==='assistant'?lac_attachment_list((string)$id,(string)$message['id']):[];}unset($message);out(['conversation'=>$c,'messages'=>$messages,'attachments'=>$attachments]);
}
if($action==='attachment_content'){
 $conversationId=(int)($_GET['conversation_id']??0);$attachmentId=(string)($_GET['attachment_id']??'');lac_require_conversation_unlocked($pdo,$conversationId);
 $attachment=lac_attachment_get($attachmentId,(string)$conversationId);if(!$attachment||($attachment['visibility']??'')!=='user')out(['error'=>'Attachment not found'],404);
 if(($attachment['source_type']??'')==='artifact'){
  $artifactId=(string)($attachment['artifact_ref']??$attachment['source_ref']??'');
  if(!preg_match('/^art_[a-f0-9]{24}$/',$artifactId))out(['error'=>'Artifact reference is invalid'],422);
  out(['attachment'=>$attachment,'preview'=>['type'=>'image','url'=>'api.php?action=artifact_image&id='.rawurlencode($artifactId)]]);
 }
 if(($attachment['source_type']??'')!=='data_pack'||empty($attachment['run_id']))out(['error'=>'This attachment has no previewable Data Pack source.','attachment'=>$attachment],422);
 try{
  $manifest=lac_data_pack_manifest_get((string)$attachment['source_ref'],(string)$attachment['run_id']);$preview=null;
  if(is_array($manifest)){$field=null;foreach($manifest['fields']??[] as $name=>$meta)if(!empty($meta['segmented'])){$field=(string)$name;break;}if($field!==null){$preview=['type'=>'tabular_page','field'=>$field]+lac_data_pack_read_segmented((string)$attachment['source_ref'],(string)$attachment['run_id'],$field,0,50);}}
  if($preview===null){$preview=['type'=>'data_reference','data_ref'=>$attachment['source_ref'],'manifest_available'=>false,'message'=>'This result is available through the Data Access API; no full-payload preview is generated.'];}
  out(['attachment'=>$attachment,'preview'=>$preview]);
 }catch(Throwable $e){out(['error'=>'Attachment source could not be read'],409);}
}
if($action==='delete'){
 require_csrf();$id=(int)($_POST['id']??0);lac_require_conversation_unlocked($pdo,$id);
 $mq=$pdo->prepare('SELECT content FROM messages WHERE conversation_id=?');$mq->execute([$id]);$chartFiles=[];while($row=$mq->fetch())$chartFiles=array_merge($chartFiles,lac_python_chart_files((string)$row['content']));
 lac_artifact_cleanup_conversation($id);lac_attachment_cleanup_conversation((string)$id);$pdo->prepare('DELETE FROM conversations WHERE id=?')->execute([$id]);$cleanup=lac_cleanup_unreferenced_python_charts($pdo,$chartFiles);
 out(['ok'=>true,'charts_deleted'=>count($cleanup['deleted']),'charts_kept_shared'=>count($cleanup['kept'])]);
}

if($action==='rename'){
 require_csrf();$id=(int)($_POST['id']??0);$title=trim((string)($_POST['title']??''));if(!$id||$title==='')out(['error'=>'Invalid conversation title'],422);lac_require_conversation_unlocked($pdo,$id);
 $q=$pdo->prepare('UPDATE conversations SET title=? WHERE id=?');$q->execute([mb_substr($title,0,180),$id]);out(['ok'=>true]);
}
if($action==='conversation_password'){
 require_csrf();
 $id=(int)($_POST['id']??0);$password=(string)($_POST['password']??'');$operation=(string)($_POST['operation']??'set');
 if($id<1)out(['error'=>'Conversation ID is missing.'],422);
 $q=$pdo->prepare('SELECT password_hash FROM conversations WHERE id=?');$q->execute([$id]);$hash=$q->fetchColumn();
 if($hash===false)out(['error'=>'Conversation not found'],404);
 $_SESSION['conversation_unlock_attempts']??=[];
 $attemptKey=(string)$id;
 $verifyCurrent=function(string $candidate)use($hash,$attemptKey):bool{
   $attempt=$_SESSION['conversation_unlock_attempts'][$attemptKey]??['count'=>0,'until'=>0];
   if((int)($attempt['until']??0)>time())out(['error'=>'Too many attempts. Wait before trying again.','retry_after'=>(int)$attempt['until']-time()],429);
   if($hash && password_verify($candidate,(string)$hash)){
     unset($_SESSION['conversation_unlock_attempts'][$attemptKey]);
     return true;
   }
   $count=(int)($attempt['count']??0)+1;$delay=$count>=5?min(900,30*(2**min(5,$count-5))):0;
   $_SESSION['conversation_unlock_attempts'][$attemptKey]=['count'=>$count,'until'=>$delay?time()+$delay:0];
   out(['error'=>'Incorrect password.','attempts_remaining'=>max(0,5-$count),'retry_after'=>$delay],403);
 };
 if($operation==='unlock'){
   if($hash)$verifyCurrent($password);
   unset($_SESSION['conversation_unlock_attempts'][$attemptKey]);
   if(!in_array($id,array_map('intval',$_SESSION['conversation_unlocks']??[]),true))$_SESSION['conversation_unlocks'][]=$id;
   if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
   out(['ok'=>true]);
 }
 if($operation==='remove_force')out(['error'=>'This operation is not supported. Verify the current password to unlock.'],403);
 if($operation==='set'){
   if($hash)$verifyCurrent((string)($_POST['current_password']??''));
   $length=mb_strlen($password,'UTF-8');
   if($length<8)out(['error'=>'Password must contain at least 8 characters.'],422);
   if($length>128)out(['error'=>'Password must not exceed 128 characters.'],422);
   $s=$pdo->prepare('UPDATE conversations SET password_hash=? WHERE id=?');$s->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
   $_SESSION['conversation_unlocks'][]=$id;$_SESSION['conversation_unlocks']=array_values(array_unique(array_map('intval',$_SESSION['conversation_unlocks'])));
   unset($_SESSION['conversation_unlock_attempts'][$attemptKey]);
   if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
   out(['ok'=>true]);
 }
 if($operation==='remove'){
   if(!$hash)out(['ok'=>true]);
   $verifyCurrent($password);
   $pdo->prepare('UPDATE conversations SET password_hash=NULL WHERE id=?')->execute([$id]);
   $_SESSION['conversation_unlocks']=array_values(array_filter(array_map('intval',$_SESSION['conversation_unlocks']??[]),fn($v)=>$v!==$id));
   unset($_SESSION['conversation_unlock_attempts'][$attemptKey]);
   out(['ok'=>true]);
 }
 out(['error'=>'Invalid password operation'],422);
}
if($action==='message_meta_update'){
 require_csrf();$id=(int)($_POST['message_id']??0);$conversation=(int)($_POST['conversation_id']??0);$modelId=mb_substr(trim((string)($_POST['model_id']??'')),0,512);$elapsed=max(0,min(86400,(int)($_POST['elapsed_seconds']??0)));$status=mb_substr(trim((string)($_POST['status_label']??'')),0,40);$tokens=isset($_POST['token_count'])&&$_POST['token_count']!==''?max(0,(int)$_POST['token_count']):null;
 if(!$id||!$conversation||$modelId==='')out(['error'=>'Message information or model_id is missing'],422);lac_require_conversation_unlocked($pdo,$conversation);$q=$pdo->prepare("UPDATE messages SET model_id=?,elapsed_seconds=?,status_label=?,token_count=? WHERE id=? AND conversation_id=? AND role='assistant'");$q->execute([$modelId,$elapsed,$status,$tokens,$id,$conversation]);out(['ok'=>true]);
}

/**
 * Browser-based public web research. Search results and extracted page text are
 * treated as untrusted evidence; they are never allowed to override system rules.
 */
