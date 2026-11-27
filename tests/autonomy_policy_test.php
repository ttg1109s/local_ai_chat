<?php
require_once __DIR__.'/../app/agent/autonomy_policy.php';
$policy=lac_agent_autonomy_policy();
if(($policy['level']??0)!==2||empty($policy['approval']['external'])||empty($policy['approval']['irreversible'])||empty($policy['approval']['sensitive']))throw new RuntimeException('Autonomy level 2 defaults are incomplete.');
$local=lac_agent_autonomy_decision('python_statistics',['values'=>[1,2,3]],['risk'=>'compute']);
if(empty($local['allowed'])||!empty($local['approval_required']))throw new RuntimeException('Local compute should execute autonomously.');
foreach(['external','irreversible','sensitive'] as $risk){
 $decision=lac_agent_autonomy_decision('example_tool',[],['autonomy'=>['risk'=>$risk]]);
 if(empty($decision['approval_required'])||$decision['allowed'])throw new RuntimeException($risk.' action must require approval.');
}
$disabled=lac_agent_autonomy_decision('python_statistics',[],['risk'=>'compute'],['autonomy_policy'=>['level'=>1]]);
if($disabled['allowed'])throw new RuntimeException('Level 1 must not silently run as level 2.');
echo "PASS: autonomy level 2, local execution and mandatory approval categories\n";
