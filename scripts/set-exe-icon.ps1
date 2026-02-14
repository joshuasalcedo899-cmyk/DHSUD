param(
    [string]$ExePath = ".\dist\DHSUDR4A_MailTracking.exe",
    [string]$IconPath = ".\assets\DHSUDLogo.ico"
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path $ExePath)) {
    throw "EXE not found: $ExePath"
}

if (-not (Test-Path $IconPath)) {
    throw "Icon file not found: $IconPath"
}

$exeFull = (Resolve-Path $ExePath).Path
$iconFull = (Resolve-Path $IconPath).Path

$runningExe = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
    Where-Object { $_.ExecutablePath -and $_.ExecutablePath -ieq $exeFull }
if ($runningExe) {
    throw "Target EXE is currently running. Close it first, then retry: $exeFull"
}

$npxCandidates = @(
    (Get-Command npx -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue),
    "C:\Program Files\nodejs\npx.cmd",
    "C:\Program Files (x86)\nodejs\npx.cmd"
) | Where-Object { $_ -and (Test-Path $_) }

if ($npxCandidates.Count -eq 0) {
    throw "npx not found. Install Node.js first."
}

$npxCmd = [string]($npxCandidates | Select-Object -First 1)
$npxCmd = $npxCmd.Trim('"')
$npmCmd = (Get-Command npm -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue)
if (-not $npmCmd -or -not (Test-Path $npmCmd)) {
    if (Test-Path "C:\Program Files\nodejs\npm.cmd") { $npmCmd = "C:\Program Files\nodejs\npm.cmd" }
    elseif (Test-Path "C:\Program Files (x86)\nodejs\npm.cmd") { $npmCmd = "C:\Program Files (x86)\nodejs\npm.cmd" }
}
if (-not $npmCmd -or -not (Test-Path $npmCmd)) {
    throw "npm.cmd not found. Install Node.js first."
}

Write-Host "Setting icon..."
Write-Host "EXE : $exeFull"
Write-Host "ICON: $iconFull"

# Ensure local rcedit package exists.
if (-not (Test-Path ".\node_modules\rcedit")) {
    Write-Host "Installing local rcedit..."
    & $npmCmd install --no-save rcedit
    if ($LASTEXITCODE -ne 0) {
        throw "Failed to install rcedit via npm."
    }
}

# node-rcedit package ships binaries in node_modules\rcedit\bin (no .bin shim).
$rceditCandidates = @(
    ".\node_modules\rcedit\bin\rcedit-x64.exe",
    ".\node_modules\rcedit\bin\rcedit.exe"
) | Where-Object { Test-Path $_ }

if ($rceditCandidates.Count -eq 0) {
    throw "rcedit executable not found under node_modules\\rcedit\\bin."
}

$rceditExe = (Resolve-Path ($rceditCandidates | Select-Object -First 1)).Path
& $rceditExe $exeFull --set-icon $iconFull
$exitCode = $LASTEXITCODE

if ($exitCode -ne 0) {
    throw "rcedit failed with exit code $exitCode."
}

Write-Host "Done. Icon updated."
