<?php
require_once dirname(__DIR__).'/result_store.php';
require_once dirname(__DIR__).'/tool_executor.php';
require_once dirname(__DIR__).'/task_store.php';
require_once dirname(__DIR__).'/execution_store.php';
require_once dirname(__DIR__).'/completion_validator.php';
require_once dirname(__DIR__).'/autonomy_policy.php';
require_once dirname(__DIR__).'/protocol_compiler.php';
require_once dirname(__DIR__).'/execution_policy.php';
require_once dirname(__DIR__).'/model_transport.php';
require_once __DIR__.'/protocol.php';
require_once __DIR__.'/recovery.php';
require_once dirname(__DIR__).'/context/builder.php';

function lac_agent_runtime_fallback_text(array $context,string $kind='protocol'): string {
 if($kind==='model')return 'The model runtime failed repeatedly. I could not complete the request because the model service was unavailable. No unverified tool action is being reported as completed.';
 return 'I could not safely interpret the model response. No unverified tool action is being reported as completed.';
}
function lac_agent_stop_requested(array $context): bool {
 foreach($context['stop_files']??[] as $file)if($file&&is_file($file))return true;
 return false;
}

require_once dirname(__DIR__).'/manifest_manager.php';
require_once dirname(__DIR__).'/data_access.php';
require_once dirname(__DIR__).'/data_flow_controller.php';
require_once dirname(__DIR__).'/attachment_manager.php';
require_once dirname(__DIR__).'/tool_output.php';
