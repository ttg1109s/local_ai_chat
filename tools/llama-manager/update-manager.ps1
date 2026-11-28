$ErrorActionPreference = 'Stop'
$taskName = 'LocalAIChat-LlamaManager'
$managerRoot = 'C:\AI\local-ai-chat-manager'
$sourceWatcher = Join-Path $PSScriptRoot 'llama-manager-watch.ps1'
$targetWatcher = Join-Path $managerRoot 'llama-manager-watch.ps1'
if (!(Test-Path $sourceWatcher)) { throw "Source watcher not found: $sourceWatcher" }
if (!(Test-Path (Join-Path $managerRoot 'manager-settings.json'))) { throw 'Unified Llama Manager is not installed. Run install-manager.ps1 first.' }
Copy-Item -LiteralPath $sourceWatcher -Destination $targetWatcher -Force
$task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if (!$task) { throw "Scheduled Task not found: $taskName" }
Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2
Start-ScheduledTask -TaskName $taskName
Start-Sleep -Seconds 2
# The updated watcher starts core services only. RAG services remain demand-driven;
# the worker will request Qdrant+BGE only when ingest/vector work exists.
$managerSettings = Get-Content -Raw (Join-Path $managerRoot 'manager-settings.json') | ConvertFrom-Json
$requestPath = [string]$managerSettings.request_path
if (!$requestPath) { throw 'manager-settings.json is missing request_path.' }
New-Item -ItemType Directory -Force -Path (Split-Path $requestPath) | Out-Null
$req = @{ id=[guid]::NewGuid().ToString('N'); created_at=(Get-Date).ToString('o'); action='restart-worker' }
$payload = $req | ConvertTo-Json -Compress
$stream = [System.IO.File]::Open($requestPath,[System.IO.FileMode]::CreateNew,[System.IO.FileAccess]::Write,[System.IO.FileShare]::None)
try { $bytes = [System.Text.Encoding]::UTF8.GetBytes($payload); $stream.Write($bytes,0,$bytes.Length) } finally { $stream.Dispose() }
Write-Host 'Watcher updated. Manager restarted and Worker replaced with the new version.' -ForegroundColor Green
Write-Host 'Qwen and core services remain running. BGE-M3/Qdrant start only when a RAG task requires them.'
Write-Host 'manager-settings.json was not overwritten. The current Qwen model and configuration were preserved.'
