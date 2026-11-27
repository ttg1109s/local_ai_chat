<?php
/** Canonical, renderer-neutral envelope for every tool result. */
function lac_tool_output_detect_type(array $result): array {
 $domain=is_array($result['result']??null)?$result['result']:$result;
 $mime=strtolower((string)($domain['mime_type']??''));$file=strtolower((string)($domain['filename']??$domain['file']??''));
 if(str_starts_with($mime,'image/')||preg_match('/\.(png|jpe?g|webp|gif|svg)$/',$file))return ['type'=>'image','format'=>$mime?:pathinfo($file,PATHINFO_EXTENSION)];
 if(str_contains($mime,'pdf')||preg_match('/\.pdf$/',$file))return ['type'=>'document','format'=>'pdf'];
 if(preg_match('/\.(docx|xlsx|pptx|txt|md|html)$/',$file,$m))return ['type'=>'document','format'=>substr($m[0],1)];
 if(isset($domain['sources'])||isset($domain['citations'])||isset($domain['pages']))return ['type'=>'evidence','format'=>'json'];
 if(isset($domain['chart'])||isset($domain['chart_spec'])||isset($domain['plot']))return ['type'=>'chart','format'=>'json'];
 if(isset($domain['dataframe'])||isset($domain['columns'])&&isset($domain['rows']))return ['type'=>'tabular','format'=>'json'];
 if(isset($domain['metrics'])||isset($domain['accuracy'])||isset($domain['predictions']))return ['type'=>'model_report','format'=>'json'];
 if(array_is_list($domain)&&$domain&&is_array($domain[0]??null))return ['type'=>'tabular','format'=>'json'];
 if(isset($domain['formula'])||isset($domain['latex']))return ['type'=>'math','format'=>'latex'];
 return ['type'=>'json','format'=>'json'];
}
function lac_tool_output_envelope(array $result,array $context=[]): array {
 $detected=lac_tool_output_detect_type($result);$ref=(string)($result['result_reference']??'');
 $artifact=[];
 // Data Pack references belong only in result.data_ref. The artifacts array is
 // reserved for independently managed binary artifacts.
 foreach(($result['artifacts']??[]) as $a)if(is_array($a)&&isset($a['id']))$artifact[]=$a;
 $preview=null;$domain=is_array($result['result']??null)?$result['result']:$result;
 if(isset($domain['sources'])&&is_array($domain['sources']))$preview=['source_count'=>count($domain['sources']),'sources'=>array_slice($domain['sources'],0,12)];
 elseif(isset($domain['rows'])&&is_array($domain['rows']))$preview=['row_count'=>count($domain['rows']),'rows'=>array_slice($domain['rows'],0,20)];
 else $preview=function_exists('lac_data_pack_compact_preview')?lac_data_pack_compact_preview($domain):array_slice($domain,0,30,true);
 return ['schema_version'=>'1.0','tool'=>(string)($result['tool']??''),'execution_id'=>$result['execution_id']??null,'result_id'=>$result['result_id']??null,'tool_call_id'=>$result['tool_call_id']??null,'status'=>empty($result['ok'])?'failed':'succeeded','result'=>['type'=>$detected['type'],'format'=>$detected['format'],'data_ref'=>$ref?:null,'preview'=>$preview],'artifacts'=>$artifact,'attachments'=>[],'metadata'=>['run_id'=>$context['run_id']??null,'coverage'=>$domain['coverage']??null,'truncated'=>(bool)($domain['truncated']??false),'created_at'=>date(DATE_ATOM)]];
}
