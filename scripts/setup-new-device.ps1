param(
    [string]$Domain = "dhsud.local",
    [string]$XamppRoot = "C:\xampp",
    [string]$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path,
    [string]$LanIp = "",
    [switch]$SkipDependencies,
    [switch]$SkipCertificateSetup,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Write-Step([string]$Message) {
    Write-Host "`n== $Message ==" -ForegroundColor Cyan
}

function To-ApachePath([string]$PathValue) {
    return ($PathValue -replace "\\", "/")
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Invoke-Action([string]$Label, [scriptblock]$Action) {
    if ($DryRun) {
        Write-Host "[DryRun] $Label"
        return
    }
    Write-Host $Label
    & $Action
}

function Get-PreferredLanIPv4 {
    try {
        $route = Get-NetRoute -DestinationPrefix "0.0.0.0/0" -ErrorAction Stop |
            Where-Object { $_.NextHop -ne "0.0.0.0" } |
            Sort-Object RouteMetric, ifMetric |
            Select-Object -First 1

        if ($route) {
            $ipObj = Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $route.InterfaceIndex -ErrorAction Stop |
                Where-Object { $_.IPAddress -ne "127.0.0.1" -and $_.IPAddress -notlike "169.254.*" } |
                Select-Object -First 1
            if ($ipObj) {
                return $ipObj.IPAddress
            }
        }
    } catch {}

    try {
        $fallback = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
            Where-Object {
                $_.IPAddress -ne "127.0.0.1" -and
                $_.IPAddress -notlike "169.254.*" -and
                $_.PrefixOrigin -ne "WellKnown"
            } |
            Select-Object -First 1
        if ($fallback) {
            return $fallback.IPAddress
        }
    } catch {}

    return $null
}

Write-Host "DHSUD new-device setup"
Write-Host "ProjectRoot: $ProjectRoot"
Write-Host "Domain: $Domain"

if ([string]::IsNullOrWhiteSpace($LanIp)) {
    $LanIp = Get-PreferredLanIPv4
}

if ([string]::IsNullOrWhiteSpace($LanIp)) {
    Write-Warning "Could not auto-detect LAN IP. Certificate will not include a LAN IP unless -LanIp is provided."
} else {
    Write-Host "LAN IP: $LanIp"
}

if (-not (Test-IsAdministrator)) {
    throw "Run this script in PowerShell as Administrator."
}

$httpdConfPath = Join-Path $XamppRoot "apache\conf\httpd.conf"
$vhostsConfPath = Join-Path $XamppRoot "apache\conf\extra\httpd-vhosts.conf"
$httpdExePath = Join-Path $XamppRoot "apache\bin\httpd.exe"
$hostsPath = Join-Path $env:SystemRoot "System32\drivers\etc\hosts"

if (-not (Test-Path $httpdConfPath)) { throw "Missing file: $httpdConfPath" }
if (-not (Test-Path $vhostsConfPath)) { throw "Missing file: $vhostsConfPath" }
if (-not (Test-Path $httpdExePath)) { throw "Missing file: $httpdExePath" }

$projectResolved = (Resolve-Path $ProjectRoot).Path
$projectApache = To-ApachePath $projectResolved
$htdocsResolved = (Resolve-Path (Join-Path $XamppRoot "htdocs")).Path
$htdocsApache = To-ApachePath $htdocsResolved

if (-not $projectResolved.StartsWith($htdocsResolved, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "ProjectRoot must be inside $htdocsResolved so URLs work correctly."
}

$relativeWebPath = $projectResolved.Substring($htdocsResolved.Length).TrimStart("\\")
$relativeWebPath = ($relativeWebPath -replace "\\", "/")
if ([string]::IsNullOrWhiteSpace($relativeWebPath)) {
    $localHttpBase = "http://localhost"
} else {
    $localHttpBase = "http://localhost/$relativeWebPath"
}
$lanHttpsBase = ""

$certPath = Join-Path $projectResolved "tools\dhsud-local.pem"
$keyPath = Join-Path $projectResolved "tools\dhsud-local-key.pem"
$certApache = To-ApachePath $certPath
$keyApache = To-ApachePath $keyPath

Write-Step "1) Configure hosts file"
$hostsLines = Get-Content -Path $hostsPath
$domainEscaped = [regex]::Escape($Domain)
$needsIpv4 = -not ($hostsLines -match "^\s*127\.0\.0\.1\s+$domainEscaped(\s|$)")
$needsIpv6 = -not ($hostsLines -match "^\s*::1\s+$domainEscaped(\s|$)")

if ($needsIpv4 -or $needsIpv6) {
    $newLines = @()
    if ($needsIpv4) { $newLines += "127.0.0.1 $Domain" }
    if ($needsIpv6) { $newLines += "::1 $Domain" }
    Invoke-Action "Updating hosts file: $hostsPath" {
        Add-Content -Path $hostsPath -Value ("`r`n" + ($newLines -join "`r`n"))
    }
} else {
    Write-Host "Hosts file already has $Domain"
}

Write-Step "2) Configure Apache core settings"
$httpdConf = Get-Content -Raw -Path $httpdConfPath
$httpdOriginal = $httpdConf

$httpdConf = [regex]::Replace($httpdConf, '(?m)^\s*#\s*(LoadModule\s+rewrite_module\s+modules/mod_rewrite\.so)\s*$', '$1')
$httpdConf = [regex]::Replace($httpdConf, '(?m)^\s*#\s*(LoadModule\s+ssl_module\s+modules/mod_ssl\.so)\s*$', '$1')
$httpdConf = [regex]::Replace($httpdConf, '(?m)^\s*#\s*(Include\s+conf/extra/httpd-vhosts\.conf)\s*$', '$1')

if ($httpdConf -notmatch '(?m)^\s*Listen\s+443\s*$') {
    $httpdConf = $httpdConf.TrimEnd() + "`r`nListen 443`r`n"
}

if ($httpdConf -ne $httpdOriginal) {
    Invoke-Action "Writing Apache config: $httpdConfPath" {
        Set-Content -Path $httpdConfPath -Value $httpdConf -Encoding ASCII
    }
} else {
    Write-Host "Apache core config already up to date"
}

Write-Step "3) Configure Apache virtual hosts"
$blockStart = "# BEGIN DHSUD_TRANSFER_SETUP"
$blockEnd = "# END DHSUD_TRANSFER_SETUP"

$vhostBlock = @"
$blockStart
<VirtualHost *:80>
    ServerName localhost
    ServerAlias 127.0.0.1
    DocumentRoot "$htdocsApache"
    <Directory "$htdocsApache">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:443>
    ServerName $Domain
    DocumentRoot "$projectApache"
    SSLEngine on
    SSLCertificateFile "$certApache"
    SSLCertificateKeyFile "$keyApache"
    <Directory "$projectApache">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
$blockEnd
"@

$vhostsConf = Get-Content -Raw -Path $vhostsConfPath
$vhostsOriginal = $vhostsConf
$blockPattern = '(?s)# BEGIN DHSUD_TRANSFER_SETUP.*?# END DHSUD_TRANSFER_SETUP'

if ($vhostsConf -match $blockPattern) {
    $vhostsConf = [regex]::Replace($vhostsConf, $blockPattern, $vhostBlock.Trim())
} else {
    $vhostsConf = $vhostsConf.TrimEnd() + "`r`n`r`n" + $vhostBlock.Trim() + "`r`n"
}

if ($vhostsConf -ne $vhostsOriginal) {
    Invoke-Action "Writing virtual hosts config: $vhostsConfPath" {
        Set-Content -Path $vhostsConfPath -Value $vhostsConf -Encoding ASCII
    }
} else {
    Write-Host "Virtual hosts config already up to date"
}

Write-Step "4) Configure certificate"
if (-not $SkipCertificateSetup) {
    $mkcertCmd = Get-Command mkcert -ErrorAction SilentlyContinue
    if ($mkcertCmd) {
        Invoke-Action "Trust local CA (mkcert -install)" {
            & $mkcertCmd.Source -install
        }
        $certHosts = @($Domain, "localhost", "127.0.0.1", "::1")
        if (-not [string]::IsNullOrWhiteSpace($LanIp)) {
            $certHosts += $LanIp
        }
        Invoke-Action "Generate local cert for $Domain" {
            & $mkcertCmd.Source -cert-file $certPath -key-file $keyPath @certHosts
        }
    } elseif ((Test-Path $certPath) -and (Test-Path $keyPath)) {
        Write-Warning "mkcert is not installed. Using existing cert files, but browser may still show untrusted certificate on this PC."
    } else {
        throw "mkcert not found and certificate files are missing. Install mkcert or provide tools/dhsud-local.pem and tools/dhsud-local-key.pem"
    }
} else {
    Write-Host "Skipped certificate setup"
}

Write-Step "5) Validate and restart Apache"
Invoke-Action "Testing Apache syntax" {
    & $httpdExePath -t
}

$apacheSvc = Get-Service -Name "Apache2.4" -ErrorAction SilentlyContinue
if ($apacheSvc) {
    Invoke-Action "Restarting Apache2.4 service" {
        Restart-Service -Name "Apache2.4" -Force
    }
} else {
    Write-Warning "Apache2.4 service was not found. Restart Apache using XAMPP Control Panel."
}

Write-Step "6) Install Node dependencies"
if (-not $SkipDependencies) {
    $depInstaller = Join-Path $PSScriptRoot "install-deps.ps1"
    if (Test-Path $depInstaller) {
        Invoke-Action "Running dependency installer" {
            & powershell -ExecutionPolicy Bypass -File $depInstaller
        }
    } else {
        Write-Warning "Dependency installer not found: $depInstaller"
    }
} else {
    Write-Host "Skipped dependency installation"
}

Write-Step "7) Done"
Write-Host "Open these URLs after setup:"
Write-Host "- HTTPS (custom domain): https://$Domain/pages/Admin_LogIn.php"
Write-Host "- HTTP (localhost path): $localHttpBase/pages/Admin_LogIn.php"
if (-not [string]::IsNullOrWhiteSpace($LanIp)) {
    if ([string]::IsNullOrWhiteSpace($relativeWebPath)) {
        $lanHttpsBase = "https://$LanIp"
    } else {
        $lanHttpsBase = "https://$LanIp/$relativeWebPath"
    }
    Write-Host "- HTTPS (LAN IP): $lanHttpsBase/pages/Admin_LogIn.php"
    Write-Host ""
    Write-Host "For other devices:"
    Write-Host "- Add hosts entry: $LanIp $Domain"
    Write-Host "- Trust mkcert root CA on each device"
}
