<?php
require_once __DIR__.'/../app/agent/schema_validator.php';
require_once __DIR__.'/../app/agent/tool_registry.php';
function tool_reg_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$catalog=lac_tool_catalog();
$validate=static function(string $tool,array $arguments)use($catalog):array{
 if(!isset($catalog[$tool]))return ['valid'=>false,'error'=>'UNKNOWN_TOOL'];
 $errors=lac_tool_validate_arguments($tool,$arguments,$catalog[$tool]);
 return ['valid'=>!$errors,'error'=>$errors?'INVALID_ARGUMENTS':null,'details'=>$errors];
};
$cases=[
 ['python_plot',['chart_type'=>'line','title'=>'Regression','series'=>[['name'=>'A','values'=>[1,2,3]],['name'=>'B','values'=>[3,2,1]]]],true,'multi-series line'],
 ['python_plot',['chart_type'=>'bar','title'=>'Regression','series'=>[['name'=>'A','values'=>[1,2]],['name'=>'B','values'=>[3,4]]]],true,'multi-series bar'],
 ['python_plot',['chart_type'=>'pie','title'=>'Invalid','series'=>[['values'=>[1,2]]]],false,'unsupported multi-series chart'],
 ['python_plot',['chart_type'=>'line','series'=>[['values'=>[1,2],'x'=>['a']]]],false,'mismatched x/value lengths'],
 ['python_calculate',['expression'=>'1/x','mode'=>'limit','point'=>'0'],true,'string point values'],
 ['manage_websites',['action'=>'read'],false,'read requires configured site'],
 ['manage_websites',['action'=>'list'],true,'list without site'],
 ['python_dataframe',['data'=>[['a'=>1]],'operation'=>'filter'],false,'filter requires column'],
 ['python_dataframe',['data'=>[['a'=>1]],'operation'=>'filter','column'=>'a','value'=>1],true,'filter with required args'],
 ['python_dataframe',['data'=>[['a'=>1,'b'=>2]],'operation'=>'groupby','column'=>'a','aggregation'=>'sum'],false,'groupby requires value column'],
 ['verify_result',['kind'=>'arithmetic','expression'=>'2+2'],false,'arithmetic verification requires actual'],
 ['verify_result',['kind'=>'record_count','records'=>[1,2],'reported_count'=>2],true,'record-count verification'],
 ['python_generate_data',['distribution'=>'uniform','count'=>10,'min'=>5,'max'=>2],false,'invalid generation range'],
];
foreach($cases as [$tool,$args,$expected,$label])tool_reg_assert($validate($tool,$args)['valid']===$expected,$label.' schema validation');
$exampleCount=0;$failures=[];
foreach($catalog as $name=>$meta)foreach(($meta['examples']??[]) as $index=>$example){$result=$validate($name,$example);$exampleCount++;if(empty($result['valid']))$failures[]=$name.' example '.$index.': '.json_encode($result['details']??[]);}
tool_reg_assert(!$failures,'all registry examples pass schema validation: '.implode(' | ',$failures));
$coverage=[
 'data_access'=>['operation'=>['inspect','read','query','verify']],
 'search_web'=>['mode'=>['search','research','deep_research']],
 'manage_websites'=>['action'=>['list','read']],
 'python_calculate'=>['mode'=>['evaluate','simplify','factor','expand','derivative','integrate','limit','solve','nsolve','series','matrix'],'matrix_operation'=>['determinant','inverse','rank','eigenvalues','transpose','rref']],
 'python_generate_data'=>['distribution'=>['integer','uniform','normal']],
 'python_statistics'=>['method'=>['describe','descriptive','summary','pearson','spearman','kendall','correlation','linear_regression','one_sample_t_test','two_sample_t_test','paired_t_test','mann_whitney','wilcoxon','anova','one_way_anova','normality','chi_square','confidence_interval_mean']],
 'python_plot'=>['chart_type'=>['line','bar','scatter','histogram','boxplot','pie','area']],
 'python_dataframe'=>['operation'=>['profile','filter','groupby'],'operator'=>['eq','ne','gt','gte','lt','lte','contains']],
 'verify_result'=>['kind'=>['arithmetic','record_count']]
];

$dataAccessOps=[];$sortDirections=[];$aggregateOps=[];
foreach(($catalog['data_access']['examples']??[]) as $example){
 foreach(($example['where']??[]) as $condition){$dataAccessOps[]=is_array($condition)?($condition['op']??''):'eq';}
 if(isset($example['sort']['direction']))$sortDirections[]=$example['sort']['direction'];
 foreach(($example['aggregate']??[]) as $aggregate)$aggregateOps[]=$aggregate['op']??'';
}
foreach(['eq','neq','gt','gte','lt','lte','in','contains','starts_with','ends_with','is_null','not_null'] as $op)tool_reg_assert(in_array($op,$dataAccessOps,true),'data_access examples must cover where operator '.$op);
foreach(['asc','desc'] as $direction)tool_reg_assert(in_array($direction,$sortDirections,true),'data_access examples must cover sort direction '.$direction);
foreach(['count','sum','avg','min','max'] as $op)tool_reg_assert(in_array($op,$aggregateOps,true),'data_access examples must cover aggregate '.$op);
$dfAggregations=[];foreach(($catalog['python_dataframe']['examples']??[]) as $example)if(isset($example['aggregation']))$dfAggregations[]=$example['aggregation'];
foreach(['count','sum','mean','min','max'] as $op)tool_reg_assert(in_array($op,$dfAggregations,true),'python_dataframe examples must cover aggregation '.$op);
foreach($coverage as $tool=>$dimensions){
 $examples=$catalog[$tool]['examples']??[];
 foreach($dimensions as $field=>$expectedValues){
  $seen=[];
  foreach($examples as $example)if(array_key_exists($field,$example))$seen[]=$example[$field];
  $missing=array_values(array_diff($expectedValues,$seen));
  tool_reg_assert(!$missing,$tool.' examples must cover '.$field.': '.implode(', ',$missing));
 }
}

echo "OK: tool argument validation tests passed ($exampleCount registry examples; ".count($catalog).' tools)'.PHP_EOL;
