<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(2);}
require __DIR__.'/../app/bootstrap.php';
$pdo->exec('DELETE FROM rag_runtime_leases WHERE expires_at < NOW()');
$active=(int)$pdo->query('SELECT COUNT(*) FROM rag_runtime_leases WHERE expires_at >= NOW()')->fetchColumn();
if($active>0){fwrite(STDOUT,"ACTIVE\n");exit(10);}
fwrite(STDOUT,"IDLE\n");exit(0);
