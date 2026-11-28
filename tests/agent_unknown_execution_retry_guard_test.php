<?php
declare(strict_types=1);

$source=file_get_contents(__DIR__.'/../app/agent/execution_store.php');
if(!is_string($source))throw new RuntimeException('Execution store source read failed.');
$guard="if(in_array(\$existing['state']??'', ['DISPATCHED','EXECUTING','RESULT_RECEIVED','UNKNOWN'],true)){";
if(!str_contains($source,$guard))throw new RuntimeException('UNKNOWN executions must be blocked from blind retry.');
$guardPos=strpos($source,$guard);
$unknownCode=strpos($source,"'error_code'=>'EXECUTION_OUTCOME_UNKNOWN'",$guardPos);
$unknownMessage=strpos($source,'Reconcile it before retrying.',$guardPos);
if($unknownCode===false||$unknownMessage===false||$unknownCode<$guardPos||$unknownMessage<$guardPos)throw new RuntimeException('UNKNOWN retry guard must return an outcome-unknown error and require reconciliation.');
echo "PASS: UNKNOWN execution states are blocked from blind retry\n";
