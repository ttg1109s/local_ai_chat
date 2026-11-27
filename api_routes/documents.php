<?php
/** API route family: documents */
if($action==='upload'){
 require_csrf();if(!isset($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)out(['error'=>'Upload failed or the file exceeds the PHP upload limit.'],422);
 $f=$_FILES['file'];if($f['size']>$config['max_upload_mb']*1024*1024)out(['error'=>'File exceeds the application size limit.'],422);
 $name=mb_substr(basename((string)$f['name']),0,255);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$allowed=['txt','md','csv','json','xml','html','htm','log','docx','xlsx','pptx'];
 if(!in_array($ext,$allowed,true))out(['error'=>'Supported formats: TXT, MD, CSV, JSON, XML, HTML, LOG, DOCX, XLSX, and PPTX. PDF is disabled because a reliable PDF extractor is not available.'],422);
 require_once LAC_ROOT.'/app/rag_engine.php';$hash=hash_file('sha256',$f['tmp_name'],true);$dupe=$pdo->prepare('SELECT id,name FROM rag_documents WHERE file_hash=?');$dupe->execute([$hash]);$existing=$dupe->fetch();if($existing)out(['ok'=>true,'duplicate'=>true,'document_id'=>(int)$existing['id'],'message'=>'Duplicate SHA-256 file; skipped without creating a copy.']);
 $dir=LAC_ROOT.'/storage/uploads';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))out(['error'=>'Could not create the document storage directory.'],500);
 try{$pdo->beginTransaction();$pdo->prepare('INSERT INTO rag_documents(name,mime,size_bytes,file_hash) VALUES(?,?,?,?)')->execute([$name,(string)($f['type']??'application/octet-stream'),(int)$f['size'],$hash]);$doc=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO document_ingest_jobs(document_id,status) VALUES(?,'queued')")->execute([$doc]);$dest=$dir.'/'.$doc.'-'.bin2hex($hash);if(!move_uploaded_file($f['tmp_name'],$dest))throw new RuntimeException('Could not save the uploaded file.');$pdo->commit();lac_bge_mark_activity();lac_manager_request('ensure-rag-stack',5);out(['ok'=>true,'document_id'=>$doc,'status'=>'queued','message'=>'Document received. BGE-M3 is starting on demand to process RAG.']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(isset($dest)&&is_file($dest))@unlink($dest);out(['error'=>$e->getMessage()],500);}
}
if($action==='bge_control'){
 require_csrf();$cmd=(string)($_POST['command']??'');
 $map=['start'=>'start-bge','stop'=>'stop-bge','restart'=>'restart-bge'];
 if(!isset($map[$cmd]))out(['error'=>'Invalid BGE command. Supported commands: start, stop, restart.'],422);
 $requestPath=LAC_ROOT.'/storage/llama-manager/request.json';
 if(!is_dir(dirname($requestPath))&&!mkdir(dirname($requestPath),0775,true)&&!is_dir(dirname($requestPath)))out(['error'=>'Could not create the Manager queue directory.'],500);
 $fp=@fopen($requestPath,'x');if(!$fp)out(['error'=>'Manager is processing another command. Wait for it to finish, then try again.'],409);
 $req=['id'=>bin2hex(random_bytes(8)),'created_at'=>date(DATE_ATOM),'action'=>$map[$cmd]];
 $json=json_encode($req,JSON_UNESCAPED_UNICODE);$written=fwrite($fp,$json===false?'':$json);fclose($fp);
 if(!$written){@unlink($requestPath);out(['error'=>'Could not write the BGE control request.'],500);}
 out(['ok'=>true,'command'=>$cmd,'message'=>$cmd==='stop'?'BGE-M3 stop requested. Pending RAG tasks will remain queued and resume automatically when BGE starts again.':'BGE-M3 start request sent.']);
}
if($action==='infrastructure_status'){
 $path='C:/AI/local-ai-chat-manager/status.json';
 if(!is_file($path))out(['installed'=>false,'message'=>'Unified Manager status was not found.']);
 $raw=file_get_contents($path);$raw=preg_replace('/^\xEF\xBB\xBF/','',(string)$raw);$data=json_decode((string)$raw,true);
 if(!is_array($data))out(['installed'=>true,'error'=>'Invalid Manager status file.'],500);
 // The JSON file is only a heartbeat/cache. Verify live Windows listeners independently.
 $ps=<<<'PS'
$ErrorActionPreference='SilentlyContinue'
$ports=@{qwen=8080;bge=8081;qdrant=6333;mariadb=3306;apache=80}
$out=@{manager_pid=$null;services=@{}}
$all=Get-CimInstance Win32_Process
$mgr=$all | Where-Object { $_.CommandLine -match 'llama-manager-watch\.ps1' } | Select-Object -First 1
if($mgr){$out.manager_pid=[int]$mgr.ProcessId}
foreach($key in $ports.Keys){
 $port=[int]$ports[$key]
 $listener=Get-NetTCPConnection -LocalPort $port -State Listen | Select-Object -First 1
 $pidValue=if($listener){[int]$listener.OwningProcess}else{$null}
 $proc=if($pidValue){$all|Where-Object{[int]$_.ProcessId -eq $pidValue}|Select-Object -First 1}else{$null}
 $out.services[$key]=@{running=!!$listener;pid=$pidValue;name=$(if($proc){$proc.Name}else{$null});path=$(if($proc){$proc.ExecutablePath}else{$null})}
}
$worker=$all|Where-Object{$_.CommandLine -match 'rag-worker\.php'}|Select-Object -First 1
$out.services.ragworker=@{running=!!$worker;pid=$(if($worker){[int]$worker.ProcessId}else{$null});name=$(if($worker){$worker.Name}else{$null});path=$(if($worker){$worker.ExecutablePath}else{$null})}
$out|ConvertTo-Json -Depth 5 -Compress
PS;
 $encoded=base64_encode(mb_convert_encoding($ps,'UTF-16LE','UTF-8'));
 $cmd='powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '.$encoded;
 $liveRaw=function_exists('shell_exec')?@shell_exec($cmd):null;
 $live=is_string($liveRaw)?json_decode(trim($liveRaw),true):null;
 $updated=strtotime((string)($data['updated_at']??''));$heartbeatFresh=$updated!==false&&$updated>0&&(time()-$updated)<=15;
 $data['installed']=true;
 $data['manager_online']=is_array($live)&&!empty($live['manager_pid']);
 $data['manager_pid']=$data['manager_online']?(int)$live['manager_pid']:null;
 $data['heartbeat_fresh']=$heartbeatFresh;
 if(is_array($live)&&isset($live['services'])){
  foreach($live['services'] as $key=>$v){
   if(!isset($data['services'][$key]))$data['services'][$key]=[];
   $old=$data['services'][$key];
   $running=!empty($v['running']);
   $healthy=false;
   if($running){
    if($key==='qwen')$url='http://127.0.0.1:8080/health';
    elseif($key==='bge')$url='http://127.0.0.1:8081/health';
    elseif($key==='qdrant')$url='http://127.0.0.1:6333/readyz';
    elseif($key==='apache')$url='http://127.0.0.1/local_ai_chat/';
    else $url='';
    if($url!==''){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$healthy=$body!==false&&$code>=200&&$code<300;}
    elseif($key==='mariadb')$healthy=((int)($v['pid']??0)>0);
    else $healthy=true;
   }
   $data['services'][$key]=array_merge($old,['running'=>$running,'healthy'=>$healthy,'pid'=>$v['pid']??null,'owner'=>($data['manager_online']&&($old['owner']??'')==='manager')?'manager':($running?'external':'none'),'detail'=>$running?($healthy?'Ready':'Running, health check failed'):'Not running']);
  }
 }
 if(!$data['manager_online'])$data['message']='Manager is offline. Service states below are detected directly from Windows, not from the stale status file.';
 else $data['message']='Live service status verified from Windows.';
 out($data);
}
if($action==='infrastructure_control'){
 require_csrf();$cmd=(string)($_POST['command']??'');if(!in_array($cmd,['start-all','stop-all','restart-all'],true))out(['error'=>'Invalid infrastructure command.'],422);
 $requestPath=LAC_ROOT.'/storage/llama-manager/request.json';if(!is_dir(dirname($requestPath))&&!mkdir(dirname($requestPath),0775,true)&&!is_dir(dirname($requestPath)))out(['error'=>'Could not create the queue directory.'],500);
 $fp=@fopen($requestPath,'x');if(!$fp)out(['error'=>'Another command is pending. Wait for Manager to finish, then try again.'],409);
 $req=['id'=>bin2hex(random_bytes(8)),'created_at'=>date(DATE_ATOM),'action'=>$cmd,'model'=>trim(setting($pdo,'model',$config['ai']['model'])),'context'=>(int)setting($pdo,'context_size','16384'),'gpu_layers'=>(int)setting($pdo,'gpu_layers','10')];$written=fwrite($fp,json_encode($req,JSON_UNESCAPED_UNICODE));fclose($fp);if($written===false){@unlink($requestPath);out(['error'=>'Could not write the control request.'],500);}out(['ok'=>true,'message'=>'Queued command '.$cmd.' in the Unified Manager queue.']);
}if($action==='server_control'){
 require_csrf();$cmd=(string)($_POST['command']??'');if(!in_array($cmd,['start','stop','restart'],true))out(['error'=>'Invalid command'],422);
 $model=trim(setting($pdo,'model',$config['ai']['model']));
 if(in_array($cmd,['start','restart'],true)&&$model==='')out(['error'=>'No model selected. Open Settings, scan for models, select a model, and save before starting.'],422);
 $requestPath=LAC_ROOT.'/storage/llama-manager/request.json';if(!is_dir(dirname($requestPath))&&!mkdir(dirname($requestPath),0775,true)&&!is_dir(dirname($requestPath)))out(['error'=>'Could not create the queue directory.'],500);
 // Exclusive create prevents simultaneous HTTP requests from overwriting one another.
 $fp=@fopen($requestPath,'x');if(!$fp)out(['error'=>'Another command is pending. If this persists, check the queue and Llama Manager status.'],409);
 $req=['id'=>bin2hex(random_bytes(8)),'created_at'=>date(DATE_ATOM),'action'=>$cmd,'port'=>8080,'model'=>$model,'context'=>(int)setting($pdo,'context_size','16384'),'gpu_layers'=>(int)setting($pdo,'gpu_layers','10')];
 $written=fwrite($fp,json_encode($req,JSON_UNESCAPED_UNICODE));fclose($fp);if($written===false){@unlink($requestPath);out(['error'=>'Could not write the request queue.'],500);}out(['ok'=>true,'message'=>'Command sent: '.$cmd.' to Local AI Chat Manager.']);
}
if($action==='rag_vector_rebuild'){
 require_csrf();$q=$pdo->prepare("INSERT INTO vector_outbox(canonical_chunk_id,action,status) SELECT id,'upsert','pending' FROM canonical_chunks");$q->execute();out(['ok'=>true,'queued'=>$q->rowCount(),'message'=>'Queued vector resynchronization for all canonical chunks.']);
}
if($action==='document_retry'){require_csrf();$id=(int)($_POST['id']??0);$q=$pdo->prepare("UPDATE document_ingest_jobs SET status='queued',last_error=NULL,completed_at=NULL WHERE document_id=? AND status='failed'");$q->execute([$id]);if(!$q->rowCount())out(['error'=>'Document is not in a failed state or does not exist.'],409);out(['ok'=>true]);}
if($action==='document_reindex'){
 require_csrf();$id=(int)($_POST['id']??0);if($id<1)out(['error'=>'Invalid document ID.'],422);
 $q=$pdo->prepare('SELECT id FROM rag_documents WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())out(['error'=>'Document not found.'],404);
 $q=$pdo->prepare("UPDATE document_ingest_jobs SET status='queued',last_error=NULL,completed_at=NULL,started_at=NULL WHERE document_id=?");$q->execute([$id]);
 if(!$q->rowCount()){ $q=$pdo->prepare("INSERT INTO document_ingest_jobs(document_id,status) VALUES(?,'queued') ON DUPLICATE KEY UPDATE status='queued',last_error=NULL,completed_at=NULL,started_at=NULL");$q->execute([$id]); }
 out(['ok'=>true,'message'=>'Document queued for chunking and re-indexing.']);
}
if($action==='documents'){
 $items=$pdo->query("SELECT d.id,d.name,d.size_bytes,d.created_at,COALESCE(j.status,'queued') status,j.last_error,(SELECT COUNT(*) FROM rag_document_chunk_map m WHERE m.document_id=d.id) chunks,(SELECT COUNT(DISTINCT m.canonical_chunk_id) FROM rag_document_chunk_map m JOIN vector_outbox v ON v.canonical_chunk_id=m.canonical_chunk_id WHERE m.document_id=d.id AND v.action='upsert' AND v.status IN ('pending','processing')) pending_vectors FROM rag_documents d LEFT JOIN document_ingest_jobs j ON j.document_id=d.id ORDER BY d.id DESC")->fetchAll();
 $counts=$pdo->query("SELECT status,COUNT(*) total FROM document_ingest_jobs GROUP BY status")->fetchAll();
 $outbox=$pdo->query("SELECT status,COUNT(*) total FROM vector_outbox GROUP BY status")->fetchAll();
 $chunkCount=(int)$pdo->query("SELECT COUNT(*) FROM canonical_chunks")->fetchColumn();
 out(['items'=>$items,'summary'=>['chunks'=>$chunkCount,'jobs'=>$counts,'outbox'=>$outbox]]);
}
if($action==='document_delete'){
 require_csrf();$id=(int)($_POST['id']??0);$pdo->beginTransaction();try{$s=$pdo->prepare('SELECT file_hash FROM rag_documents WHERE id=? FOR UPDATE');$s->execute([$id]);$hash=$s->fetchColumn();if($hash===false){$pdo->rollBack();out(['error'=>'Document not found.'],404);}$m=$pdo->prepare('SELECT canonical_chunk_id FROM rag_document_chunk_map WHERE document_id=?');$m->execute([$id]);$ids=array_values(array_unique(array_map('intval',$m->fetchAll(PDO::FETCH_COLUMN))));$pdo->prepare('DELETE FROM rag_documents WHERE id=?')->execute([$id]);foreach($ids as $cid){$c=$pdo->prepare('SELECT COUNT(*) FROM rag_document_chunk_map WHERE canonical_chunk_id=?');$c->execute([$cid]);if((int)$c->fetchColumn()===0)$pdo->prepare("INSERT INTO vector_outbox(canonical_chunk_id,action,status) VALUES(?,'delete','pending')")->execute([$cid]);}$pdo->commit();$path=LAC_ROOT.'/storage/uploads/'.$id.'-'.bin2hex($hash);if(is_file($path))@unlink($path);out(['ok'=>true]);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>$e->getMessage()],500);}}
