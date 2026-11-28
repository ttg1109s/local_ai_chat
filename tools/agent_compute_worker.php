<?php
declare(strict_types=1);
require_once __DIR__.'/../app/agent/tool_executor.php';
require_once __DIR__.'/../app/agent/execution_store.php';
final class LACComputeWorkerPDO extends PDO { public function __construct() {} }
$raw=stream_get_contents(STDIN,2*1024*1024+1);
if(strlen($raw)>2*1024*1024){fwrite(STDOUT,json_encode(['ok'=>false,'error_code'=>'INPUT_TOO_LARGE']));exit(2);}
$job=json_decode($raw,true);
if(!is_array($job)){fwrite(STDOUT,json_encode(['ok'=>false,'error_code'=>'INVALID_JOB']));exit(2);}
try {
 $result=lac_tool_execute_managed(new LACComputeWorkerPDO(),(string)($job['tool']??''),(array)($job['arguments']??[]),(array)($job['permissions']??[]),(array)($job['context']??[]),(string)($job['tool_call_id']??''));
 fwrite(STDOUT,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
} catch(Throwable $e) {
 fwrite(STDOUT,json_encode(['ok'=>false,'error_code'=>'WORKER_EXCEPTION','error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
}
