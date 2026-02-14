param(
    [switch]$SkipBrowserDownload
)

$ErrorActionPreference = "Stop"

Write-Host "== DHSUD dependency installer =="
Write-Host "Project:" (Get-Location).Path

function Resolve-NodeCmd {
    $candidates = @(
        (Get-Command node -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue),
        "C:\Program Files\nodejs\node.exe",
        "C:\Program Files (x86)\nodejs\node.exe"
    ) | Where-Object { $_ -and (Test-Path $_) }

    if ($candidates.Count -gt 0) {
        return $candidates[0]
    }

    return $null
}

function Resolve-NpmCmd {
    $candidates = @(
        (Get-Command npm -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue),
        "C:\Program Files\nodejs\npm.cmd",
        "C:\Program Files (x86)\nodejs\npm.cmd"
    ) | Where-Object { $_ -and (Test-Path $_) }

    if ($candidates.Count -gt 0) {
        return $candidates[0]
    }

    return $null
}

function Ensure-NodeInstalled {
    $nodeExe = Resolve-NodeCmd
    $npmCmd = Resolve-NpmCmd
    if ($nodeExe -and $npmCmd) {
        Write-Host "Node found at: $nodeExe"
        Write-Host "npm found at: $npmCmd"
        return @{ Node = $nodeExe; Npm = $npmCmd }
    }

    Write-Host "Node.js not found. Installing Node.js LTS via winget..."
    if (-not (Get-Command winget -ErrorAction SilentlyContinue)) {
        throw "winget is not available. Install Node.js LTS manually from https://nodejs.org and rerun."
    }
    winget install -e --id OpenJS.NodeJS.LTS --accept-package-agreements --accept-source-agreements

    $nodeExe = Resolve-NodeCmd
    $npmCmd = Resolve-NpmCmd
    if (-not $nodeExe -or -not $npmCmd) {
        throw "Node.js install did not complete correctly. Please restart terminal and run again."
    }

    Write-Host "Node installed at: $nodeExe"
    Write-Host "npm installed at: $npmCmd"
    return @{ Node = $nodeExe; Npm = $npmCmd }
}

function Ensure-PathForSession {
    $nodeDir = "C:\Program Files\nodejs"
    if (Test-Path $nodeDir) {
        if (-not ($env:Path -split ";" | Where-Object { $_ -eq $nodeDir })) {
            $env:Path = "$nodeDir;$env:Path"
            Write-Host "Added to current session PATH: $nodeDir"
        }
    }
}

try {
    $runtime = Ensure-NodeInstalled
    $nodeExe = $runtime.Node
    Ensure-PathForSession

    $npmCmd = $runtime.Npm
    if (-not $npmCmd -or -not (Test-Path $npmCmd)) {
        throw "npm.cmd not found. Node installation appears incomplete."
    }

    Write-Host "npm path: $npmCmd"
    $env:NODE_PATH = $nodeExe
    [Environment]::SetEnvironmentVariable("NODE_PATH", $nodeExe, "User")
    Write-Host "NODE_PATH set to: $nodeExe"

    & $nodeExe -v
    & $npmCmd -v

    if ($SkipBrowserDownload) {
        Write-Host "Installing npm dependencies without browser download..."
        $env:PUPPETEER_SKIP_DOWNLOAD = "true"
        & $npmCmd install
    } else {
        Write-Host "Installing npm dependencies..."
        & $npmCmd install
    }

    Write-Host "Verifying Puppeteer..."
    & $nodeExe -e "const p=require('puppeteer'); console.log('Puppeteer OK:', !!p);"

    Write-Host ""
    Write-Host "Dependencies installed successfully."
    Write-Host "If Apache/PHP cannot find Node, set NODE_PATH in Apache env to: $nodeExe"
    exit 0
}
catch {
    Write-Error $_.Exception.Message
    exit 1
}
