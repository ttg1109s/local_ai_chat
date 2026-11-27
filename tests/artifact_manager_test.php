<?php
require_once __DIR__.'/../app/agent/artifact_manager.php';
$id='bat_'.str_repeat('a',20);
$artifact=['id'=>'art_'.str_repeat('b',24),'type'=>'image','mime_type'=>'image/jpeg','filename'=>'chart_'.str_repeat('c',32).'.jpg','url'=>'api.php?action=python_output&file=chart_'.str_repeat('c',32).'.jpg','status'=>'success'];
$m=lac_artifact_batch_register($id,[$artifact],['run_id'=>'test-run','conversation_id'=>73]);
$read=lac_artifact_batch_get($id);
if(($read['item_count']??0)!==1||($read['items'][0]['id']??null)!==$artifact['id'])throw new RuntimeException('Artifact batch persistence failed.');
$found=lac_artifact_find_by_id($artifact['id']);if(($found['meta']['conversation_id']??0)!==73)throw new RuntimeException('Artifact registry must be independent from message rows.');
$deleted=lac_artifact_delete_by_id($artifact['id'],73);if(empty($deleted['deleted']))throw new RuntimeException('Artifact registry deletion failed.');

echo "PASS: artifact batch manifest\n";
