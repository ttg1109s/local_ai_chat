<?php
return [
 'app_name'=>'Local AI Chat',
 'timezone'=>'Asia/Ho_Chi_Minh',
 'db'=>['host'=>'127.0.0.1','name'=>'local_ai_chat','user'=>'root','pass'=>'','charset'=>'utf8mb4'],
 'ai'=>['endpoint'=>'http://127.0.0.1:8080/v1/chat/completions','models_endpoint'=>'http://127.0.0.1:8080/v1/models','model'=>'','temperature'=>0.7,'top_p'=>0.9,'max_tokens'=>4096,'timeout'=>1200],
 'upload_dir'=>__DIR__.'/../storage/uploads','max_upload_mb'=>20,'model_dir'=>'C:/AI/models'
];
