<?php
/** PHP-owned, bounded JSON bridge to Python. No arbitrary code execution is exposed to the model. */
function lac_python_find_command(): array {
 $configured=trim((string)(getenv('LOCAL_AI_PYTHON')?:''));
 if($configured!==''){
  if(is_file($configured))return [$configured];
  // Permit an executable name resolved through PATH, but never split arbitrary shell text.
  if(preg_match('/^[A-Za-z0-9_.-]+$/',$configured))return [$configured];
  throw new RuntimeException('LOCAL_AI_PYTHON must be a valid interpreter path or executable name.');
 }
 if(PHP_OS_FAMILY==='Windows'){
  $candidates=[getenv('LOCALAPPDATA')?rtrim((string)getenv('LOCALAPPDATA'),'\\/').'\\Programs\\Python\\Python313\\python.exe':'',getenv('LOCALAPPDATA')?rtrim((string)getenv('LOCALAPPDATA'),'\\/').'\\Programs\\Python\\Python312\\python.exe':'','C:\\Python313\\python.exe','C:\\Python312\\python.exe'];
  foreach($candidates as $candidate)if($candidate!==''&&is_file($candidate))return [$candidate];
  return ['python'];
 }
 return ['python3'];
}
function lac_python_runtime(array $payload,int $timeout=60,array $context=[]): array {
 $projectRoot=dirname(__DIR__);$worker=$projectRoot.DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'python_worker.py';
 if(!is_file($worker))throw new RuntimeException('Could not find Python Worker.');
 $timeout=max(2,min(300,$timeout));$command=array_merge(lac_python_find_command(),[$worker]);
 $outputDir=$projectRoot.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'python_outputs';
 if(!is_dir($outputDir)&&!@mkdir($outputDir,0775,true)&&!is_dir($outputDir))throw new RuntimeException('Could not create the Python output directory.');
 $payload['output_dir']=$outputDir;$payload['request_id']=bin2hex(random_bytes(8));
 $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR);
 if($json===false)throw new RuntimeException('Could not encode data sent to Python.');
 if(strlen($json)>2*1024*1024)throw new RuntimeException('Data sent to Python exceeds the 2 MB limit.');
 $descriptors=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
 $process=@proc_open($command,$descriptors,$pipes,$projectRoot,null,['bypass_shell'=>true]);
 if(!is_resource($process))throw new RuntimeException('PHP could not start Python. Check LOCAL_AI_PYTHON and execution permissions.');
 fwrite($pipes[0],$json);fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
 $stdout='';$stderr='';$started=microtime(true);$timedOut=false;$overflow=false;$exitCode=null;
 do{
  $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);
  if(strlen($stdout)>1024*1024||strlen($stderr)>256*1024){$overflow=true;@proc_terminate($process);break;}
  $status=proc_get_status($process);
  if(!$status['running']){$exitCode=(int)$status['exitcode'];break;}
  if(microtime(true)-$started>$timeout){$timedOut=true;@proc_terminate($process);usleep(100000);$s=proc_get_status($process);if(!empty($s['running']))@proc_terminate($process,9);break;}
  foreach($context['stop_files']??[] as $stopFile){if($stopFile&&is_file($stopFile)){@proc_terminate($process);fclose($pipes[1]);fclose($pipes[2]);@proc_close($process);throw new RuntimeException('LAC_AGENT_STOPPED');}}
  usleep(30000);
 }while(true);
 $stdout.=stream_get_contents($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$closeCode=proc_close($process);
 if($timedOut)throw new RuntimeException('Python Worker exceeded the time limit ('.$timeout.' seconds).');
 if($overflow)throw new RuntimeException('Python Worker output exceeds the safety limit.');
 if($exitCode===null||$exitCode<0)$exitCode=$closeCode;
 if($exitCode!==0)throw new RuntimeException('Python Worker failed (exit '.$exitCode.'): '.mb_substr(trim($stderr."\n".$stdout),0,2000));
 $decoded=json_decode(trim($stdout),true);
 if(!is_array($decoded)||!array_key_exists('success',$decoded))throw new RuntimeException('Python Worker returned invalid JSON: '.mb_substr($stdout,0,1000));
 if(empty($decoded['success']))throw new RuntimeException((string)($decoded['error']??'Python processing failed.'));
 return $decoded['result']??[];
}
