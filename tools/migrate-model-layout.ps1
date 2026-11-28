$ErrorActionPreference = 'Stop'
$root = 'C:\AI\models'
$rag = Join-Path $root 'rag'
New-Item -ItemType Directory -Path $rag -Force | Out-Null

# Move the embedding model out of the chat-model root.
$bgeOld = Join-Path $root 'bge-m3-q8_0.gguf'
$bgeNew = Join-Path $rag 'bge-m3-q8_0.gguf'
if ((Test-Path -LiteralPath $bgeOld -PathType Leaf) -and !(Test-Path -LiteralPath $bgeNew)) {
    Move-Item -LiteralPath $bgeOld -Destination $bgeNew
}

# Copy chat models directly into the root. Keep Hugging Face cache originals
# until the new files have been tested successfully.
$copies = @(
    @{
        Source = 'C:\Users\po\.cache\huggingface\hub\Gemma4-E2B\gemma-4-E2B_q4_0-it.gguf'
        Destination = (Join-Path $root 'gemma-4-E2B_q4_0-it.gguf')
        SHA256 = 'FA401B55B07EE70A54C6DAE3903C783A6E65064312529EA57175CB5F8DEC6634'
    },
    @{
        Source = 'C:\Users\po\.cache\huggingface\hub\models--HauhauCS--Qwen3-4B-2507-Instruct-Uncensored-HauhauCS-Aggressive\snapshots\ce36938d2edf886ddefc0d3136744b1b6777198b\Qwen3-4B-2507-Instruct-Uncensored-HauhauCS-Aggressive-Q4_K_M.gguf'
        Destination = (Join-Path $root 'Qwen3-4B-2507-Instruct-Uncensored-HauhauCS-Aggressive-Q4_K_M.gguf')
        SHA256 = '6615B7B5184931E4DF9C6D0AE9CD29CA9319B73908D4423283D4CC401A12A1CD'
    }
)
foreach ($item in $copies) {
    if (!(Test-Path -LiteralPath $item.Source -PathType Leaf)) { throw "Missing source: $($item.Source)" }
    if (!(Test-Path -LiteralPath $item.Destination -PathType Leaf)) {
        Copy-Item -LiteralPath $item.Source -Destination $item.Destination
    }
    $hash = (Get-FileHash -LiteralPath $item.Destination -Algorithm SHA256).Hash
    if ($hash -ne $item.SHA256) { throw "SHA256 mismatch: $($item.Destination)" }
    Write-Host "Verified: $($item.Destination)" -ForegroundColor Green
}
Write-Host "Layout migration complete. HF cache originals were NOT deleted." -ForegroundColor Green
