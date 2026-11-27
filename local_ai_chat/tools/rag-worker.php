<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require __DIR__.'/../app/bootstrap.php';require __DIR__.'/../app/rag_engine.php';
$once=in_array('--once',$argv??[],true);$sleep=5;$idleSeconds=60;
$embeddingUrl=rtrim(setting($pdo,'embedding_endpoint','http://127.0.0.1:8081/v1/embeddings'),'/');
$healthUrl=preg_replace('~/v1/embeddings$~','/health',$embeddingUrl);
$log=__DIR__.'/../storage/rag-worker.log';$activityFile=__DIR__.'/../storage/bge-activity.touch';
function worker_log(string $message,bool $error=false): void {global $log;$line='['.date('c').'] '.$message.PHP_EOL;file_put_contents($log,$line,FILE_APPEND|LOCK_EX);fwrite($error?STDERR:STDOUT,$line);}
function worker_embedding_ready(string $url): bool {$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return $body!==false&&$code>=200&&$code<300;}
function worker_qdrant_ready(): bool { $ch=curl_init('http://127.0.0.1:6333/readyz');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>3]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return $body!==false&&$code>=200&&$code<300; }
function worker_touch_activity(): void {global $activityFile;@file_put_contents($activityFile,(string)time(),LOCK_EX);}
function worker_request_manager(string $command): bool {
 $path=__DIR__.'/../storage/llama-manager/request.json';$dir=dirname($path);
 if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return false;
 $fp=@fopen($path,'x');if(!$fp)return false;
 $req=['id'=>bin2hex(random_bytes(8)),'created_at'=>date(DATE_ATOM),'action'=>$command];
 $json=json_encode($req,JSON_UNESCAPED_UNICODE);$written=$json!==false?fwrite($fp,$json):false;fflush($fp);fclose($fp);
 if($written===false){@unlink($path);return false;}return true;
}
function worker_has_pending_work(PDO $pdo): bool {
 $jobs=(int)$pdo->query("SELECT COUNT(*) FROM document_ingest_jobs WHERE status IN ('queued','extracting','chunking')")->fetchColumn();
 $vectors=(int)$pdo->query("SELECT COUNT(*) FROM vector_outbox WHERE status IN ('pending','processing') OR (status='failed' AND available_at<=NOW())")->fetchColumn();
 return ($jobs+$vectors)>0;
}
function worker_bge_is_manually_pinned(): bool {
 $path='C:/AI/local-ai-chat-manager/manager-settings.json';if(!is_file($path))return false;
 $raw=@file_get_contents($path);$cfg=json_decode((string)$raw,true);return is_array($cfg)&&!empty($cfg['bge_enabled']);
}
worker_log('Local AI RAG worker started (RAG stack on-demand; idle shutdown: 300 seconds).');
$lastEnsureRequest=0;$lastStopRequest=0;$lastWorkAt=time();$workerLease='worker:'.getmypid();
do {
  try {
    $pending=worker_has_pending_work($pdo);
    $ready=worker_embedding_ready($healthUrl)&&worker_qdrant_ready();
    if($pending&&!$ready){
      if(time()-$lastEnsureRequest>=10){if(worker_request_manager('ensure-rag-stack'))worker_log('Pending RAG work detected; requested on-demand BGE-M3 startup.');$lastEnsureRequest=time();}
      if(!$once)sleep($sleep);continue;
    }
    if($ready&&$pending){
      rag_lease_acquire($pdo,$workerLease,'worker',7200);
      worker_touch_activity();$lastWorkAt=time();
      $pdo->exec("UPDATE document_ingest_jobs SET status='queued',last_error='Worker recovered an interrupted task',started_at=NULL WHERE status IN ('extracting','chunking') AND updated_at < DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
      $q=$pdo->query("SELECT document_id FROM document_ingest_jobs WHERE status='queued' ORDER BY created_at LIMIT 3");
      foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){try{rag_process_document($pdo,(int)$id);worker_log("Ingested document #$id into canonical chunks; vector sync queued.");worker_touch_activity();$lastWorkAt=time();}catch(Throwable $e){worker_log("Ingest #$id failed: {$e->getMessage()}",true);}}
      rag_lease_renew($pdo,$workerLease,7200);
      $n=rag_process_outbox($pdo,10);if($n){worker_log("Vector outbox operations completed: $n");worker_touch_activity();$lastWorkAt=time();}
      // Refresh queue state after this batch. The next loop handles any remaining jobs.
      $pending=worker_has_pending_work($pdo);
      if(!$pending)rag_lease_release($pdo,$workerLease);else rag_lease_renew($pdo,$workerLease,7200);
    }
    if($ready&&!$pending&&!worker_bge_is_manually_pinned()&&!rag_has_active_leases($pdo)){
      // Worker lease is released above only after the full ingest + vector batch is complete.
      $activityAt=is_file($activityFile)?(int)@file_get_contents($activityFile):$lastWorkAt;
      if($activityAt<1)$activityAt=$lastWorkAt;
      $idleFor=max(0,time()-max($activityAt,$lastWorkAt));
      if($idleFor >= $idleSeconds && time()-$lastStopRequest>=30){
        if(worker_request_manager('stop-rag-stack')){worker_log('No ingest/vector/chat activity for 300 seconds; requested BGE-M3 and Qdrant shutdown.');$lastStopRequest=time();}
      }
    }
  }catch(Throwable $e){worker_log($e->getMessage(),true);try{if(!worker_has_pending_work($pdo))rag_lease_release($pdo,$workerLease);else rag_lease_renew($pdo,$workerLease,7200);}catch(Throwable $ignored){}}
  if(!$once)sleep($sleep);
} while(!$once);
