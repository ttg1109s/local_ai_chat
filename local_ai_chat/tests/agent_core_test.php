<?php
require_once __DIR__.'/../app/agent/tool_registry.php';
require_once __DIR__.'/../app/agent/schema_validator.php';
require_once __DIR__.'/../app/agent/protocol.php';
function check(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$catalog=lac_tool_catalog();
check(count($catalog)===11,'catalog retains all remaining tools including data generation and system tools');
check(lac_agent_tool_signature('python_plot',['x'=>[1,2],'y'=>[3,4]])===lac_agent_tool_signature('python_plot',['y'=>[3,4],'x'=>[1,2]]),'tool signatures ignore JSON object key order');
check(isset($catalog['python_generate_data']),'data generation tool registered');
check(lac_tool_validate_arguments('python_generate_data',['distribution'=>'integer','count'=>50,'min'=>1,'max'=>100],$catalog['python_generate_data'])===[],'valid data generation schema');
check(lac_tool_validate_arguments('python_generate_data',['distribution'=>'uniform','count'=>10,'series_count'=>3,'seed'=>7],$catalog['python_generate_data'])===[],'multi-series data generation schema');
check(lac_tool_validate_arguments('python_plot',['chart_type'=>'line','series'=>[['name'=>'A','values'=>[1,2]],['name'=>'B','values'=>[2,3]]]],$catalog['python_plot'])===[],'multi-series plot schema');
check(lac_tool_validate_arguments('python_plot',['chart_type'=>'line','y'=>[1,2,3]],$catalog['python_plot'])===[],'single-series plot accepts omitted x');
check(count(lac_tool_validate_arguments('python_plot',['chart_type'=>'line'],$catalog['python_plot']))>0,'single-series plot requires y when series is absent');
check(count(lac_tool_validate_arguments('python_plot',['chart_type'=>'histogram','series'=>[['values'=>[1,2]]]],$catalog['python_plot']))>0,'multi-series plot rejects unsupported chart type');
check(count(lac_tool_validate_arguments('python_plot',['chart_type'=>'line','x'=>[1,2],'y'=>[1,2,3]],$catalog['python_plot']))>0,'single-series plot rejects mismatched x/y');

check(lac_tool_validate_arguments('manage_websites',['action'=>'list'],$catalog['manage_websites'])===[],'website list action accepted');
check(lac_tool_validate_arguments('manage_websites',['action'=>'read','site'=>'Weather'],$catalog['manage_websites'])===[],'website read action accepted');
check(lac_tool_validate_arguments('python_calculate',['expression'=>'1+2'],$catalog['python_calculate'])===[],'valid calculate schema');
check(count(lac_tool_validate_arguments('python_calculate',['expression'=>'1+2','unexpected'=>true],$catalog['python_calculate']))>0,'unknown argument rejected');
check(count(lac_tool_validate_arguments('python_statistics',['method'=>'pearson','values'=>[1,2,3]],$catalog['python_statistics']))>0,'paired method requires y');
$defaults=lac_tool_apply_argument_defaults(['distribution'=>'uniform'],$catalog['python_generate_data']);
check(($defaults['count']??null)===10,'schema default count=10 applied when omitted');
check(($catalog['search_web']['idempotent']??false)===true,'read tool defaults to idempotent');
check(($catalog['python_plot']['cache_policy']??'')==='never','compute tools are not implicitly cached');
echo "Agent core tests passed.\n";
