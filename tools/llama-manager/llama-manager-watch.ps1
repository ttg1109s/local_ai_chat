$ErrorActionPreference = 'Continue'
$root = 'C:\AI\local-ai-chat-manager'
$settingsPath = Join-Path $root 'manager-settings.json'
$statusPath = Join-Path $root 'status.json'
$ownedPath = Join-Path $root 'owned-processes.json'
$logPath = Join-Path $root 'manager.log'
New-Item -ItemType Directory -Force -Path $root | Out-Null

function Log([string]$message) { Add-Content -Encoding UTF8 $logPath "$(Get-Date -Format o) $message" }
function Read-Json([string]$path,[object]$fallback) { try { if(Test-Path $path){return (Get-Content -Raw $path | ConvertFrom-Json)} } catch { Log "JSON read failed $path : $_" }; return $fallback }
function Write-JsonAtomic([string]$path,[object]$value) { $tmp="${path}.tmp"; $value | ConvertTo-Json -Depth 8 | Set-Content -Encoding UTF8 $tmp; Move-Item -Force $tmp $path }
function Get-Owned { return (Read-Json $ownedPath ([pscustomobject]@{})) }
function Get-Prop($object,[string]$name) { $p=$object.PSObject.Properties[$name];if($p){return $p.Value};return $null }
function Set-Prop($object,[string]$name,$value) { $p=$object.PSObject.Properties[$name];if($p){$p.Value=$value}else{$object | Add-Member -NotePropertyName $name -NotePropertyValue $value -Force} }
function Remove-Prop($object,[string]$name) { $p=$object.PSObject.Properties[$name];if($p){$object.PSObject.Properties.Remove($name)} }
function Save-Owned($owned) { Write-JsonAtomic $ownedPath $owned }
function Get-PortProcess([int]$port) {
    $listener = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    if(!$listener){return $null}
    return Get-CimInstance Win32_Process -Filter "ProcessId = $($listener.OwningProcess)" -ErrorAction SilentlyContinue
}
function Is-DescendantOfOwned([int]$pidValue,[int]$ownedPid) {
    if($pidValue -eq $ownedPid){return $true};$all=Get-CimInstance Win32_Process -ErrorAction SilentlyContinue;$current=$pidValue
    for($i=0;$i -lt 12;$i++){$p=$all|Where-Object{[int]$_.ProcessId -eq $current}|Select-Object -First 1;if(!$p){return $false};$current=[int]$p.ParentProcessId;if($current -eq $ownedPid){return $true};if($current -le 4){return $false}}
    return $false
}
function Test-Http([string]$url) { try { $r=Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 3; return ($r.StatusCode -ge 200 -and $r.StatusCode -lt 500) } catch { return $false } }
function Test-MariaDB { $admin='C:\xampp\mysql\bin\mysqladmin.exe';if(!(Test-Path $admin)){return $false};try{$null=& $admin -h 127.0.0.1 -u root ping 2>&1;return ($LASTEXITCODE -eq 0)}catch{return $false} }
function Get-ServiceSnapshot {
    $owned=Get-Owned; $spec=@(
      @{key='mariadb';name='MariaDB';port=3306;url='';exe='C:\xampp\mysql\bin\mysqld.exe'},
      @{key='qdrant';name='Qdrant';port=6333;url='http://127.0.0.1:6333/collections';exe='C:\AI\qdrant\bin\qdrant.exe'},
      @{key='bge';name='BGE-M3';port=8081;url='http://127.0.0.1:8081/health';exe=[string]$settings.executable},
      @{key='qwen';name='Qwen';port=8080;url='http://127.0.0.1:8080/health';exe=[string]$settings.executable},
      @{key='apache';name='Apache';port=80;url='http://127.0.0.1/local_ai_chat/';exe='C:\xampp\apache\bin\httpd.exe'},
      @{key='ragworker';name='RAG Worker';port=0;url='';exe='C:\xampp\php\php.exe'}
    )
    $result=@{}
    foreach($s in $spec){$proc=if($s.key -eq 'ragworker'){$workerPid=Get-Prop $owned 'ragworker';if($workerPid){$wp=Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$workerPid)" -ErrorAction SilentlyContinue;if($wp -and $wp.ExecutablePath -ieq 'C:\xampp\php\php.exe' -and $wp.CommandLine -match 'rag-worker\.php'){Get-Process -Id ([int]$workerPid) -ErrorAction SilentlyContinue}}}else{Get-PortProcess $s.port};$running=$false;$healthy=$false;$pidValue=$null;$owner='none';$detail='Not running'
      if($proc){$running=$true;$pidValue=if($s.key -eq 'ragworker'){[int]$proc.Id}else{[int]$proc.ProcessId};$healthy=if($s.key -eq 'mariadb'){Test-MariaDB}elseif($s.key -eq 'ragworker'){$true}elseif($s.url){Test-Http $s.url}else{$true};$ownedPid=Get-Prop $owned $s.key;$owner=if($ownedPid -and ($s.key -eq 'ragworker' -or (Is-DescendantOfOwned $pidValue ([int]$ownedPid)) )){'manager'}else{'external'};$detail=if($healthy){'Ready'}else{'A listener exists, but the health check has not passed.'}}
      $result[$s.key]=@{name=$s.name;running=$running;healthy=$healthy;pid=$pidValue;owner=$owner;detail=$detail;port=$s.port}
    }
    return $result
}
function Write-TransactionResult([string]$id,[string]$state,[string]$message,[object]$extra=$null) {
    if(!$id -or $id -notmatch '^[a-f0-9]{16,64}$'){return}
    $dir=Join-Path $root 'transactions'
    New-Item -ItemType Directory -Force -Path $dir | Out-Null
    $obj=@{id=$id;state=$state;message=$message;updated_at=(Get-Date).ToString('o')}
    if($null -ne $extra){$obj.details=$extra}
    Write-JsonAtomic (Join-Path $dir ($id+'.json')) $obj
}
function Save-Status([string]$message='') {
    $services=Get-ServiceSnapshot
    $obj=@{updated_at=(Get-Date).ToString('o');message=$message;services=$services}
    Write-JsonAtomic $statusPath $obj
}
function Wait-Ready([string]$key,[int]$port,[string]$url,[int]$processId,[int]$seconds=120) {
    for($i=0;$i -lt $seconds;$i+=2){
        Start-Sleep -Seconds 2
        $proc=Get-Process -Id $processId -ErrorAction SilentlyContinue
        if(!$proc){throw "${key} exited immediately after launch (PID ${processId}). Check manager.log."}
        $listener=Get-PortProcess $port
        if($listener -and [int]$listener.ProcessId -eq $processId -and (!$url -or (Test-Http $url))){return $true}
    }
    throw "${key} did not become ready within ${seconds} seconds."
}
function Test-QwenModelIdentity([string]$expected) {
    if(!$expected){return $false}
    $expectedNorm=$expected.Replace('/','\').TrimEnd('\')
    for($attempt=0;$attempt -lt 15;$attempt++){
        try {
            $props=Invoke-RestMethod -Uri 'http://127.0.0.1:8080/props' -TimeoutSec 5
            $models=Invoke-RestMethod -Uri 'http://127.0.0.1:8080/v1/models' -TimeoutSec 5
            $actuals=@([string]$props.model_path,[string]$props.model_alias,[string]$models.data[0].id)
            foreach($actual in $actuals){
                if(!$actual){continue}
                if($actual -ieq $expected){return $true}
                $actualNorm=$actual.Replace('/','\').TrimEnd('\')
                if([IO.Path]::IsPathRooted($expectedNorm) -and [IO.Path]::IsPathRooted($actualNorm) -and $actualNorm -ieq $expectedNorm){return $true}
                if($expectedNorm -match '\.gguf$' -and [IO.Path]::GetFileName($actualNorm) -ieq [IO.Path]::GetFileName($expectedNorm)){return $true}
                if($expected.Contains('/') -and $actual -like ('*'+$expected)){return $true}
            }
        } catch { Log "Qwen model identity check attempt $attempt failed: $_" }
        Start-Sleep -Seconds 2
    }
    return $false
}
function Start-One([string]$key,$request=$null) {
    $owned=Get-Owned;$snap=Get-ServiceSnapshot
    $ports=@{mariadb=3306;qdrant=6333;bge=8081;qwen=8080;apache=80;ragworker=0};$port=$ports[$key]
    if($key -eq 'qwen' -or $key -eq 'bge'){
        $activeListener=Get-PortProcess $port
        if($activeListener){
            $activeInfo=Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$activeListener.ProcessId)" -ErrorAction SilentlyContinue
            $activePortPattern='(?:--port\s+|--port=)'+$port+'(?:\s|$)'
            if(!$activeInfo -or $activeInfo.ExecutablePath -ine [string]$settings.executable -or $activeInfo.Name -ine 'llama-server.exe' -or $activeInfo.CommandLine -notmatch $activePortPattern){
                throw "Port ${port} is occupied by another process. Cannot verify model ${key} status."
            }
        }
    }
    if($snap[$key].running){Log "$key already running; owner=$($snap[$key].owner), pid=$($snap[$key].pid)";return}
    if(($key -eq 'qwen' -or $key -eq 'bge') -and (Get-PortProcess $port)){
        throw "Port ${port} still has a listener. Starting another ${key} process was refused."
    }
    $exe='';$procArgs=@();$url='';$work='C:\'
    switch($key){
      'mariadb' {$exe='C:\xampp\mysql\bin\mysqld.exe';$procArgs=@('--defaults-file="C:\xampp\mysql\bin\my.ini"','--standalone');$url=''}
      'qdrant' {$exe='C:\AI\qdrant\bin\qdrant.exe';$procArgs=@('--config-path','C:\AI\qdrant\config.yaml');$url='http://127.0.0.1:6333/collections';$work='C:\AI\qdrant'}
      'bge' {$exe=[string]$settings.executable;$procArgs=@('--model','C:\AI\models\rag\bge-m3-q8_0.gguf','--embedding','--batch-size','2048','--ubatch-size','1024','--device','none','--n-gpu-layers','0','--host','127.0.0.1','--port','8081','--no-webui');$url='http://127.0.0.1:8081/health';$work=Split-Path $exe}
      'qwen' {$exe=[string]$settings.executable;$model=[string]$settings.qwen_model;$ctx=if($settings.qwen_context){[int]$settings.qwen_context}else{16384};$gpu=if($null -ne $settings.qwen_gpu_layers){[int]$settings.qwen_gpu_layers}else{10};if($request){if($request.model){$model=[string]$request.model};if($request.context){$ctx=[int]$request.context};if($null -ne $request.gpu_layers){$gpu=[int]$request.gpu_layers}};if(!$model){throw 'Qwen model is not configured.'};if(Test-Path $model -PathType Leaf){$procArgs=@('-m',('"' + $model + '"'))}else{$procArgs=@('-hf',$model)};$procArgs+=@('--host','127.0.0.1','--port','8080','-c',"$ctx",'-ngl',"$gpu",'--jinja');$url='http://127.0.0.1:8080/health';$work=Split-Path $exe}
      'apache' {$exe='C:\xampp\apache\bin\httpd.exe';$procArgs=@('-d','C:/xampp/apache');$url='http://127.0.0.1/local_ai_chat/';$work='C:\xampp\apache\bin'}
      'ragworker' {$exe='C:\xampp\php\php.exe';$worker=Join-Path ([string]$settings.project_root) 'tools\rag-worker.php';$procArgs=@(('"' + $worker + '"'));$url='';$work=[string]$settings.project_root}
    }
    if(!(Test-Path $exe)){throw "Executable not found for $key: $exe"}
    if($key -eq 'bge' -and !(Test-Path 'C:\AI\models\rag\bge-m3-q8_0.gguf')){throw 'BGE-M3 model not found.'}
    if($key -eq 'qdrant' -and !(Test-Path 'C:\AI\qdrant\config.yaml')){throw 'C:\AI\qdrant\config.yaml not found.'}
    if($key -eq 'mariadb' -and !(Test-Path 'C:\xampp\mysql\bin\my.ini')){throw 'MariaDB my.ini configuration not found.'}
    if($key -eq 'apache'){& $exe -t -d 'C:/xampp/apache' 2>&1 | Out-Null;if($LASTEXITCODE -ne 0){throw 'Apache configuration is invalid. Startup was refused.'}}
    $out=Join-Path $root "${key}.stdout.log";$err=Join-Path $root "${key}.stderr.log"
    $proc=Start-Process -FilePath $exe -ArgumentList $procArgs -WorkingDirectory $work -WindowStyle Hidden -PassThru -RedirectStandardOutput $out -RedirectStandardError $err
    Set-Prop $owned $key ([int]$proc.Id);Save-Owned $owned;Log "Started $key PID=$($proc.Id)"
    if($key -eq 'ragworker'){Start-Sleep -Seconds 2;if(!(Get-Process -Id $proc.Id -ErrorAction SilentlyContinue)){throw 'RAG Worker exited after startup.'}}else{Wait-Ready $key $port $url ([int]$proc.Id) $(if($key -eq 'qwen'){240}else{90}) | Out-Null}
}
function Stop-ProcessTree([int]$rootPid) {
    $all=Get-CimInstance Win32_Process -ErrorAction SilentlyContinue;$ids=New-Object System.Collections.Generic.List[int];$ids.Add($rootPid)
    for($i=0;$i -lt $ids.Count;$i++){foreach($child in ($all | Where-Object { [int]$_.ParentProcessId -eq $ids[$i] })){if(!$ids.Contains([int]$child.ProcessId)){$ids.Add([int]$child.ProcessId)}}}
    for($i=$ids.Count-1;$i -ge 0;$i--){Stop-Process -Id $ids[$i] -ErrorAction SilentlyContinue}
}
function Stop-LlamaService([string]$key) {
    # Fail closed: never permit a replacement model to start until every old
    # llama-server PID is gone and the dedicated TCP port is confirmed free.
    $port=if($key -eq 'qwen'){8080}else{8081}
    $expected=[string]$settings.executable
    if(!$expected -or !(Test-Path $expected)){
        throw "Cannot stop ${key}: configured llama-server executable is missing ($expected)."
    }

    $listenerIds=@(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique)
    $owned=Get-Owned
    $ownedPid=Get-Prop $owned $key
    if($ownedPid){$listenerIds+=([int]$ownedPid)}
    $targets=@($listenerIds | Where-Object {$_ -and [int]$_ -gt 0} | Select-Object -Unique)
    $portPattern='(?:--port\s+|--port=)'+$port+'(?:\s|$)'

    foreach($targetPid in $targets){
        $targetPid=[int]$targetPid
        $info=Get-CimInstance Win32_Process -Filter "ProcessId = $targetPid" -ErrorAction SilentlyContinue
        if(!$info){continue}
        # The port + exact executable identify the service. The old model name is
        # intentionally not checked: it must differ from the requested new model.
        if($info.ExecutablePath -ine $expected -or $info.Name -ine 'llama-server.exe' -or $info.CommandLine -notmatch $portPattern){
            Log "ERROR: Refusing unsafe stop for ${key} PID=${targetPid}: executable/port identity mismatch."
            throw "Could not verify the old ${key} process (PID ${targetPid}). Model switching was stopped to prevent RAM/VRAM overcommit."
        }

        Log "Stopping Local AI llama-server ${key} PID=${targetPid} port=${port}"
        Stop-Process -Id $targetPid -ErrorAction SilentlyContinue
        for($i=0;$i -lt 10;$i++){
            Start-Sleep -Seconds 1
            if(!(Get-Process -Id $targetPid -ErrorAction SilentlyContinue)){break}
        }
        if(Get-Process -Id $targetPid -ErrorAction SilentlyContinue){
            Log "Graceful stop timed out for ${key} PID=${targetPid}; forcing termination."
            Stop-Process -Id $targetPid -Force -ErrorAction SilentlyContinue
        }
        for($i=0;$i -lt 10;$i++){
            Start-Sleep -Seconds 1
            if(!(Get-Process -Id $targetPid -ErrorAction SilentlyContinue)){break}
        }
        if(Get-Process -Id $targetPid -ErrorAction SilentlyContinue){
            Log "ERROR: ${key} PID=${targetPid} is still alive after force-stop."
            throw "Could not release the old model process ${key} (PID ${targetPid}). The new model was not loaded."
        }
        Log "Stopped Local AI llama-server ${key} PID=${targetPid}."
    }

    # Re-query the kernel TCP table; do not trust only the old PID list.
    $remaining=@(Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -ExpandProperty OwningProcess -Unique)
    if($remaining.Count -gt 0){
        $remainingText=($remaining -join ', ')
        Log "ERROR: ${key} port ${port} remains occupied by PID(s): ${remainingText}."
        throw "Port ${port} is still used by PID ${remainingText}. Loading the new model was refused."
    }

    Remove-Prop $owned $key
    Save-Owned $owned
    Log "Confirmed ${key}: old process exited and port ${port} is free. Safe to load replacement model."
}
function Stop-One([string]$key) {
    if($key -eq 'qwen' -or $key -eq 'bge'){Stop-LlamaService $key;return}
    $owned=Get-Owned;$pidValue=(Get-Prop $owned $key)
    if(!$pidValue){Log "Skip stop ${key}: process is not owned by this manager.";return}
    $proc=Get-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue
    if(!$proc){Remove-Prop $owned $key;Save-Owned $owned;return}
    $info=Get-CimInstance Win32_Process -Filter "ProcessId = $([int]$pidValue)" -ErrorAction SilentlyContinue
    $expected= switch($key){'mariadb'{'C:\xampp\mysql\bin\mysqld.exe'} 'qdrant'{'C:\AI\qdrant\bin\qdrant.exe'} 'bge'{[string]$settings.executable} 'qwen'{[string]$settings.executable} 'apache'{'C:\xampp\apache\bin\httpd.exe'} 'ragworker'{'C:\xampp\php\php.exe'}}
    if(!$info -or $info.ExecutablePath -ine $expected -or ($key -eq 'ragworker' -and $info.CommandLine -notmatch 'rag-worker\.php') -or ($key -eq 'qwen' -and $info.CommandLine -notmatch '--port\s+8080') -or ($key -eq 'bge' -and $info.CommandLine -notmatch '--port\s+8081')){Log "Refuse to stop ${key} PID=${pidValue}: process identity no longer matches.";Remove-Prop $owned $key;Save-Owned $owned;return}
    if($key -eq 'mariadb'){
      $admin='C:\xampp\mysql\bin\mysqladmin.exe';if(Test-Path $admin){try{& $admin -h 127.0.0.1 -u root shutdown 2>$null}catch{}}
    } elseif($key -eq 'apache') { Stop-ProcessTree ([int]$pidValue) } else {
      Stop-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue
    }
    for($i=0;$i -lt 10;$i++){Start-Sleep -Seconds 1;if(!(Get-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue)){break}}
    if(Get-Process -Id ([int]$pidValue) -ErrorAction SilentlyContinue){Stop-Process -Id ([int]$pidValue) -Force -ErrorAction SilentlyContinue}
    Remove-Prop $owned $key;Save-Owned $owned;Log "Stopped owned $key PID=$pidValue"
}
function Test-RagLeaseActive {
    $php='C:\xampp\php\php.exe';$checker=Join-Path ([string]$settings.project_root) 'tools\rag-lease-check.php'
    if(!(Test-Path $php) -or !(Test-Path $checker)){Log 'RAG lease check unavailable; refusing to stop BGE/Qdrant (fail-safe).';return $true}
    try {& $php $checker | Out-Null;$code=$LASTEXITCODE;return ($code -ne 0)} catch {Log "RAG lease check failed; refusing shutdown: $_";return $true}
}
function Stop-RAG-Stack-Safe {
    if(Test-RagLeaseActive){Log 'RAG stack shutdown deferred: active lease exists or lease check failed.';return}
    Stop-One 'bge';Stop-One 'qdrant';Log 'RAG stack stopped after lease check.'
}
function Start-All($request=$null) {
    foreach($key in @('mariadb','qwen','apache')){try{Start-One $key $request}catch{Log "Start $key failed: $_"}}
    try{Start-One 'ragworker'}catch{Log "Start ragworker failed: $_"}
}
function Stop-All { foreach($key in @('ragworker','apache','qwen','bge','qdrant','mariadb')){try{Stop-One $key}catch{Log "Stop $key failed: $_"}} }

$settings=Read-Json $settingsPath ([pscustomobject]@{})
if(!$settings.executable){$settings | Add-Member -NotePropertyName executable -NotePropertyValue 'C:\Users\po\AppData\Local\Microsoft\WinGet\Packages\ggml.llamacpp_Microsoft.Winget.Source_8wekyb3d8bbwe\llama-server.exe' -Force}
if(!$settings.qwen_model){$settings | Add-Member -NotePropertyName qwen_model -NotePropertyValue 'HauhauCS/Qwen3-4B-2507-Instruct-Uncensored-HauhauCS-Aggressive:Q4_K_M' -Force}
if(!$settings.request_path){$settings | Add-Member -NotePropertyName request_path -NotePropertyValue 'C:\xampp\htdocs\local_ai_chat\storage\llama-manager\request.json' -Force}
if(!$settings.project_root){$settings | Add-Member -NotePropertyName project_root -NotePropertyValue 'C:\xampp\htdocs\local_ai_chat' -Force}
if($null -eq $settings.bge_on_demand_v18){
    # One-time migration from V17: its default bge_enabled=true meant auto-start.
    # V18 resets that legacy default; later explicit UI starts remain pinned ON.
    if($null -eq $settings.bge_enabled){$settings | Add-Member -NotePropertyName bge_enabled -NotePropertyValue $false -Force}else{$settings.bge_enabled=$false}
    $settings | Add-Member -NotePropertyName bge_on_demand_v18 -NotePropertyValue $true -Force
}elseif($null -eq $settings.bge_enabled){$settings | Add-Member -NotePropertyName bge_enabled -NotePropertyValue $false -Force}
Write-JsonAtomic $settingsPath $settings
Log 'Unified Local AI manager started.'
# Start missing local infrastructure automatically at user sign-in. Qwen/BGE listeners on the dedicated Local AI ports are managed by exact executable + port identity.
$autoEnsure=$true;$lastEnsure=Get-Date
Save-Status 'Manager is starting; Local AI Qwen/BGE instances are identified by executable and dedicated ports.'
# Do not force-stop an existing RAG process during Manager restart; the lease-aware worker performs idle shutdown.
Start-All
Save-Status 'Manager started; BGE-M3 is demand-driven and Qwen remains available.'
while($true){
    try {
      $reqPath=[string]$settings.request_path
      if($reqPath -and (Test-Path $reqPath)){
        $processing="${reqPath}.processing"
        try {Move-Item -LiteralPath $reqPath -Destination $processing -ErrorAction Stop;$request=Get-Content -Raw $processing | ConvertFrom-Json
          $action=[string]$request.action
          switch($action){
            'start' {if($request.model){$settings.qwen_model=[string]$request.model};if($request.context){$settings.qwen_context=[int]$request.context};if($null -ne $request.gpu_layers){$settings.qwen_gpu_layers=[int]$request.gpu_layers};Write-JsonAtomic $settingsPath $settings;Start-One 'qwen' $request}
            'restart' {
              $txId=[string]$request.id
              $oldModel=[string]$settings.qwen_model
              $oldContext=if($settings.qwen_context){[int]$settings.qwen_context}else{16384}
              $oldGpu=if($null -ne $settings.qwen_gpu_layers){[int]$settings.qwen_gpu_layers}else{10}
              try {
                if($request.model){$settings.qwen_model=[string]$request.model}
                if($request.context){$settings.qwen_context=[int]$request.context}
                if($null -ne $request.gpu_layers){$settings.qwen_gpu_layers=[int]$request.gpu_layers}
                Write-JsonAtomic $settingsPath $settings
                Stop-One 'qwen'
                Start-One 'qwen' $request
                if(!(Test-QwenModelIdentity ([string]$settings.qwen_model))){throw "Qwen health endpoint is ready, but the loaded model identity could not be verified for '$($settings.qwen_model)'."}
                Write-TransactionResult $txId 'committed' 'The requested model process started and passed the Manager health check.' @{model=[string]$settings.qwen_model;context=[int]$settings.qwen_context;gpu_layers=[int]$settings.qwen_gpu_layers;previous_model=$oldModel;previous_context=$oldContext;previous_gpu_layers=$oldGpu}
              } catch {
                $switchError=[string]$_
                Log "Model switch failed; beginning rollback. transaction=$txId error=$switchError"
                try {
                  try { Stop-One 'qwen' } catch { Log "Rollback cleanup warning: $_" }
                  $settings.qwen_model=$oldModel
                  $settings.qwen_context=$oldContext
                  $settings.qwen_gpu_layers=$oldGpu
                  Write-JsonAtomic $settingsPath $settings
                  if($oldModel){Start-One 'qwen' @{model=$oldModel;context=$oldContext;gpu_layers=$oldGpu};if(!(Test-QwenModelIdentity $oldModel)){throw "Previous model process started, but its model identity could not be verified ($oldModel)."}}
                  Write-TransactionResult $txId 'rolled_back' "New model failed: $switchError Previous model was restored and passed the Manager health check." @{model=$oldModel;context=$oldContext;gpu_layers=$oldGpu;failure=$switchError}
                } catch {
                  $rollbackError=[string]$_
                  Log "CRITICAL: model rollback failed. transaction=$txId original=$switchError rollback=$rollbackError"
                  Write-TransactionResult $txId 'rollback_failed' "New model failed: $switchError. Previous model could not be restored: $rollbackError" @{previous_model=$oldModel;failure=$switchError;rollback_failure=$rollbackError}
                }
              }
            }
            'restart-bge' {$settings.bge_enabled=$true;Write-JsonAtomic $settingsPath $settings;Stop-One 'bge';Start-One 'bge'}
            'start-bge' {$settings.bge_enabled=$true;Write-JsonAtomic $settingsPath $settings;Start-One 'bge'}
            'ensure-bge' {Start-One 'bge'}
            'restart-worker' {Stop-One 'ragworker';Start-One 'ragworker'}
            'ensure-rag-stack' {Start-One 'qdrant';Start-One 'bge'}
            'stop-rag-stack' {Stop-RAG-Stack-Safe}
            'stop-bge' {$settings.bge_enabled=$false;Write-JsonAtomic $settingsPath $settings;Stop-One 'bge'}
            'stop' {Stop-One 'qwen'}
            'start-all' {$autoEnsure=$true;if($request.model){$settings.qwen_model=[string]$request.model};if($request.context){$settings.qwen_context=[int]$request.context};if($null -ne $request.gpu_layers){$settings.qwen_gpu_layers=[int]$request.gpu_layers};Write-JsonAtomic $settingsPath $settings;Start-All $request}
            'restart-all' {$autoEnsure=$true;if($request.model){$settings.qwen_model=[string]$request.model};if($request.context){$settings.qwen_context=[int]$request.context};if($null -ne $request.gpu_layers){$settings.qwen_gpu_layers=[int]$request.gpu_layers};Write-JsonAtomic $settingsPath $settings;Stop-One 'qwen';Start-All $request}
            'stop-all' {$autoEnsure=$false;Stop-All}
            default {throw "Invalid Manager command: $action"}
          }
          Log "Completed request $action";Remove-Item $processing -Force -ErrorAction SilentlyContinue
        } catch {Log "Request error: $_";if(Test-Path $processing){Remove-Item $processing -Force -ErrorAction SilentlyContinue}}
      }
      if($autoEnsure -and ((Get-Date)-$lastEnsure).TotalSeconds -ge 60){Start-All;$lastEnsure=Get-Date}
      Save-Status
    } catch {Log "Watch loop error: $_"}
    Start-Sleep -Seconds 3
}
