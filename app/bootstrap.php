<?php
declare(strict_types=1);
// Harden session cookies before session_start(). Secure is enabled automatically on HTTPS.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
function lac_lang(string $key, ?string $fallback=null): string {
    $pack = $GLOBALS['LAC_LANG'] ?? [];
    return (string)($pack[$key] ?? $fallback ?? $key);
}

$config=require __DIR__.'/config.php';
date_default_timezone_set($config['timezone']);
$pdo=new PDO(
 'mysql:host='.$config['db']['host'].';dbname='.$config['db']['name'].';charset='.$config['db']['charset'],
 $config['db']['user'],$config['db']['pass'],
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);
$pdo->exec("CREATE TABLE IF NOT EXISTS conversations(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180) NOT NULL DEFAULT 'New chat',sandbox TINYINT(1) NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS messages(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,conversation_id BIGINT UNSIGNED NOT NULL,role ENUM('user','assistant','system') NOT NULL,content LONGTEXT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX(conversation_id,id),CONSTRAINT fk_messages_conversation FOREIGN KEY(conversation_id) REFERENCES conversations(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS documents(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,mime VARCHAR(120),size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS document_chunks(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,document_id BIGINT UNSIGNED NOT NULL,chunk_no INT NOT NULL,content MEDIUMTEXT NOT NULL,FULLTEXT KEY ft_content(content),INDEX(document_id),CONSTRAINT fk_chunks_document FOREIGN KEY(document_id) REFERENCES documents(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS settings(setting_key VARCHAR(120) PRIMARY KEY,setting_value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Interface language is independent from the language used by AI responses.
$langQuery=$pdo->prepare("SELECT setting_value FROM settings WHERE setting_key='interface_language' LIMIT 1");
$langQuery->execute();
$interfaceLanguage=(string)($langQuery->fetchColumn() ?: 'en');
$interfaceLanguage=$interfaceLanguage==='en'?'en':'en';
$langFile=dirname(__DIR__).'/lang/eng.php';
$GLOBALS['LAC_LANG']=require $langFile;
$GLOBALS['LAC_INTERFACE_LANGUAGE']=$interfaceLanguage;

$pdo->exec("CREATE TABLE IF NOT EXISTS model_aliases(model_id VARCHAR(512) PRIMARY KEY,alias VARCHAR(180) NOT NULL DEFAULT '',updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS model_metadata(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,model_path VARCHAR(1024) NOT NULL,path_hash CHAR(64) NOT NULL UNIQUE,file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,file_mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,scan_status VARCHAR(24) NOT NULL DEFAULT 'pending',metadata_json LONGTEXT NULL,error_text TEXT NULL,scanned_at TIMESTAMP NULL DEFAULT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_model_metadata_status(scan_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->exec('ALTER TABLE model_aliases ADD COLUMN generation_params JSON NULL'); } catch (Throwable $e) { /* Column already exists. */ }

// Vector RAG v2 tables. Legacy documents/document_chunks remain intact for rollback/history.
$pdo->exec("CREATE TABLE IF NOT EXISTS rag_documents(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,mime VARCHAR(120) NULL,size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,file_hash BINARY(32) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_rag_file_hash(file_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS canonical_chunks(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,content_hash BINARY(32) NOT NULL,content MEDIUMTEXT NOT NULL,content_chars INT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_canonical_content_hash(content_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
// Optional lexical index for hybrid vector + keyword retrieval. Existing data is indexed automatically.
try {
 $idx=$pdo->query("SHOW INDEX FROM canonical_chunks WHERE Key_name='ft_canonical_content'")->fetchAll();
 if(!$idx) $pdo->exec("ALTER TABLE canonical_chunks ADD FULLTEXT KEY ft_canonical_content(content)");
} catch(Throwable $e) {
 error_log('RAG FULLTEXT index migration skipped: '.$e->getMessage());
}

$pdo->exec("CREATE TABLE IF NOT EXISTS rag_document_chunk_map(document_id BIGINT UNSIGNED NOT NULL,chunk_no INT UNSIGNED NOT NULL,canonical_chunk_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(document_id,chunk_no),KEY idx_map_canonical_chunk(canonical_chunk_id),CONSTRAINT fk_rag_map_document FOREIGN KEY(document_id) REFERENCES rag_documents(id) ON DELETE CASCADE,CONSTRAINT fk_rag_map_chunk FOREIGN KEY(canonical_chunk_id) REFERENCES canonical_chunks(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS document_ingest_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,document_id BIGINT UNSIGNED NOT NULL,status ENUM('queued','extracting','chunking','embedding','completed','failed','cancelled') NOT NULL DEFAULT 'queued',attempts INT UNSIGNED NOT NULL DEFAULT 0,last_error TEXT NULL,started_at DATETIME NULL,completed_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_ingest_document(document_id),KEY idx_ingest_worker(status,updated_at),CONSTRAINT fk_ingest_document FOREIGN KEY(document_id) REFERENCES rag_documents(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS vector_outbox(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,canonical_chunk_id BIGINT UNSIGNED NOT NULL,action ENUM('upsert','delete') NOT NULL,status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',attempts INT UNSIGNED NOT NULL DEFAULT 0,available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,locked_at DATETIME NULL,last_error TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_outbox_worker(status,available_at,id),KEY idx_outbox_chunk_status(canonical_chunk_id,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Durable RAG leases prevent one concurrent request from stopping services used by another.
$pdo->exec("CREATE TABLE IF NOT EXISTS rag_runtime_leases(lease_key VARCHAR(190) PRIMARY KEY,lease_type VARCHAR(32) NOT NULL,owner_pid BIGINT UNSIGNED NULL,expires_at DATETIME NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_rag_lease_expiry(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Legacy global model_alias migration disabled: aliases belong to exact GGUF filenames.
$msgCols=$pdo->query("SHOW COLUMNS FROM messages LIKE 'interrupted'")->fetchAll();
if(!$msgCols){$pdo->exec("ALTER TABLE messages ADD COLUMN interrupted TINYINT(1) NOT NULL DEFAULT 0");}
// Stable model identity and request timestamp metadata; aliases are resolved at read time.
foreach(['model_id'=>"VARCHAR(512) NULL",'request_timestamp'=>"VARCHAR(40) NULL"] as $column=>$definition){$exists=$pdo->query("SHOW COLUMNS FROM messages LIKE '$column'")->fetchAll();if(!$exists){$pdo->exec("ALTER TABLE messages ADD COLUMN `$column` $definition");}}
// Persist per-message model label and generation metadata across reloads.
foreach(['model_label'=>"VARCHAR(180) NOT NULL DEFAULT ''",'elapsed_seconds'=>"INT UNSIGNED NOT NULL DEFAULT 0",'status_label'=>"VARCHAR(40) NOT NULL DEFAULT ''",'token_count'=>"BIGINT UNSIGNED NULL DEFAULT NULL"] as $column=>$definition){$exists=$pdo->query("SHOW COLUMNS FROM messages LIKE '$column'")->fetchAll();if(!$exists){$pdo->exec("ALTER TABLE messages ADD COLUMN `$column` $definition");}}

// Add per-conversation password protection without requiring a database re-import.
$cols=$pdo->query("SHOW COLUMNS FROM conversations LIKE 'password_hash'")->fetchAll();
if(!$cols){$pdo->exec("ALTER TABLE conversations ADD COLUMN password_hash VARCHAR(255) NULL");}
$_SESSION['conversation_unlocks']??=[];
$csrf=$_SESSION['csrf']??=bin2hex(random_bytes(32));
function out(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function setting(PDO $pdo,string $key,string $fallback):string{$q=$pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$q->execute([$key]);return (string)($q->fetchColumn()?:$fallback);}
function require_csrf():void{if(!hash_equals($_SESSION['csrf']??'',$_POST['_csrf']??''))out(['error'=>'Invalid CSRF token. Reload the page.'],403);}
/** Enforce conversation password protection at the API boundary, not in the UI. */
function lac_require_conversation_unlocked(PDO $pdo, int $conversationId): array {
    if ($conversationId < 1) out(['error'=>'Conversation ID is missing.'],422);
    $q=$pdo->prepare('SELECT id,password_hash FROM conversations WHERE id=?');
    $q->execute([$conversationId]);
    $conversation=$q->fetch();
    if (!$conversation) out(['error'=>'Conversation does not exist.'],404);
    $unlocked=array_map('intval', is_array($_SESSION['conversation_unlocks']??null) ? $_SESSION['conversation_unlocks'] : []);
    if (!empty($conversation['password_hash']) && !in_array($conversationId,$unlocked,true)) {
        out(['error'=>'Conversation is locked. Unlock it first.','locked'=>true],423);
    }
    return $conversation;
}
