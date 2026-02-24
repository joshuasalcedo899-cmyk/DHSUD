param(
    [string]$RootCAPath = "",
    [string]$ServerIp = "192.168.1.7",
    [string]$Domain = "dhsud.local",
    [switch]$SkipHosts
)

$ErrorActionPreference = "Stop"

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-IsAdministrator)) {
    throw "Run this script in PowerShell as Administrator."
}

if ([string]::IsNullOrWhiteSpace($RootCAPath)) {
    $candidatePaths = @(
        (Join-Path (Resolve-Path (Join-Path $PSScriptRoot "..")).Path "tools\rootCA.pem"),
        (Join-Path $env:LOCALAPPDATA "mkcert\rootCA.pem")
    )

    $RootCAPath = $candidatePaths | Where-Object { Test-Path $_ } | Select-Object -First 1
}

if ([string]::IsNullOrWhiteSpace($RootCAPath) -or -not (Test-Path $RootCAPath)) {
    throw "rootCA.pem not found. Provide -RootCAPath C:\path\to\rootCA.pem"
}

Write-Host "Installing CA certificate into Trusted Root..."
& certutil.exe -addstore -f Root "$RootCAPath" | Out-Null

if (-not $SkipHosts) {
    $hostsPath = Join-Path $env:SystemRoot "System32\drivers\etc\hosts"
    $hostsLines = Get-Content -Path $hostsPath
    $escapedDomain = [regex]::Escape($Domain)
    $hasMapping = $hostsLines -match "^\s*$([regex]::Escape($ServerIp))\s+$escapedDomain(\s|$)"

    if (-not $hasMapping) {
        Add-Content -Path $hostsPath -Value "`r`n$ServerIp $Domain"
    }

    ipconfig /flushdns | Out-Null
}

Write-Host ""
Write-Host "Done."
Write-Host "Root CA: $RootCAPath"
if (-not $SkipHosts) {
    Write-Host "Hosts mapping ensured: $ServerIp $Domain"
}
Write-Host "Open: https://$Domain/pages/Admin_LogIn.php"
