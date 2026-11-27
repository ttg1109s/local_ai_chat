<?php
require_once __DIR__.'/manifest_manager.php';require_once __DIR__.'/result_store.php';
function lac_data_pack_inspect(string $ref,string $runId): array {
 $m=lac_data_pack_manifest_get($ref,$runId);if(!$m){if(!lac_data_pack_exists_for_run($ref,$runId))throw new RuntimeException('DATA_PACK_NOT_FOUND');return ['data_ref'=>$ref,'manifest_available'=>false];}
 $fields=[];foreach($m['fields'] as $name=>$meta)$fields[$name]=['type'=>$meta['type']??'unknown','count'=>$meta['count']??null,'segmented'=>(bool)($meta['segmented']??false),'segment_count'=>count($meta['segments']??[]),'bytes'=>array_sum(array_column($meta['segments']??[],'bytes'))];
 return ['data_ref'=>$ref,'manifest_available'=>true,'data_pack_id'=>$m['data_pack_id'],'tool'=>$m['tool'],'fields'=>$fields,'integrity'=>$m['integrity'],'lifecycle'=>$m['lifecycle'],'created_at'=>$m['created_at']??null];
}
function lac_data_pack_read_segmented(string $ref,string $runId,string $field,int $offset=0,int $limit=100): array {
 $m=lac_data_pack_manifest_get($ref,$runId);if(!$m)throw new RuntimeException('DATA_PACK_MANIFEST_NOT_FOUND');$meta=$m['fields'][$field]??null;if(!is_array($meta)||empty($meta['segmented']))throw new RuntimeException('DATA_PACK_FIELD_NOT_SEGMENTED');
 $total=(int)($meta['count']??0);$offset=max(0,min($total,$offset));$limit=max(1,min(1000,$limit));$end=min($total,$offset+$limit);$out=[];$cursor=0;$dir=lac_data_pack_manifest_dir();$id=(string)$m['data_pack_id'];
 foreach($meta['segments'] as $seg){$rows=(int)$seg['rows'];$start=$cursor;$stop=$cursor+$rows;$cursor=$stop;if($stop<=$offset||$start>=$end)continue;$name=(string)($seg['file']??'');if(!preg_match('/^'.preg_quote($id,'/').'\.[a-zA-Z0-9_-]+\.\d{6}\.jsonl$/D',$name))throw new RuntimeException('SEGMENT_NAME_INVALID');$path=$dir.'/'.$name;if(!is_file($path)||is_link($path))throw new RuntimeException('SEGMENT_MISSING');if(!hash_equals((string)$seg['sha256'],(string)hash_file('sha256',$path)))throw new RuntimeException('SEGMENT_INTEGRITY_FAILED');
  $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('SEGMENT_OPEN_FAILED');$idx=$start;while(($line=fgets($fh))!==false){if($idx>=$offset&&$idx<$end){$v=json_decode($line,true);if(json_last_error()!==JSON_ERROR_NONE){fclose($fh);throw new RuntimeException('SEGMENT_DECODE_FAILED');}$out[]=$v;}$idx++;if($idx>=$end)break;}fclose($fh);
 }
 return ['data_ref'=>$ref,'field'=>$field,'offset'=>$offset,'limit'=>$limit,'total_rows'=>$total,'returned_rows'=>count($out),'has_more'=>$end<$total,'coverage'=>['start'=>$offset,'end'=>$offset+count($out),'total'=>$total,'complete'=>$offset===0&&count($out)===$total],'rows'=>$out];
}
/** Verify every segment referenced by a run-owned manifest, not merely manifest presence. */
function lac_data_pack_verify(string $ref,string $runId): array {
 $m=lac_data_pack_manifest_get($ref,$runId);if(!$m)throw new RuntimeException('DATA_PACK_MANIFEST_NOT_FOUND');$checked=0;$bytes=0;$errors=[];$dir=lac_data_pack_manifest_dir();$id=(string)$m['data_pack_id'];
 foreach($m['fields'] as $field=>$meta)foreach(($meta['segments']??[]) as $seg){$name=(string)($seg['file']??'');if(!preg_match('/^'.preg_quote($id,'/').'\.[a-zA-Z0-9_-]+\.\d{6}\.jsonl$/D',$name)){$errors[]=['field'=>$field,'error'=>'SEGMENT_NAME_INVALID'];continue;}$path=$dir.'/'.$name;if(!is_file($path)||is_link($path)){$errors[]=['field'=>$field,'error'=>'SEGMENT_MISSING'];continue;}$actual=hash_file('sha256',$path);$checked++;$bytes+=(int)filesize($path);if(!is_string($actual)||!hash_equals((string)$seg['sha256'],$actual))$errors[]=['field'=>$field,'file'=>$name,'error'=>'SEGMENT_INTEGRITY_FAILED'];}
 return ['data_ref'=>$ref,'verified'=>!$errors,'verification_scope'=>'all_manifest_segments','segments_checked'=>$checked,'bytes_checked'=>$bytes,'errors'=>$errors,'manifest_integrity'=>$m['integrity']??null];
}
/** Bounded full-dataset scan. Sorting/aggregation are explicit and refuse scans over policy. */
/**
 * Execute a bounded query over the complete segmented field without hydrating the
 * source dataset. Filtering and aggregates are streaming; only matched rows needed
 * for sorting/pagination are retained, under an explicit memory budget.
 */
function lac_data_pack_query(string $ref,string $runId,string $field,array $query=[]): array {
 $m=lac_data_pack_manifest_get($ref,$runId);
 if(!$m)throw new RuntimeException('DATA_PACK_MANIFEST_NOT_FOUND');
 $meta=$m['fields'][$field]??null;
 if(!is_array($meta)||empty($meta['segmented']))throw new RuntimeException('DATA_PACK_FIELD_NOT_SEGMENTED');
 $total=(int)($meta['count']??0);
 $maxScan=max(1000,min(2000000,(int)($query['max_scan_rows']??200000)));
 if($total>$maxScan)throw new RuntimeException('DATA_QUERY_SCAN_LIMIT_EXCEEDED');
 $where=$query['where']??[];
 if(!is_array($where)||count($where)>32)throw new InvalidArgumentException('DATA_QUERY_WHERE_INVALID');
 $allowedOps=['eq','neq','gt','gte','lt','lte','in','contains','starts_with','ends_with','is_null','not_null'];
 $matches=static function($row)use($where,$allowedOps):bool {
  if(!is_array($row))return false;
  foreach($where as $key=>$condition){
   $exists=array_key_exists((string)$key,$row);$actual=$exists?$row[$key]:null;
   if(is_array($condition)&&array_key_exists('op',$condition)){
    $op=(string)$condition['op'];if(!in_array($op,$allowedOps,true))throw new InvalidArgumentException('DATA_QUERY_OPERATOR_INVALID');
    $v=$condition['value']??null;
    $ok=match($op){
     'eq'=>$exists&&$actual===$v,'neq'=>!$exists||$actual!==$v,
     'gt'=>$exists&&is_numeric($actual)&&is_numeric($v)&&$actual>$v,
     'gte'=>$exists&&is_numeric($actual)&&is_numeric($v)&&$actual>=$v,
     'lt'=>$exists&&is_numeric($actual)&&is_numeric($v)&&$actual<$v,
     'lte'=>$exists&&is_numeric($actual)&&is_numeric($v)&&$actual<=$v,
     'in'=>$exists&&is_array($v)&&in_array($actual,$v,true),
     'contains'=>$exists&&is_string($actual)&&is_string($v)&&str_contains($actual,$v),
     'starts_with'=>$exists&&is_string($actual)&&is_string($v)&&str_starts_with($actual,$v),
     'ends_with'=>$exists&&is_string($actual)&&is_string($v)&&str_ends_with($actual,$v),
     'is_null'=>$exists&&$actual===null,'not_null'=>$exists&&$actual!==null,default=>false
    };
    if(!$ok)return false;
   }elseif($actual!==$condition)return false;
  }
  return true;
 };
 $aggregates=[];$aggregateSpecs=$query['aggregate']??[];
 if(!is_array($aggregateSpecs)||count($aggregateSpecs)>20)throw new InvalidArgumentException('DATA_QUERY_AGGREGATE_INVALID');
 foreach($aggregateSpecs as $spec){
  if(!is_array($spec))continue;$op=(string)($spec['op']??'');$f=(string)($spec['field']??'');
  if(!in_array($op,['count','sum','avg','min','max'],true))throw new InvalidArgumentException('DATA_QUERY_AGGREGATE_OPERATOR_INVALID');
  $aggregates[]=['op'=>$op,'field'=>$f,'value'=>null,'_sum'=>0.0,'_count'=>0];
 }
 $sort=$query['sort']??null;
 if($sort!==null&&(!is_array($sort)||!isset($sort['field'])))throw new InvalidArgumentException('DATA_QUERY_SORT_INVALID');
 $sortField=is_array($sort)?(string)$sort['field']:null;
 $sortDir=is_array($sort)&&strtolower((string)($sort['direction']??'asc'))==='desc'?-1:1;
 $offset=max(0,min(2000000,(int)($query['offset']??0)));
 $limit=max(1,min(1000,(int)($query['limit']??100)));
 $matched=0;$rows=[];$cursor=0;$materializedBytes=0;$pageFull=false;$maxSortBytes=max(1048576,min(67108864,(int)($query['max_sort_bytes']??16777216)));$maxPageBytes=max(65536,min(12582912,(int)($query['max_page_bytes']??10485760)));$dir=lac_data_pack_manifest_dir();$id=(string)$m['data_pack_id'];
 foreach(($meta['segments']??[]) as $seg){
  $name=(string)($seg['file']??'');
  if(!preg_match('/^'.preg_quote($id,'/').'\.[a-zA-Z0-9_-]+\.\d{6}\.jsonl$/D',$name))throw new RuntimeException('SEGMENT_NAME_INVALID');
  $path=$dir.'/'.$name;
  if(!is_file($path)||is_link($path))throw new RuntimeException('SEGMENT_MISSING');
  $actualHash=hash_file('sha256',$path);
  if(!is_string($actualHash)||!hash_equals((string)($seg['sha256']??''),$actualHash))throw new RuntimeException('SEGMENT_INTEGRITY_FAILED');
  $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('SEGMENT_OPEN_FAILED');
  try {
   while(($line=fgets($fh))!==false){
    if(strlen($line)>4*1024*1024)throw new RuntimeException('DATA_ROW_SIZE_LIMIT_EXCEEDED');
    $row=json_decode($line,true);if(json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('SEGMENT_DECODE_FAILED');
    $cursor++;if(!$matches($row))continue;$matched++;
    foreach($aggregates as &$a){
     if($a['op']==='count'){$a['_count']++;continue;}
     $v=is_array($row)?($row[$a['field']]??null):null;if(!is_numeric($v))continue;
     $n=(float)$v;$a['_sum']+=$n;$a['_count']++;
     if($a['op']==='min')$a['value']=$a['value']===null?$n:min($a['value'],$n);
     if($a['op']==='max')$a['value']=$a['value']===null?$n:max($a['value'],$n);
    }unset($a);
    if($sortField!==null){
     $materializedBytes+=strlen($line);
     if($materializedBytes>$maxSortBytes)throw new RuntimeException('DATA_QUERY_SORT_MEMORY_LIMIT_EXCEEDED');
     $rows[]=$row;
    }elseif(!$pageFull&&$matched>$offset&&count($rows)<$limit){
     $rowBytes=strlen($line);
     if($materializedBytes+$rowBytes>$maxPageBytes){
      if(!$rows)throw new RuntimeException('DATA_QUERY_PAGE_BYTE_LIMIT_EXCEEDED');
      $pageFull=true;
     }else{$rows[]=$row;$materializedBytes+=$rowBytes;}
    }
   }
  } finally {fclose($fh);}
 }
 foreach($aggregates as &$a){
  if($a['op']==='count')$a['value']=$matched;
  elseif($a['op']==='sum')$a['value']=floor($a['_sum'])===$a['_sum']?(int)$a['_sum']:$a['_sum'];
  elseif($a['op']==='avg')$a['value']=$a['_count']?$a['_sum']/$a['_count']:null;
  unset($a['_sum'],$a['_count']);
 }unset($a);
 if($sortField!==null){
  usort($rows,static function($a,$b)use($sortField,$sortDir){
   $x=is_array($a)?($a[$sortField]??null):null;$y=is_array($b)?($b[$sortField]??null):null;
   $cmp=is_numeric($x)&&is_numeric($y)?($x<=>$y):strcmp((string)$x,(string)$y);
   return $cmp*$sortDir;
  });
  $rows=array_slice($rows,$offset,$limit);
 }
 return ['data_ref'=>$ref,'field'=>$field,'offset'=>$offset,'limit'=>$limit,'total_rows'=>$total,
  'matched_rows'=>$matched,'returned_rows'=>count($rows),'has_more'=>$offset+count($rows)<$matched,
  'coverage'=>['scanned_rows'=>$cursor,'matched_rows'=>$matched,'complete_scan'=>$cursor===$total,'filter_applied'=>(bool)$where],
  'aggregates'=>$aggregates,'rows'=>$rows];
}
