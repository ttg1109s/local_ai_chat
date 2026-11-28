<?php
declare(strict_types=1);

// Regression guard for descriptor mutation races: registration and all owner-cleanup
// paths must share the same stable lock inode.
$source=file_get_contents(__DIR__.'/../app/agent/attachment_manager.php');
if(!is_string($source))throw new RuntimeException('Could not read attachment_manager.php.');
if(substr_count($source,'lac_attachment_mutation_lock()')<4)throw new RuntimeException('Register/message/conversation/artifact cleanup must use the shared mutation lock.');
if(!str_contains($source,".attachment-manager.lock"))throw new RuntimeException('Attachment mutation lock filename is missing.');
foreach(['lac_attachment_cleanup_message','lac_attachment_cleanup_conversation','lac_attachment_cleanup_artifact'] as $fn){
 $start=strpos($source,'function '.$fn);if($start===false)throw new RuntimeException('Missing cleanup function '.$fn);
 $body=substr($source,$start,1000);
 if(!str_contains($body,'lac_attachment_mutation_lock()')||!str_contains($body,'lac_attachment_mutation_unlock($lock)'))throw new RuntimeException($fn.' must lock and unlock the shared mutation mutex.');
}
echo "agent_attachment_cleanup_serialization_test: PASS\\n";
