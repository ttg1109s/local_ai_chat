<?php
/** API route family: generation */
if($action==='generation_watch'){
 // A fresh, read-only SSE connection used after a browser reload. The original
 // chat_stream request remains the sole owner of generation and keeps writing
 // tokens to messages; this endpoint only tails the persisted message content.
 $unlocked=array_map('intval',$_SESSION['conversation_unlocks']??[]);
 session_write_close();
 ignore_user_abort(false);
 @set_time_limit(0);
 $id=(int)($_GET['id']??0);$messageId=(int)($_GET['message_id']??0);
 if(!$id||!$messageId){http_response_code(422);exit;}
 $cq=$pdo->prepare('SELECT password_hash FROM conversations WHERE id=?');$cq->execute([$id]);$passwordHash=$cq->fetchColumn();
 if($passwordHash===false){http_response_code(404);exit;}
 if($passwordHash&&!in_array($id,$unlocked,true)){http_response_code(423);exit;}
 $mq=$pdo->prepare("SELECT id FROM messages WHERE id=? AND conversation_id=? AND role='assistant'");$mq->execute([$messageId,$id]);
 if(!$mq->fetchColumn()){http_response_code(404);exit;}
 while(ob_get_level()>0){@ob_end_clean();}
 header('Content-Type: text/event-stream; charset=utf-8');
 header('Cache-Control: no-cache, no-store, must-revalidate');
 header('X-Accel-Buffering: no');
 header('Connection: keep-alive');
 $send=function(string $event,array $data,?int $eventId=null){
  if($eventId!==null)echo 'id: '.$eventId."\n";
  echo 'event: '.$event."\n".'data: '.json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)."\n\n";
  @ob_flush();@flush();
 };
 $lastHeader=isset($_SERVER['HTTP_LAST_EVENT_ID'])?(string)$_SERVER['HTTP_LAST_EVENT_ID']:'';
 $offset=(ctype_digit($lastHeader)?(int)$lastHeader:0);
 $first=$lastHeader==='';
 $lockName='lac_gen_'.$id;
 $deadline=microtime(true)+25;
 while(microtime(true)<$deadline){
  if(connection_aborted())break;
  $q=$pdo->prepare("SELECT content,interrupted,status_label,elapsed_seconds,token_count,request_timestamp,model_id FROM messages WHERE id=? AND conversation_id=? AND role='assistant'");
  $q->execute([$messageId,$id]);$m=$q->fetch();
  if(!$m)break;
  $content=(string)$m['content'];$length=strlen($content);
  if($first||$offset>$length){$send('snapshot',['content'=>$content],$length);$offset=$length;$first=false;}
  elseif($length>$offset){$delta=substr($content,$offset);$offset=$length;$send('delta',['text'=>$delta],$offset);}
  $lq=$pdo->prepare('SELECT IS_USED_LOCK(?)');$lq->execute([$lockName]);$owner=$lq->fetchColumn();
  $running=$owner!==false&&$owner!==null;
  if(!$running){
   $send('complete',['status_label'=>(string)$m['status_label'],'interrupted'=>(int)$m['interrupted'],'elapsed_seconds'=>(int)$m['elapsed_seconds'],'token_count'=>$m['token_count']===null?null:(int)$m['token_count'],'content'=>$content],$offset);
   exit;
  }
  echo ": heartbeat\n\n";@ob_flush();@flush();
  usleep(350000);
 }
 exit;
}
if($action==='generation_trace'){
 header('Cache-Control: no-store, no-cache, must-revalidate');
 $id=(int)($_GET['id']??0);$messageId=(int)($_GET['message_id']??0);
 if(!$id||!$messageId)out(['error'=>'Conversation or message_id is missing'],422);
 $unlocked=array_map('intval',$_SESSION['conversation_unlocks']??[]);
 $cq=$pdo->prepare('SELECT password_hash FROM conversations WHERE id=?');$cq->execute([$id]);$hash=$cq->fetchColumn();
 if($hash===false)out(['error'=>'Conversation not found'],404);
 if($hash&&!in_array($id,$unlocked,true))out(['error'=>'Conversation is locked'],423);
 $mq=$pdo->prepare("SELECT id FROM messages WHERE id=? AND conversation_id=? AND role='assistant'");$mq->execute([$messageId,$id]);
 if(!$mq->fetchColumn())out(['error'=>'Message not found'],404);
 $path=LAC_ROOT.'/storage/traces/'.$messageId.'.jsonl';$events=[];$after=max(0,(int)($_GET['after_sequence']??0));$sequence=0;$latest=0;$limit=500;
 if(is_file($path)){
  $fh=@fopen($path,'rb');
  if($fh){while(($line=fgets($fh))!==false){$v=json_decode($line,true);if(!is_array($v))continue;$sequence++;$seq=(int)($v['sequence']??$sequence);$latest=max($latest,$seq);if($seq<=$after)continue;if(count($events)>=$limit)break;if(!isset($v['sequence']))$v['sequence']=$seq;$events[]=$v;}fclose($fh);}
 }
 out(['events'=>$events,'latest_sequence'=>$latest,'next_sequence'=>$events?(int)($events[count($events)-1]['sequence']??$latest):$after,'has_more'=>$latest>$after+count($events)]);
}
if($action==='generation_snapshot'){
 header('Cache-Control: no-store, no-cache, must-revalidate');
 $id=(int)($_GET['id']??0);if(!$id)out(['error'=>'Conversation ID is missing'],422);lac_require_conversation_unlocked($pdo,$id);
 $lockName='lac_gen_'.$id;$lockQ=$pdo->prepare('SELECT IS_USED_LOCK(?)');$lockQ->execute([$lockName]);$lockOwner=$lockQ->fetchColumn();$running=$lockOwner!==false && $lockOwner!==null;
 $q=$pdo->prepare("SELECT m.id AS id,m.content AS content,m.model_id AS model_id,m.status_label AS status_label,m.interrupted AS interrupted,m.elapsed_seconds AS elapsed_seconds,m.request_timestamp AS request_timestamp,m.token_count AS token_count,COALESCE(NULLIF(a.alias,''),m.model_id,'') AS model_label FROM messages AS m LEFT JOIN model_aliases AS a ON a.model_id=m.model_id WHERE m.conversation_id=? AND m.role='assistant' ORDER BY m.id DESC LIMIT 1");$q->execute([$id]);$m=$q->fetch();
 if(!$m)out(['running'=>false,'message'=>null]);
 $started=strtotime((string)$m['request_timestamp']);$elapsed=max(0,(int)$m['elapsed_seconds']);
 if($running&&$started)$elapsed=max($elapsed,time()-$started);
 // A worker that died or lost its DB connection releases GET_LOCK. Do not leave
 // its message looking perpetually active; mark it recoverable without schema changes.
 if(!$running&&in_array((string)$m['status_label'],['preparing','typing','generating','continuing'],true)){
  if($started)$elapsed=max($elapsed,time()-$started);
  $pdo->prepare("UPDATE messages SET interrupted=1,status_label='connection lost',elapsed_seconds=? WHERE id=? AND conversation_id=? AND role='assistant'")->execute([$elapsed,(int)$m['id'],$id]);
  $m['interrupted']=1;$m['status_label']='connection lost';$m['elapsed_seconds']=$elapsed;
 }
 out(['running'=>$running,'message'=>['id'=>(int)$m['id'],'content'=>(string)$m['content'],'model_label'=>(string)$m['model_label'],'status_label'=>(string)$m['status_label'],'interrupted'=>(int)$m['interrupted'],'elapsed_seconds'=>$elapsed,'token_count'=>$m['token_count']===null?null:(int)$m['token_count']]]);
}
if($action==='stop_generation'){
 require_csrf();$dir=LAC_ROOT.'/storage/runs';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))out(['error'=>'Could not create the process-control directory.'],500);
 $run=preg_replace('/[^a-f0-9]/','',(string)($_POST['run_id']??''));$messageId=(int)($_POST['message_id']??0);$conversationId=(int)($_POST['conversation_id']??0);
 if(strlen($run)===20){
   $metaPath=$dir.'/'.$run.'.json';$meta=is_file($metaPath)?json_decode((string)file_get_contents($metaPath),true):null;
   if(!is_array($meta))out(['error'=>'Process not found.'],404);
   $runConversation=(int)($meta['conversation_id']??0);$runMessage=(int)($meta['message_id']??0);
   lac_require_conversation_unlocked($pdo,$runConversation);
   $verify=$pdo->prepare('SELECT id FROM messages WHERE id=? AND conversation_id=? AND role=\'assistant\'');$verify->execute([$runMessage,$runConversation]);
   if(!$verify->fetchColumn())out(['error'=>'Process is no longer valid.'],404);
   file_put_contents($dir.'/'.$run.'.stop','stop');out(['ok'=>true]);
 }
 if($messageId>0&&$conversationId>0){
   $q=$pdo->prepare("SELECT id FROM messages WHERE id=? AND conversation_id=? AND role='assistant'");$q->execute([$messageId,$conversationId]);
   if(!$q->fetchColumn())out(['error'=>'Generation task to stop was not found.'],404);
   file_put_contents($dir.'/'.$messageId.'.stop','stop');out(['ok'=>true]);
 }
 out(['error'=>'A valid process ID or message_id is missing.'],422);
}

