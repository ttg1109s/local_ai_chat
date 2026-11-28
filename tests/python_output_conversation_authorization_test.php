<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../api_routes/system_runtime.php');
if(!is_string($source))throw new RuntimeException('Could not read system runtime route.');
$start=strpos($source, 'if($action===\'python_output\'){');
if($start===false)throw new RuntimeException('python_output route missing.');
$end=strpos($source, "\n\n/** Normalize a model identifier",$start);
if($end===false)throw new RuntimeException('Could not isolate python_output route.');
$route=substr($source,$start,$end-$start);
$referenceLookup=strpos($route, 'SELECT DISTINCT c.id,c.password_hash FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE LOCATE(?,m.content)>0');
$notFoundCheck=strpos($route, 'if(!$refs){http_response_code(404);exit;}');
$lockCheck=strpos($route, "if(!empty($ref['password_hash'])&&!in_array((int)$ref['id'],$unlockedIds,true)){http_response_code(423);exit;}");
$fileRead=strpos($route, "$path=LAC_ROOT.'/storage/python_outputs/'.$file;");
if($referenceLookup===false||$notFoundCheck===false||$lockCheck===false||$fileRead===false||!($referenceLookup<$fileRead&&$notFoundCheck<$fileRead&&$lockCheck<$fileRead)){
 throw new RuntimeException('Generated chart serving must resolve message ownership and enforce conversation unlock before reading file.');
}
if(!str_contains($route, "header('Cache-Control: private, no-store');"))throw new RuntimeException('Generated chart response must not be cacheable across unlock state.');
echo "PASS: python_output requires a message reference and unlocked owner conversation before file access\n";
