<?php
$chat=file_get_contents(__DIR__.'/../api_routes/chat.php');
$stream=file_get_contents(__DIR__.'/../api_routes/chat_stream.php');
foreach([$chat,$stream] as $source){
 if(!str_contains($source,'Respond in English only.'))throw new RuntimeException('Chat must explicitly require English output.');
}
$notes=file_get_contents(__DIR__.'/../AGENT_ENGLISH_NORMALIZATION_NOTES.md');
if(!str_contains($notes,'All Agent Runtime protocol instructions, internal diagnostics, event names, and log messages must be written in English.'))throw new RuntimeException('Project language policy must require English internal constructs.');
echo "PASS: English-only Agent instructions and internal language policy\n";
