<?php
$source=file_get_contents(__DIR__.'/../app/agent/execution_store.php');
if(!is_string($source))throw new RuntimeException('Could not read execution_store.php');
$managed=strpos($source,'function lac_tool_execute_managed_locked');
if($managed===false)throw new RuntimeException('Managed executor not found.');
$body=substr($source,$managed);
if(substr_count($body,"error_code'=>'JOB_CANCELLED")<2)throw new RuntimeException('Expected both cancellation checkpoints.');
// Cancellation of an in-flight invocation must not clean up the entire run:
// earlier committed Data Packs belong to completed invocations and must remain recoverable.
if(preg_match('/JOB_CANCELLED.{0,500}lac_data_pack_cleanup_run\\(\\$runId\\)/s',$body)) {
 throw new RuntimeException('Cancellation path still deletes all Data Packs for the run.');
}
if(str_contains($body,"Job was cancelled; the Data Pack was revoked.")) {
 throw new RuntimeException('Cancellation still reports revocation of all run results.');
}
echo "execution_cancellation_preserves_prior_results_test: PASS\\n";
