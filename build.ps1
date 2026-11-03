# Builds dist\PrintServer.exe (no console window) and, when Inno Setup 6 is
# installed, dist\PrintServer-Setup-<version>.exe.
#
#   powershell -ExecutionPolicy Bypass -File build.ps1 -Version 0.1.0

param([string]$Version = "0.1.0")
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

if (-not (Get-Command go -ErrorAction SilentlyContinue)) {
    $env:Path = "C:\Program Files\Go\bin;$env:Path"
}

go test ./...
if ($LASTEXITCODE -ne 0) { throw "tests failed" }

New-Item -ItemType Directory -Force dist | Out-Null
go build -trimpath -ldflags "-H=windowsgui -s -w -X main.version=$Version" -o dist\PrintServer.exe .
if ($LASTEXITCODE -ne 0) { throw "build failed" }
Write-Host "Built dist\PrintServer.exe"

$iscc = @("${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe", "$env:LOCALAPPDATA\Programs\Inno Setup 6\ISCC.exe") |
    Where-Object { Test-Path $_ } | Select-Object -First 1
if ($iscc) {
    & $iscc "/DAppVersion=$Version" installer\PrintServer.iss
    if ($LASTEXITCODE -ne 0) { throw "installer build failed" }
} else {
    Write-Host "Inno Setup 6 not found; skipped the installer (winget install JRSoftware.InnoSetup)"
}
