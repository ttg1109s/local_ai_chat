<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../api_routes/generation.php');
if (!is_string($source)) {
    throw new RuntimeException('Could not read generation route.');
}
$start = strpos($source, 'if($messageId>0&&$conversationId>0){');
if ($start === false) {
    throw new RuntimeException('Message-ID stop branch not found.');
$end = strpos($source, "\n }\n out(['error'=>'A valid process ID", $start);
if ($end === false) {
    throw new RuntimeException('Could not isolate message-ID stop branch.');
$branch = substr($source, $start, $end - $start);
$authorization = strpos($branch, 'lac_require_conversation_unlocked($pdo,$conversationId);');
$lookup = strpos($branch, 'SELECT id FROM messages WHERE id=? AND conversation_id=?');
$stopWrite = strpos($branch, "file_put_contents($dir.'/'.$messageId.'.stop'");
if ($authorization === false || $lookup === false || $stopWrite === false || !($authorization < $lookup && $authorization < $stopWrite)) {
    throw new RuntimeException('Message-ID stop path must authorize conversation unlock before validation/write.');
}
echo "PASS: stop_generation message-ID path enforces conversation unlock authorization\n";
