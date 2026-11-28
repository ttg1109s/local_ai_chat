$ErrorActionPreference = 'Stop'
Write-Host 'Installing Unified Local AI Manager and RAG Worker...' -ForegroundColor Cyan
& (Join-Path $PSScriptRoot 'llama-manager\install-manager.ps1')
Write-Host 'Manager starts the RAG Worker after MariaDB and its dependencies are ready.' -ForegroundColor Green
Write-Host 'Complete. LocalAIChat-LlamaManager runs when the Windows account signs in.' -ForegroundColor Green
