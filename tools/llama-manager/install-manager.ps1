$ErrorActionPreference = 'Stop'
$root = 'C:\AI\local-ai-chat-manager'
$taskName = 'LocalAIChat-LlamaManager'
New-Item -ItemType Directory -Force -Path $root | Out-Null
$cmd = Get-Command 'llama-server.exe' -ErrorAction SilentlyContinue
$exe = if($cmd){$cmd.Source}else{'C:\Users\po\AppData\Local\Microsoft\WinGet\Packages\ggml.llamacpp_Microsoft.Winget.Source_8wekyb3d8bbwe\llama-server.exe'}
if(!(Test-Path $exe)){throw "llama-server.exe not found: $exe"}
$projectRoot=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$requestPath=Join-Path $projectRoot 'storage\llama-manager\request.json'
New-Item -ItemType Directory -Force -Path (Split-Path $requestPath) | Out-Null
@{executable=$exe;request_path=$requestPath;project_root=$projectRoot;qwen_model='HauhauCS/Qwen3-4B-2507-Instruct-Uncensored-HauhauCS-Aggressive:Q4_K_M';qwen_context=16384;qwen_gpu_layers=10} | ConvertTo-Json | Set-Content -Encoding UTF8 (Join-Path $root 'manager-settings.json')
$watcher=Join-Path $root 'llama-manager-watch.ps1'
Copy-Item (Join-Path $PSScriptRoot 'llama-manager-watch.ps1') $watcher -Force
if(Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue){Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue;Unregister-ScheduledTask -TaskName $taskName -Confirm:$false}
$identity="$env:USERDOMAIN\$env:USERNAME"
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$watcher`""
$trigger=New-ScheduledTaskTrigger -AtLogOn -User $identity
$principal=New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew -StartWhenAvailable -RestartInterval (New-TimeSpan -Minutes 1) -RestartCount 999
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Host 'Unified Local AI Manager installed.' -ForegroundColor Green
Write-Host "Task: $taskName (runs automatically when the Windows account signs in)"
Write-Host "Manager: $root"
Write-Host "Project: $projectRoot"
Write-Host "llama-server: $exe"
Write-Host 'OFF stops Qwen/BGE Llama Server on ports 8080/8081 only when the configured executable and port arguments match. Other Llama Server processes and external XAMPP services are not affected.'
Write-Host 'The AIStoryWriter-LlamaManager task is unchanged. Do not run two Qwen control commands concurrently.'
