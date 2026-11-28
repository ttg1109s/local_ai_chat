<?php
require __DIR__.'/app/bootstrap.php';
$action=(string)($_GET['action']??'');
if (!defined('LAC_ROOT')) define('LAC_ROOT', __DIR__);

// Route modules execute in this scope and share the established bootstrap context
// ($pdo, $config, $action, session, CSRF helpers). Preserve ordering for legacy API behavior.
require __DIR__.'/api_routes/system_runtime.php';
require __DIR__.'/api_routes/conversations.php';
require __DIR__.'/api_routes/agent.php';
require __DIR__.'/api_routes/chat_stream.php';
require __DIR__.'/api_routes/generation.php';
require __DIR__.'/api_routes/chat.php';
require __DIR__.'/api_routes/documents.php';
out(['error'=>'Invalid action'],404);
