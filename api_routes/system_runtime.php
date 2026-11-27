<?php
/** API route family: system runtime */
require_once LAC_ROOT.'/app/agent/artifact_manager.php';
require_once LAC_ROOT.'/app/agent/attachment_manager.php';
// Python chart lifecycle: only touch generated chart_<32 hex>.jpg files (and legacy PNGs).
function lac_python_chart_files(string $content): array {
 preg_match_all('/chart_[a-f0-9]{32}\.(?:jpg|png)/i',$content,$matches);
 return array_values(array_unique(array_map('strtolower',$matches[0]??[])));
}
function lac_cleanup_unreferenced_python_charts(PDO $pdo,array $files): array {
 $deleted=[];$kept=[];$check=$pdo->prepare('SELECT 1 FROM messages WHERE LOCATE(?,content)>0 LIMIT 1');
 foreach(array_unique($files) as $file){
  if(!preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',$file))continue;
  $check->execute([$file]);
  if($check->fetchColumn()){$kept[]=$file;continue;}
  $refQ=$pdo->prepare('SELECT DISTINCT c.id,c.password_hash FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE m.content LIKE ?');
 $refQ->execute(['%'.$file.'%']);$refs=$refQ->fetchAll();
 if(!$refs){http_response_code(404);exit;}
 $unlockedIds=array_map('intval',$_SESSION['conversation_unlocks']??[]);
 foreach($refs as $ref){if(!empty($ref['password_hash'])&&!in_array((int)$ref['id'],$unlockedIds,true)){http_response_code(423);exit;}}
 $path=LAC_ROOT.'/storage/python_outputs/'.$file;
  if(is_file($path)){if(@unlink($path))$deleted[]=$file;else error_log('Python chart cleanup failed: '.$file);}
 }
 return ['deleted'=>$deleted,'kept'=>$kept];
}

// Serve generated JPG charts and legacy PNG charts from the private Python output directory.
if($action==='python_output_delete'){
 require_csrf();
 $file=strtolower((string)($_POST['file']??''));$messageId=(int)($_POST['message_id']??0);
 if(!preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',$file))out(['error'=>'Invalid chart filename.'],422);
 if($messageId<1)out(['error'=>'message_id is missing. Reload the page and try again.'],422);
 $q=$pdo->prepare('SELECT content,conversation_id FROM messages WHERE id=?');$q->execute([$messageId]);$messageRow=$q->fetch();$content=$messageRow['content']??false;
 if($content!==false)lac_require_conversation_unlocked($pdo,(int)$messageRow['conversation_id']);
 if($content===false)out(['error'=>'Message containing the chart was not found.'],404);
 // Remove this chart reference from the selected message first. A shared chart remains
 // on disk while any other message still references the same generated filename.
 $quoted=preg_quote($file,'/');
 $updated=preg_replace('/!?\[[^\]]*\]\([^)]*'.$quoted.'[^)]*\)/i','',(string)$content);
 $updated=preg_replace('/(?:https?:\/\/|api\.php\?action=python_output)[^\s<>)]*'.$quoted.'/i','',$updated??(string)$content);
 if($updated!==(string)$content){$u=$pdo->prepare('UPDATE messages SET content=? WHERE id=?');$u->execute([$updated,$messageId]);}
 $result=lac_cleanup_unreferenced_python_charts($pdo,[$file]);
 out(['ok'=>true,'deleted'=>in_array($file,$result['deleted'],true),'still_referenced'=>in_array($file,$result['kept'],true)]);
}
if($action==='artifact_image'){
 $artifactId=(string)($_GET['id']??'');
 if(!preg_match('/^art_[a-f0-9]{24}$/',$artifactId)){http_response_code(404);exit;}
 $resolved=lac_artifact_find_by_id($artifactId);$artifact=is_array($resolved['artifact']??null)?$resolved['artifact']:null;
 if(!$artifact){http_response_code(404);exit;}
 $conversationId=(int)($resolved['meta']['conversation_id']??0);
 if($conversationId<1){http_response_code(404);exit;}
 lac_require_conversation_unlocked($pdo,$conversationId);
 $path=lac_artifact_file_path((string)$artifact['filename']);
 if(!$path||!is_file($path)||is_link($path)){http_response_code(404);exit;}
 $info=@getimagesize($path);$mime=(string)($info['mime']??'');
 if(!in_array($mime,['image/jpeg','image/png'],true)||$mime!==(string)$artifact['mime_type']){http_response_code(404);exit;}
 $size=(int)(@filesize($path)?:0);if($size<1||$size>25*1024*1024){http_response_code(404);exit;}
 header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Disposition: inline; filename="image"');header('Content-Length: '.$size);readfile($path);exit;
}
if($action==='artifact_delete'){
 require_csrf();
 $artifactId=(string)($_POST['artifact_id']??'');if(!preg_match('/^art_[a-f0-9]{24}$/',$artifactId))out(['error'=>'Invalid artifact ID'],422);
 $resolved=lac_artifact_find_by_id($artifactId);if(!$resolved)out(['ok'=>true,'deleted'=>false]);
 $conversationId=(int)($resolved['meta']['conversation_id']??0);if($conversationId<1)out(['error'=>'Artifact owner is invalid'],404);
 lac_require_conversation_unlocked($pdo,$conversationId);
 try{$result=lac_artifact_delete_by_id($artifactId,$conversationId);lac_attachment_cleanup_artifact((string)$conversationId,$artifactId);out(['ok'=>true]+$result);}catch(Throwable $e){out(['error'=>'Artifact could not be deleted'],409);}
}

if($action==='python_output'){
 $file=(string)($_GET['file']??'');
 if(!preg_match('/^chart_[a-f0-9]{32}\.(?:jpg|png)$/D',$file)){http_response_code(404);exit;}
 $path=LAC_ROOT.'/storage/python_outputs/'.$file;
 if(!is_file($path)||is_link($path)){http_response_code(404);exit;}
 $size=@filesize($path);if(!$size||$size>25*1024*1024){http_response_code(404);exit;}
 $expectedMime=str_ends_with(strtolower($file), '.jpg')?'image/jpeg':'image/png';
 $imageInfo=@getimagesize($path);if(!is_array($imageInfo)||($imageInfo['mime']??'')!==$expectedMime){http_response_code(404);exit;}
 header('Content-Type: '.$expectedMime);
 header('X-Content-Type-Options: nosniff');
 header('Cache-Control: private, max-age=3600');
 header('Content-Disposition: inline; filename="'.$file.'"');
 header('Content-Length: '.filesize($path));
 readfile($path);exit;
}


/** Normalize a model identifier so aliases survive a transition from a Hugging Face
 * repository ID to the corresponding local GGUF filename. */
function lac_find_model_alias(PDO $pdo,string $model): string {
 // Aliases are keyed only by the exact model ID (full GGUF filename).
 // Never inherit aliases from Hugging Face IDs, paths, or normalized filenames.
 $q=$pdo->prepare('SELECT alias FROM model_aliases WHERE model_id=? LIMIT 1');
 $q->execute([trim($model)]);
 return trim((string)($q->fetchColumn()?:''));
}

/**
 * BGE-M3 on-demand lifecycle helpers.
 * The Manager remains the only component allowed to start/stop llama-server.
 */
function lac_bge_health(string $url='http://127.0.0.1:8081/health', int $timeout=2): bool {
 $ch=@curl_init($url);if(!$ch)return false;
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_TIMEOUT=>$timeout]);
 $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 return $body!==false&&$code>=200&&$code<300;
}
function lac_qdrant_health(string $url='http://127.0.0.1:6333/readyz', int $timeout=2): bool {
 $ch=@curl_init($url);if(!$ch)return false;
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_TIMEOUT=>$timeout]);
 $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 return $body!==false&&$code>=200&&$code<300;
}
function lac_bge_mark_activity(): void {
 $dir=LAC_ROOT.'/storage';if(!is_dir($dir))@mkdir($dir,0775,true);
 @file_put_contents($dir.'/bge-activity.touch',(string)time(),LOCK_EX);
}
function lac_manager_request(string $command, int $waitSeconds=30): bool {
 $requestPath=LAC_ROOT.'/storage/llama-manager/request.json';
 $dir=dirname($requestPath);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return false;
 $deadline=microtime(true)+$waitSeconds;
 do {
  $fp=@fopen($requestPath,'x');
  if($fp){
   $req=['id'=>bin2hex(random_bytes(8)),'created_at'=>date(DATE_ATOM),'action'=>$command];
   $json=json_encode($req,JSON_UNESCAPED_UNICODE);
   $written=$json!==false?fwrite($fp,$json):false;fflush($fp);fclose($fp);
   if($written===false){@unlink($requestPath);return false;}
   return true;
  }
  usleep(250000);
 } while(microtime(true)<$deadline);
 return false;
}
function lac_bge_ensure_for_rag(PDO $pdo, int $timeoutSeconds=240): bool {
 @set_time_limit(max(300,(int)ini_get('max_execution_time')));
 // No canonical chunks means there is nothing for semantic retrieval to embed.
 try{$count=(int)$pdo->query('SELECT COUNT(*) FROM canonical_chunks')->fetchColumn();}catch(Throwable $e){return false;}
 if($count<1)return false;
 lac_bge_mark_activity();
 if(lac_bge_health()&&lac_qdrant_health())return true;
 if(!lac_manager_request('ensure-rag-stack',30)){
  // A concurrent Manager request may be in flight; still wait for its result.
  if(!(lac_bge_health()&&lac_qdrant_health()))error_log('RAG on-demand: could not enqueue ensure-rag-stack request.');
 }
 $deadline=microtime(true)+$timeoutSeconds;
 do {if(lac_bge_health('http://127.0.0.1:8081/health',3)&&lac_qdrant_health('http://127.0.0.1:6333/readyz',3)){lac_bge_mark_activity();return true;}usleep(1000000);}while(microtime(true)<$deadline);
 error_log('BGE on-demand: health endpoint did not become ready; RAG retrieval will continue with its available fallback.');
 return false;
}
if($action==='server_info'){
 // Host inventory is collected from the operating system, never from llama-server.
 $family=PHP_OS_FAMILY;$host=gethostname()?:php_uname('n');$resolvedIp=gethostbyname($host);$data=['hostname'=>$host,'local_ip'=>$resolvedIp!==$host?$resolvedIp:null,'os'=>['family'=>$family,'name'=>php_uname('s'),'release'=>php_uname('r'),'version'=>php_uname('v'),'architecture'=>php_uname('m')],'cpu'=>[],'memory'=>[],'gpus'=>[],'disks'=>[],'uptime_seconds'=>null];
 $runFixed=function(string $command):string{if(!function_exists('shell_exec'))return ''; $out=@shell_exec($command);return is_string($out)?trim($out):'';};
 $bytes=function($v):?int{return is_numeric($v)?(int)$v:null;};
 if($family==='Windows'){
  $ps=<<<'PS'
$ErrorActionPreference='SilentlyContinue'
$os=Get-CimInstance Win32_OperatingSystem
$cs=Get-CimInstance Win32_ComputerSystem
$cpus=@(Get-CimInstance Win32_Processor)
$cpu=$cpus | Select-Object -First 1
$coreSum=($cpus | Measure-Object -Property NumberOfCores -Sum).Sum
$threadSum=($cpus | Measure-Object -Property NumberOfLogicalProcessors -Sum).Sum
$gpu=Get-CimInstance Win32_VideoController | ForEach-Object { [pscustomobject]@{name=$_.Name;vendor=$_.AdapterCompatibility;vram_bytes=$_.AdapterRAM;driver=$_.DriverVersion} }
$drives=Get-CimInstance Win32_LogicalDisk -Filter 'DriveType=3' | ForEach-Object { [pscustomobject]@{name=$_.DeviceID;filesystem=$_.FileSystem;size_bytes=$_.Size;free_bytes=$_.FreeSpace} }
[pscustomobject]@{os_name=$os.Caption;os_version=$os.Version;os_build=$os.BuildNumber;os_arch=$os.OSArchitecture;boot=$os.LastBootUpTime.ToString('o');cpu_name=$cpu.Name;cpu_cores=$coreSum;cpu_threads=$threadSum;cpu_mhz=$cpu.MaxClockSpeed;ram_total=$cs.TotalPhysicalMemory;ram_free=$os.FreePhysicalMemory*1024;gpus=@($gpu);disks=@($drives)} | ConvertTo-Json -Depth 5 -Compress
PS;
  $encoded=function_exists('iconv')?base64_encode(iconv('UTF-8','UTF-16LE',$ps)):'';$raw=$encoded!==''?$runFixed('powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand '.$encoded):'';$w=json_decode($raw,true);
  if(is_array($w)){
   $data['os']=['family'=>'Windows','name'=>$w['os_name']??'Windows','release'=>$w['os_version']??null,'build'=>$w['os_build']??null,'architecture'=>$w['os_arch']??$data['os']['architecture']];
   $data['cpu']=['name'=>$w['cpu_name']??null,'physical_cores'=>$bytes($w['cpu_cores']??null),'logical_processors'=>$bytes($w['cpu_threads']??null),'max_clock_mhz'=>$bytes($w['cpu_mhz']??null)];
   $total=$bytes($w['ram_total']??null);$free=$bytes($w['ram_free']??null);$data['memory']=['total_bytes'=>$total,'available_bytes'=>$free,'used_bytes'=>$total!==null&&$free!==null?max(0,$total-$free):null];
   foreach(($w['gpus']??[]) as $g){$data['gpus'][]=['name'=>$g['name']??null,'vendor'=>$g['vendor']??null,'vram_total_bytes'=>$bytes($g['vram_bytes']??null),'driver'=>$g['driver']??null,'vram_usage'=>'No standardized data is available from WMI'];}
   foreach(($w['disks']??[]) as $d){$data['disks'][]=['name'=>$d['name']??null,'filesystem'=>$d['filesystem']??null,'total_bytes'=>$bytes($d['size_bytes']??null),'free_bytes'=>$bytes($d['free_bytes']??null),'used_bytes'=>isset($d['size_bytes'],$d['free_bytes'])?max(0,(int)$d['size_bytes']-(int)$d['free_bytes']):null];}
   $boot=$w['boot']??null;if($boot){$bt=strtotime((string)$boot);if($bt!==false)$data['uptime_seconds']=max(0,time()-$bt);}
  }else{$data['collection_warning']='PowerShell/CIM could not run, or the PHP account does not have permission to read system information.';}
  // NVIDIA provides reliable live VRAM usage through nvidia-smi when installed.
  $nv=$runFixed('nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free --format=csv,noheader,nounits 2>NUL');
  if($nv!==''){foreach(preg_split('/\r?\n/',$nv) as $line){$a=array_map('trim',explode(',',$line));if(count($a)>=4){$found=false;foreach($data['gpus'] as &$g){if(stripos((string)$g['name'],'nvidia')!==false||stripos((string)$a[0],'nvidia')!==false){$g['vram_total_bytes']=(int)$a[1]*1024*1024;$g['vram_used_bytes']=(int)$a[2]*1024*1024;$g['vram_free_bytes']=(int)$a[3]*1024*1024;$found=true;break;}}unset($g);if(!$found)$data['gpus'][]=['name'=>$a[0],'vendor'=>'NVIDIA','vram_total_bytes'=>(int)$a[1]*1048576,'vram_used_bytes'=>(int)$a[2]*1048576,'vram_free_bytes'=>(int)$a[3]*1048576];}}}
 }else{
  $osRelease=$runFixed('cat /etc/os-release 2>/dev/null');$osName=null;$osVersion=null;foreach(preg_split('/\r?\n/',$osRelease) as $line){if(str_starts_with($line,'PRETTY_NAME='))$osName=trim(substr($line,12),'"');if(str_starts_with($line,'VERSION_ID='))$osVersion=trim(substr($line,11),'"');}
  $data['os']=['family'=>$family,'name'=>$osName?:php_uname('s'),'release'=>$osVersion?:php_uname('r'),'architecture'=>php_uname('m')];
  $cpuinfo=@file_get_contents('/proc/cpuinfo')?:'';preg_match('/^model name\s*:\s*(.+)$/m',$cpuinfo,$cm);preg_match_all('/^processor\s*:/m',$cpuinfo,$threads);$cores=(int)$runFixed('getconf _NPROCESSORS_ONLN 2>/dev/null');$topology=$runFixed('lscpu -p=socket,core 2>/dev/null');$physical=[];foreach(preg_split('/\r?\n/',$topology) as $row){if($row!==''&&$row[0]!=='#')$physical[$row]=true;}$data['cpu']=['name'=>$cm[1]??null,'physical_cores'=>$physical?count($physical):null,'logical_processors'=>$cores?:count($threads[0]),'max_clock_mhz'=>null];
  $mem=@file_get_contents('/proc/meminfo')?:'';$mi=[];foreach(preg_split('/\r?\n/',$mem) as $line)if(preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/',$line,$mm))$mi[$mm[1]]=(int)$mm[2]*1024;$total=$mi['MemTotal']??null;$free=$mi['MemAvailable']??null;$data['memory']=['total_bytes'=>$total,'available_bytes'=>$free,'used_bytes'=>$total!==null&&$free!==null?max(0,$total-$free):null];
  $up=@file_get_contents('/proc/uptime');if($up!==false)$data['uptime_seconds']=(int)floatval(explode(' ',$up)[0]);
  $df=$runFixed('df -B1 -P -x tmpfs -x devtmpfs 2>/dev/null');foreach(array_slice(preg_split('/\r?\n/',$df),1) as $line){$a=preg_split('/\s+/',$line);if(count($a)>=6&&is_numeric($a[1]))$data['disks'][]=['name'=>$a[5],'filesystem'=>$a[0],'total_bytes'=>(int)$a[1],'used_bytes'=>(int)$a[2],'free_bytes'=>(int)$a[3]];}
  $nv=$runFixed('nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free --format=csv,noheader,nounits 2>/dev/null');if($nv!==''){foreach(preg_split('/\r?\n/',$nv) as $line){$a=array_map('trim',explode(',',$line));if(count($a)>=4)$data['gpus'][]=['name'=>$a[0],'vendor'=>'NVIDIA','vram_total_bytes'=>(int)$a[1]*1048576,'vram_used_bytes'=>(int)$a[2]*1048576,'vram_free_bytes'=>(int)$a[3]*1048576];}}
  if(!$data['gpus']){$lspci=$runFixed('lspci 2>/dev/null');foreach(preg_split('/\r?\n/',$lspci) as $line)if(preg_match('/VGA compatible controller|3D controller|Display controller/i',$line))$data['gpus'][]=['name'=>trim(preg_replace('/^[^:]+:\s*/','',$line)),'vendor'=>null,'vram_total_bytes'=>null,'note'=>'nvidia-smi is unavailable; VRAM may not be readable.'];}
 }
 $data['ok']=true;$data['source']='operating_system';$data['collected_at']=date(DATE_ATOM);$data['note']='Server hardware information. Used VRAM is available only when supported by the GPU driver/tools.';out($data);
}
if($action==='llama_info'){
 $modelsUrl=setting($pdo,'models_endpoint',$config['ai']['models_endpoint']);$parts=parse_url($modelsUrl);
 if(!$parts||empty($parts['scheme'])||empty($parts['host']))out(['ok'=>false,'error'=>'Invalid models endpoint'],422);
 $base=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
 $headers=['Accept: application/json'];$apiKey=setting($pdo,'llama_api_key','');if($apiKey!=='')$headers[]='Authorization: Bearer '.$apiKey;
 $read=function(string $url)use($headers){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers]);$raw=curl_exec($ch);$error=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return ['http'=>$code,'data'=>is_string($raw)?json_decode($raw,true):null,'error'=>$error?:null];};
 $health=$read($base.'/health');$props=$read($base.'/props');$models=$read(rtrim($modelsUrl,'/'));$p=$props['data']??[];$m=$models['data']['data']??[];$model=$m[0]??[];
 out(['ok'=>($health['http']===200),'server'=>['health_http'=>$health['http'],'health'=>$health['data']??null,'props_http'=>$props['http'],'model_list_http'=>$models['http'],'model_id'=>$model['id']??null,'model_created'=>$model['created']??null,'model_meta'=>$model['meta']??null,'model_path'=>$p['model_path']??null,'model_alias'=>$p['model_alias']??null,'context_size'=>$p['default_generation_settings']['n_ctx']??($p['n_ctx']??null),'total_slots'=>$p['total_slots']??null,'modalities'=>$p['modalities']??null,'build_info'=>$p['build_info']??null,'is_sleeping'=>$p['is_sleeping']??null,'props_error'=>$props['error']??null,'models_error'=>$models['error']??null]]);
}
if(in_array($action,['websites_save','website_test'],true)){
 require_csrf();
 require_once LAC_ROOT.'/app/website_connector.php';
 if($action==='websites_save'){
  $enabled='1'; // Website access is controlled per website, not by a global switch.
  $raw=(string)($_POST['websites']??'[]');$items=json_decode($raw,true);if(!is_array($items))out(['error'=>'Invalid website list.'],422);
  if(count($items)>30)out(['error'=>'A maximum of 30 websites is allowed.'],422);
  $clean=[];
  foreach($items as $item){if(!is_array($item))continue;$name=trim((string)($item['name']??''));$url=trim((string)($item['url']??''));$search=trim((string)($item['search_url']??''));if($name===''||$url==='')out(['error'=>'Each website requires a name and base URL.'],422);
   try{[$parts,$host]=wc_validate_url($url);}catch(Throwable $e){out(['error'=>'Website '.$name.': '.$e->getMessage()],422);}
   if($search!==''){if(!str_contains($search,'{query}'))out(['error'=>'Search URL must contain the {query} placeholder.'],422);$test=str_replace('{query}','probe', $search);try{[$sp,$sh]=wc_validate_url($test,$host);}catch(Throwable $e){out(['error'=>'Search URL for '.$name.' is invalid: '.$e->getMessage()],422);}}
   $clean[]=['id'=>preg_replace('/[^a-zA-Z0-9_-]/','',(string)($item['id']??bin2hex(random_bytes(5)))),'name'=>mb_substr($name,0,100),'url'=>$url,'search_url'=>$search,'enabled'=>!empty($item['enabled'])];
  }
  $save=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$save->execute(['websites_enabled',$enabled]);$save->execute(['websites_config',json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  out(['ok'=>true,'enabled'=>$enabled==='1','websites'=>$clean,'message'=>'Website configuration saved.']);
 }
 $id=trim((string)($_POST['id']??''));$query=trim((string)($_POST['query']??''));$sites=json_decode(setting($pdo,'websites_config','[]'),true);if(!is_array($sites))$sites=[];$site=null;foreach($sites as $v)if(($v['id']??'')===$id)$site=$v;
 if(!$site)out(['error'=>'Website was not found in the allowlist.'],404);
 try{$result=wc_site_read($site,mb_substr($query,0,180));out(['ok'=>true,'result'=>$result]);}catch(Throwable $e){out(['error'=>$e->getMessage()],502);}
}
/** Read GGUF metadata and tensor descriptors without loading model weights. */
function lac_gguf_read_exact($fp,int $length): string {
 if($length<0||$length>67108864)throw new RuntimeException('GGUF field length is outside the supported range.');
 $data='';while(strlen($data)<$length&&!feof($fp)){$part=fread($fp,$length-strlen($data));if($part===false||$part==='')break;$data.=$part;}
 if(strlen($data)!==$length)throw new RuntimeException('Unexpected end of GGUF file.');return $data;
}
function lac_gguf_u32($fp): int {return (int)(unpack('V',lac_gguf_read_exact($fp,4))[1]??0);}
function lac_gguf_u64($fp): int { $v=unpack('P',lac_gguf_read_exact($fp,8))[1]??0; if(!is_int($v)||$v<0)throw new RuntimeException('GGUF value exceeds supported integer range.');return $v; }
function lac_gguf_string($fp): string {$n=lac_gguf_u64($fp);if($n>1048576)throw new RuntimeException('GGUF string is too large.');return lac_gguf_read_exact($fp,$n);}
function lac_gguf_value($fp,int $type,int $depth=0) {
 if($depth>4)throw new RuntimeException('GGUF metadata array nesting is too deep.');
 switch($type){
  case 0:return ord(lac_gguf_read_exact($fp,1)); case 1:return unpack('c',lac_gguf_read_exact($fp,1))[1];
  case 2:return unpack('v',lac_gguf_read_exact($fp,2))[1]; case 3:return unpack('s',lac_gguf_read_exact($fp,2))[1];
  case 4:return lac_gguf_u32($fp); case 5:return unpack('l',lac_gguf_read_exact($fp,4))[1];
  case 6:return unpack('f',lac_gguf_read_exact($fp,4))[1]; case 7:return ord(lac_gguf_read_exact($fp,1))!==0;
  case 8:return lac_gguf_string($fp); case 10:return lac_gguf_u64($fp);
  case 11:$v=unpack('q',lac_gguf_read_exact($fp,8))[1];return $v;
  case 12:return unpack('d',lac_gguf_read_exact($fp,8))[1];
  case 9:$itemType=lac_gguf_u32($fp);$count=lac_gguf_u64($fp);if($count>100000)throw new RuntimeException('GGUF metadata array is too large.');$out=[];for($i=0;$i<$count;$i++)$out[]=lac_gguf_value($fp,$itemType,$depth+1);return $out;
  default:throw new RuntimeException('Unknown GGUF metadata value type '.$type.'.');
 }
}
function lac_gguf_skip_value($fp,int $type,int $depth=0): void {
 if($depth>4)throw new RuntimeException('GGUF metadata array nesting is too deep.');
 $sizes=[0=>1,1=>1,2=>2,3=>2,4=>4,5=>4,6=>4,7=>1,10=>8,11=>8,12=>8];
 if(isset($sizes[$type])){if(fseek($fp,$sizes[$type],SEEK_CUR)!==0)throw new RuntimeException('Cannot skip GGUF metadata value.');return;}
 if($type===8){$n=lac_gguf_u64($fp);if($n>PHP_INT_MAX||fseek($fp,(int)$n,SEEK_CUR)!==0)throw new RuntimeException('Cannot skip GGUF metadata string.');return;}
 if($type===9){$itemType=lac_gguf_u32($fp);$count=lac_gguf_u64($fp);if($count>2000000)throw new RuntimeException('GGUF metadata array is unreasonably large.');for($i=0;$i<$count;$i++)lac_gguf_skip_value($fp,$itemType,$depth+1);return;}
 throw new RuntimeException('Unknown GGUF metadata value type '.$type.'.');
}
function lac_gguf_parse(string $path): array {
 $fp=@fopen($path,'rb');if(!$fp)throw new RuntimeException('Cannot open GGUF file.');
 try{
  if(lac_gguf_read_exact($fp,4)!=='GGUF')throw new RuntimeException('File does not have GGUF magic.');
  $version=lac_gguf_u32($fp);if($version<2||$version>3)throw new RuntimeException('Unsupported GGUF version '.$version.'.');
  $tensorCount=lac_gguf_u64($fp);$kvCount=lac_gguf_u64($fp);if($kvCount>100000)throw new RuntimeException('GGUF metadata entry count is unreasonable.');
  $meta=[];$wanted=['general.name','general.architecture','general.file_type','general.quantization_version','general.parameter_count','general.alignment','tokenizer.ggml.model','tokenizer.chat_template'];
  for($i=0;$i<$kvCount;$i++){$key=lac_gguf_string($fp);$type=lac_gguf_u32($fp);$capture=in_array($key,$wanted,true)||preg_match('/^(?:[a-z0-9_]+)\.(?:context_length|block_count|embedding_length|feed_forward_length|attention\.head_count|attention\.head_count_kv|rope\.dimension_count)$/',$key);if($capture)$meta[$key]=lac_gguf_value($fp,$type);else lac_gguf_skip_value($fp,$type);}
  $arch=(string)($meta['general.architecture']??'');$tensorTypes=[];$sampleTensors=[];
  if($tensorCount>2000000)throw new RuntimeException('GGUF tensor count exceeds the safety limit.');
  for($i=0;$i<$tensorCount;$i++){$name=lac_gguf_string($fp);$dims=lac_gguf_u32($fp);if($dims>8)throw new RuntimeException('Invalid GGUF tensor dimensions.');$shape=[];for($d=0;$d<$dims;$d++)$shape[]=lac_gguf_u64($fp);$type=lac_gguf_u32($fp);lac_gguf_u64($fp);$tensorTypes[(string)$type]=($tensorTypes[(string)$type]??0)+1;if(count($sampleTensors)<8)$sampleTensors[]=['name'=>$name,'shape'=>$shape,'type'=>$type];}
  $get=function(string $suffix)use($meta,$arch){return $arch!==''?($meta[$arch.'.'.$suffix]??null):null;};
  $fileType=$meta['general.file_type']??null;$quantNames=[0=>'F32',1=>'F16',2=>'Q4_0',3=>'Q4_1',7=>'Q8_0',8=>'Q5_0',9=>'Q5_1',10=>'Q2_K',11=>'Q3_K_S',12=>'Q3_K_M',13=>'Q3_K_L',14=>'Q4_K_S',15=>'Q4_K_M',16=>'Q5_K_S',17=>'Q5_K_M',18=>'Q6_K'];return ['gguf_version'=>$version,'architecture'=>$arch?:null,'name'=>$meta['general.name']??null,'file_type'=>$fileType,'quantization_label'=>is_int($fileType)?($quantNames[$fileType]??('GGUF file type '.$fileType)):null,'quantization_version'=>$meta['general.quantization_version']??null,'parameter_count'=>$meta['general.parameter_count']??null,'file_size_bytes'=>(int)(@filesize($path)?:0),'context_length'=>$get('context_length'),'block_count'=>$get('block_count'),'embedding_length'=>$get('embedding_length'),'feed_forward_length'=>$get('feed_forward_length'),'attention_heads'=>$get('attention.head_count'),'attention_heads_kv'=>$get('attention.head_count_kv'),'rope_dimension_count'=>$get('rope.dimension_count'),'tokenizer_model'=>$meta['tokenizer.ggml.model']??null,'chat_template_present'=>!empty($meta['tokenizer.chat_template']),'tensor_count'=>$tensorCount,'tensor_type_counts'=>$tensorTypes,'tensor_samples'=>$sampleTensors,'metadata_keys'=>array_keys($meta)];
 }finally{fclose($fp);}
}
function lac_model_metadata(PDO $pdo,string $path): array {
 $size=(int)(@filesize($path)?:0);$mtime=(int)(@filemtime($path)?:0);$hash=hash('sha256',strtolower(str_replace('\\','/',$path)));
 $q=$pdo->prepare('SELECT file_size,file_mtime,scan_status,metadata_json,error_text FROM model_metadata WHERE path_hash=? LIMIT 1');$q->execute([$hash]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if($row&&(int)$row['file_size']===$size&&(int)$row['file_mtime']===$mtime&&in_array($row['scan_status'],['ready','error'],true)){$cached=json_decode((string)$row['metadata_json'],true);return ['status'=>$row['scan_status'],'metadata'=>is_array($cached)?$cached:null,'error'=>$row['error_text']?:null];}
 try{$metadata=lac_gguf_parse($path);$status='ready';$error=null;$json=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);}
 catch(Throwable $e){$metadata=null;$status='error';$error=mb_substr($e->getMessage(),0,1000);$json=null;error_log('GGUF metadata scan failed for '.$path.': '.$error);}
 $u=$pdo->prepare('INSERT INTO model_metadata(model_path,path_hash,file_size,file_mtime,scan_status,metadata_json,error_text,scanned_at) VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE model_path=VALUES(model_path),file_size=VALUES(file_size),file_mtime=VALUES(file_mtime),scan_status=VALUES(scan_status),metadata_json=VALUES(metadata_json),error_text=VALUES(error_text),scanned_at=NOW()');
 $u->execute([$path,$hash,$size,$mtime,$status,$json,$error]);return ['status'=>$status,'metadata'=>$metadata,'error'=>$error];
}

if($action==='models'){
 // Model discovery must not depend on a running llama-server. First collect
 // server-reported models when available, then scan configured and Hugging Face
 // cache directories for local GGUF files.
 $models=[];$seen=[];
 $addModel=function(string $id,string $source)use(&$models,&$seen):bool{
  $id=trim($id);if($id===''||preg_match('/bge[-_]?m3|embedding/i',$id))return false;
  $key=strtolower(str_replace('\\','/',$id));
  if(isset($seen[$key]))return false;
  $seen[$key]=true;$models[]=['id'=>$id,'source'=>$source];return true;
 };

 $url=setting($pdo,'models_endpoint',$config['ai']['models_endpoint']);
 $ch=@curl_init($url);$serverAvailable=false;
 if($ch){
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5]);
  $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  if($raw!==false&&$code>=200&&$code<300){
   $j=json_decode($raw,true);
   foreach(($j['data']??$j['models']??[]) as $m){
    $id=(string)($m['id']??$m['name']??'');
    if($id!=='')$addModel($id,'server');
   }
   $serverAvailable=true;
  }
 }

 // Chat discovery is intentionally limited to GGUF files placed directly
 // in the configured models root. RAG/embedding models belong in models/rag
 // and must never appear in the chat model picker. Hugging Face cache paths
 // are not scanned: the central model directory is the single source of truth.
 $configured=trim((string)setting($pdo,'model_dir',$config['model_dir']));
 if($configured!=='' && is_dir($configured)){
  try{
   foreach((glob(rtrim($configured,'/\\').DIRECTORY_SEPARATOR.'*.gguf')?:[]) as $path){
    if(!is_file($path)||filesize($path)<1)continue;
    $base=basename($path);

    // A split GGUF is one model. Register only shard 1; llama.cpp
    // automatically loads the remaining shards from the same directory.
    // Example: model-00001-of-00002.gguf is kept, model-00002-of-00002.gguf is skipped.
    if(preg_match('/-(\d{5})-of-(\d{5})\.gguf$/i',$base,$shard)){
     if((int)$shard[1]!==1)continue;
    }

    if(preg_match('/bge[-_]?m3|embedding/i',$base))continue;
    $metaResult=lac_model_metadata($pdo,$path);$added=$addModel($path,'local');$modelIndex=$added?count($models)-1:null;if($modelIndex===null){$key=strtolower(str_replace('\\','/',$path));foreach($models as $idx=>$existing){if(strtolower(str_replace('\\','/',(string)$existing['id']))===$key){$modelIndex=$idx;break;}}}if($modelIndex!==null){$models[$modelIndex]['source']='local';$models[$modelIndex]['metadata_status']=$metaResult['status'];$models[$modelIndex]['metadata']=$metaResult['metadata'];$models[$modelIndex]['metadata_error']=$metaResult['error'];}
   }
  }catch(Throwable $e){error_log('Local model scan failed for '.$configured.': '.$e->getMessage());}
 }
 out([
  'models'=>$models,
  'server_available'=>$serverAvailable,
  'local_scan_roots'=>($configured!==''&&is_dir($configured)?[$configured]:[])
 ]);
}
if($action==='chat_model_switch'){
 require_csrf();$model=trim((string)($_POST['model']??''));
 if($model===''||preg_match('/bge[-_]?m3|embedding/i',$model))out(['error'=>'Chat model is invalid or is an embedding model.'],422);
 $modelsUrl=setting($pdo,'models_endpoint',$config['ai']['models_endpoint']);$parts=parse_url($modelsUrl);$base=($parts['scheme']??'http').'://'.($parts['host']??'127.0.0.1').(isset($parts['port'])?':'.$parts['port']:'');
 $read=function($url){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>4]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return ($raw!==false&&$code===200)?json_decode($raw,true):null;};
 $props=$read($base.'/props');$models=$read(rtrim($modelsUrl,'/'));$data=$models['data']??[];$running=(string)($data['data'][0]['id']??'');$path=(string)($props['model_path']??'');$alias=(string)($props['model_alias']??'');
 if($running===$model||$alias===$model||$path===$model){
  $params=lac_get_model_config($pdo,$model)['generation_params'];$pdo->beginTransaction();try{$q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['model',$model]);$q->execute(['context_size',(string)(int)$params['context_size']]);$q->execute(['gpu_layers',(string)(int)$params['gpu_layers']]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'Model is active, but saving its default configuration failed. '.$e->getMessage()],500);}
  out(['ok'=>true,'restart_queued'=>false,'already_active'=>true,'message'=>'This model is already loaded; the default configuration has been synchronized.']);
 }
 $managerStatus='C:/AI/local-ai-chat-manager/status.json';if(!is_file($managerStatus))out(['error'=>'Unified Llama Manager was not found. The model cannot be switched from chat. Install/start Manager first.'],503);
 // Chat model switching uses the selected model's own saved runtime settings.
 // Global context_size/gpu_layers are defaults for newly discovered models only.
 $modelConfig=lac_get_model_config($pdo,$model);
 $runtimeParams=$modelConfig['generation_params'];
 $queued=lac_queue_model_restart($pdo,$model,$runtimeParams);
 if(empty($queued['restart_queued'])){
  $warning=(string)($queued['warning']??'Could not queue the model-switch request.');
  $status=str_contains(strtolower($warning),'busy')?409:503;
  out(['error'=>$warning],$status);
 }
 out(['ok'=>true,'restart_queued'=>true,'transaction_id'=>$queued['transaction_id'],'model'=>$model,'context'=>(int)($runtimeParams['context_size']??16384),'gpu_layers'=>(int)($runtimeParams['gpu_layers']??0),'message'=>'Model load requested. The current default remains unchanged until the new model is confirmed.']);
}
if($action==='model_switch_status'){
 require_csrf();$id=trim((string)($_POST['id']??''));if(!preg_match('/^[a-f0-9]{16,64}$/',$id))out(['error'=>'Invalid model-switch transaction ID.'],422);
 $resultPath='C:/AI/local-ai-chat-manager/transactions/'.$id.'.json';
 if(!is_file($resultPath))out(['ok'=>true,'state'=>'pending','message'=>'Waiting for Local AI Manager.']);
 $result=json_decode((string)@file_get_contents($resultPath),true);if(!is_array($result)||($result['id']??'')!==$id)out(['error'=>'Manager returned an invalid transaction result.'],502);
 if(($result['state']??'')==='committed'){
  $details=$result['details']??[];$model=trim((string)($details['model']??''));$context=(int)($details['context']??0);$gpu=(int)($details['gpu_layers']??-1);
  if($model==='')out(['error'=>'Manager confirmed a switch without a model identifier.'],502);
  $pdo->beginTransaction();try{$q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['model',$model]);if($context>0)$q->execute(['context_size',(string)$context]);if($gpu>=0)$q->execute(['gpu_layers',(string)$gpu]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();out(['error'=>'Model started, but saving the confirmed configuration failed: '.$e->getMessage()],500);}
 }
 out(['ok'=>true,'state'=>(string)($result['state']??'unknown'),'message'=>(string)($result['message']??''),'details'=>$result['details']??null]);
}
if($action==='status'){
 $url=setting($pdo,'models_endpoint',$config['ai']['models_endpoint']);$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);out(['online'=>$raw!==false&&$code===200]);
}
function lac_get_model_config(PDO $pdo,string $model): array {
 $q=$pdo->prepare('SELECT alias,generation_params FROM model_aliases WHERE model_id=? LIMIT 1');$q->execute([$model]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];$params=json_decode((string)($row['generation_params']??''),true);if(!is_array($params))$params=[];
 $defaults=['temperature'=>(float)setting($pdo,'temperature','0.7'),'top_p'=>(float)setting($pdo,'top_p','0.9'),'max_tokens'=>(int)setting($pdo,'max_tokens','4096'),'timeout'=>(int)setting($pdo,'timeout','1200'),'context_size'=>(int)setting($pdo,'context_size','16384'),'gpu_layers'=>(int)setting($pdo,'gpu_layers','10')];
 return ['alias'=>(string)($row['alias']??''),'generation_params'=>array_merge($defaults,$params)];
}
function lac_queue_model_restart(PDO $pdo,string $model,array $params): array {
 $statusFile='C:/AI/local-ai-chat-manager/status.json';
 if(!is_file($statusFile))return ['restart_queued'=>false,'warning'=>'Unified Llama Manager was not found; the active default was not changed.'];
 $managerState=json_decode((string)@file_get_contents($statusFile),true);$updatedAt=strtotime((string)($managerState['updated_at']??''));
 if(!$updatedAt||time()-$updatedAt>30)return ['restart_queued'=>false,'warning'=>'Unified Llama Manager is not responding; the active default was not changed.'];
 $path=LAC_ROOT.'/storage/llama-manager/request.json';if(!is_dir(dirname($path))&&!mkdir(dirname($path),0775,true)&&!is_dir(dirname($path)))return ['restart_queued'=>false,'warning'=>'Restart queue could not be created; the active default was not changed.'];
 if(is_file($path.'.processing'))return ['restart_queued'=>false,'warning'=>'Another model operation is still running. Wait for it to finish before switching models.'];
 $fp=@fopen($path,'x');if(!$fp)return ['restart_queued'=>false,'warning'=>'Configuration saved, but Manager queue is busy.'];
 $id=bin2hex(random_bytes(8));$req=['id'=>$id,'created_at'=>date(DATE_ATOM),'action'=>'restart','port'=>8080,'model'=>$model,'context'=>(int)($params['context_size']??16384),'gpu_layers'=>(int)($params['gpu_layers']??10)];$json=json_encode($req,JSON_UNESCAPED_UNICODE);$written=$json!==false?fwrite($fp,$json):false;fclose($fp);if($written===false||$written===0){@unlink($path);return ['restart_queued'=>false,'warning'=>'Could not write the Manager restart request.'];}return ['restart_queued'=>true,'transaction_id'=>$id];
}
if($action==='model_config_get'){
 $model=trim((string)($_GET['model']??''));if($model==='')out(['error'=>'Model is missing'],422);out(['model'=>$model]+lac_get_model_config($pdo,$model));
}
if($action==='model_config_save'){
 require_csrf();$model=trim((string)($_POST['model']??''));$alias=trim((string)($_POST['alias']??''));$raw=(string)($_POST['generation_params']??'');$params=json_decode($raw,true);
 if($model===''||mb_strlen($alias)>180||!is_array($params))out(['error'=>'Invalid model configuration'],422);
 $allowed=['temperature','top_p','max_tokens','timeout','context_size','gpu_layers'];$clean=[];foreach($allowed as $key){if(!array_key_exists($key,$params))out(['error'=>'Missing parameter: '.$key],422);$v=$params[$key];if(!is_numeric($v))out(['error'=>'Invalid parameter: '.$key],422);$v=(float)$v;if($key==='temperature'&&($v<0||$v>2))out(['error'=>'Temperature must be between 0 and 2'],422);if($key==='top_p'&&($v<=0||$v>1))out(['error'=>'Top P must be greater than 0 and at most 1'],422);if($key==='max_tokens'&&(!in_array((int)$v,[1024,2048,4096,8192,16384,32768],true)))out(['error'=>'Invalid output token limit'],422);if($key==='timeout'&&($v<30||$v>3600))out(['error'=>'Timeout must be between 30 and 3600 seconds'],422);if($key==='context_size'&&!in_array((int)$v,[2048,4096,8192,16384,32768,65536,131072],true))out(['error'=>'Invalid context size'],422);if($key==='gpu_layers'&&($v<0||$v>999||floor($v)!==$v))out(['error'=>'GPU layers must be an integer from 0 to 999'],422);$clean[$key]=in_array($key,['temperature','top_p'])?$v:(int)$v;}
 $q=$pdo->prepare('INSERT INTO model_aliases(model_id,alias,generation_params) VALUES(?,?,?) ON DUPLICATE KEY UPDATE alias=VALUES(alias),generation_params=VALUES(generation_params)');$q->execute([$model,$alias,json_encode($clean,JSON_UNESCAPED_UNICODE)]);
 $default=setting($pdo,'model',$config['ai']['model']);$restart=false;$warning=null;$transactionId=null;
 if($model===$default){
  $oldContext=(int)setting($pdo,'context_size','16384');$oldGpu=(int)setting($pdo,'gpu_layers','10');
  if($oldContext!==(int)$clean['context_size']||$oldGpu!==(int)$clean['gpu_layers']){
   $queued=lac_queue_model_restart($pdo,$model,$clean);$restart=!empty($queued['restart_queued']);$transactionId=$queued['transaction_id']??null;$warning=$queued['warning']??null;
   if(!$restart)out(['ok'=>true,'model'=>$model,'alias'=>$alias,'generation_params'=>$clean,'restart_queued'=>false,'warning'=>$warning?:'Per-model settings were saved, but the active runtime was not changed.']);
  }
 }
 out(['ok'=>true,'model'=>$model,'alias'=>$alias,'generation_params'=>$clean,'restart_queued'=>$restart,'transaction_id'=>$transactionId,'warning'=>$warning]);
}
if($action==='infrastructure_settings_save'){
 require_csrf();$map=['endpoint','models_endpoint','model_dir'];$saved=[];foreach($map as $key){if(!isset($_POST[$key]))continue;$value=trim((string)$_POST[$key]);if(in_array($key,['endpoint','models_endpoint'],true)&&!filter_var($value,FILTER_VALIDATE_URL))out(['error'=>'Invalid URL: '.$key],422);if($key==='model_dir'&&$value==='')out(['error'=>'GGUF model directory cannot be empty'],422);$q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute([$key,$value]);$saved[$key]=$value;}out(['ok'=>true,'saved'=>$saved,'message'=>'Infrastructure settings saved. Endpoint changes apply to subsequent requests; model scanning uses the saved GGUF directory.']);
}
if($action==='model_alias_get'){
 $model=trim((string)($_GET['model']??''));if($model==='')out(['error'=>'Model is missing'],422);
 out(['model'=>$model,'alias'=>lac_find_model_alias($pdo,$model)]);
}
if($action==='model_alias_save'){
 require_csrf();$model=trim((string)($_POST['model']??''));$alias=trim((string)($_POST['alias']??''));
 if($model===''||mb_strlen($alias)>180)out(['error'=>'Invalid model or alias'],422);
 $q=$pdo->prepare('INSERT INTO model_aliases(model_id,alias) VALUES(?,?) ON DUPLICATE KEY UPDATE alias=VALUES(alias)');$q->execute([$model,$alias]);out(['ok'=>true,'model'=>$model,'alias'=>$alias]);
}
if($action==='settings_save'){
 require_csrf();
 if(isset($_POST['interface_language'])){
  $language=trim((string)$_POST['interface_language']);
  if($language!=='en')out(['error'=>'Unsupported interface language.'],422);
  $q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['interface_language',$language]);
 }
 if(isset($_POST['system_prompt'])){
  $v=trim((string)$_POST['system_prompt']);
  if(mb_strlen($v)>30000)out(['error'=>'System Prompt must be 30,000 characters or fewer.'],422);
  $q=$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$q->execute(['system_prompt',$v]);
 }
 if(isset($_POST['model'])){
  $model=trim((string)$_POST['model']);if($model==='')out(['error'=>'Select a model before saving the configuration.'],422);
  if(preg_match('/bge[-_]?m3|embedding/i',$model))out(['error'=>'An embedding model cannot be selected as the default chat model.'],422);
  $current=setting($pdo,'model',$config['ai']['model']);
  if($model!==$current){
   if(!is_file('C:/AI/local-ai-chat-manager/status.json'))out(['error'=>'Unified Llama Manager is unavailable. The previous default model was retained.'],503);
   $target=lac_get_model_config($pdo,$model)['generation_params'];
   $queued=lac_queue_model_restart($pdo,$model,$target);
   if(empty($queued['restart_queued']))out(['error'=>(string)($queued['warning']??'Could not queue model switch. The previous default was retained.')],409);
   out(['ok'=>true,'restart_queued'=>true,'transaction_id'=>$queued['transaction_id'],'model'=>$model,'message'=>'Model change is pending. The previous default remains active until the new model is verified.']);
  }
 }
 out(['ok'=>true,'restart_queued'=>false,'message'=>'Configuration saved. No model restart was required.']);
}
