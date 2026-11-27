param(
    [string]$PythonPath = ""
)
$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($PythonPath)) {
    if ($env:LOCAL_AI_PYTHON -and (Test-Path $env:LOCAL_AI_PYTHON)) {
        $PythonPath = $env:LOCAL_AI_PYTHON
    } else {
        $cmd = Get-Command python.exe -ErrorAction SilentlyContinue
        if ($cmd) { $PythonPath = $cmd.Source }
    }
}
if ([string]::IsNullOrWhiteSpace($PythonPath) -or !(Test-Path $PythonPath)) {
    throw "Khong tim thay python.exe. Hay truyen -PythonPath hoac dat LOCAL_AI_PYTHON."
}

$version = & $PythonPath -c "import sys; print(f'{sys.version_info.major}.{sys.version_info.minor}')"
if ($version -ne "3.13") {
    throw "Can Python 3.13, nhung interpreter nay la $version : $PythonPath"
}

Write-Host "Python: $PythonPath"
& $PythonPath -m pip install --upgrade pip
if ($LASTEXITCODE -ne 0) { throw "pip upgrade that bai." }
& $PythonPath -m pip install --upgrade playwright
if ($LASTEXITCODE -ne 0) { throw "Cai dat Playwright that bai." }
& $PythonPath -m playwright install msedge
if ($LASTEXITCODE -ne 0) {
    Write-Warning "Playwright khong cai duoc Edge. Neu Microsoft Edge da co san, tiep tuc va kiem tra bang nut Kiem tra in Settings."
}

$env:LOCAL_AI_PYTHON = $PythonPath
[Environment]::SetEnvironmentVariable("LOCAL_AI_PYTHON", $PythonPath, "User")
Write-Host ""
Write-Host "Da cau hinh LOCAL_AI_PYTHON for tai khoan Windows hien tai."
Write-Host "Hay khoi dong lai Apache in XAMPP, sau do vao Cau hinh > Website va bam Kiem tra."
Write-Host "Luu y: Apache chay bang Windows Service co the dung tai khoan khac; tai khoan do cung phai co quyen chay Python va Edge."
