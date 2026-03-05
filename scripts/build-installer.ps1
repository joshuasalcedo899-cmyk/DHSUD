param(
    [switch]$SkipNpmInstall,
    [switch]$SkipPlaywrightBrowserInstall,
    [switch]$Portable
)

$ErrorActionPreference = "Stop"

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "== $Message ==" -ForegroundColor Cyan
}

function Resolve-Tool([string]$Name, [string[]]$Candidates) {
    $cmd = Get-Command $Name -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source -and (Test-Path $cmd.Source)) {
        $sourcePath = $cmd.Source
        if (
            ($Name -in @("npm", "npx")) -and
            ($sourcePath -match "\.ps1$")
        ) {
            # Prefer npm.cmd/npx.cmd over PowerShell wrappers to avoid execution-policy failures.
        } else {
            return $sourcePath
        }
    }
    foreach ($candidate in $Candidates) {
        if ($candidate -and (Test-Path $candidate)) {
            return $candidate
        }
    }
    return $null
}

function Invoke-Checked([string]$FilePath, [string[]]$Arguments, [string]$Description) {
    Write-Host $Description
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$Description failed (exit code: $LASTEXITCODE)"
    }
}

function Ensure-XamppPayload([string]$ProjectRoot) {
    $xamppDir = Join-Path $ProjectRoot "installer\xampp"
    if (-not (Test-Path $xamppDir)) {
        throw "Missing installer payload directory: $xamppDir"
    }

    $zipFiles = Get-ChildItem -Path $xamppDir -Filter *.zip -File -ErrorAction SilentlyContinue
    if ($zipFiles -and $zipFiles.Count -gt 0) {
        Write-Host "Found bundled XAMPP zip: $($zipFiles[0].Name)"
        return
    }

    $hasPortableTree = Test-Path (Join-Path $xamppDir "apache") -and
        Test-Path (Join-Path $xamppDir "mysql")
    if ($hasPortableTree) {
        Write-Host "Found bundled XAMPP folder payload."
        return
    }

    throw "No XAMPP payload found in installer\xampp. Add a portable zip or extracted apache/mysql folders."
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $projectRoot

Write-Host "DHSUD installer build"
Write-Host "ProjectRoot: $projectRoot"

$nodeExe = Resolve-Tool -Name "node" -Candidates @(
    "C:\Program Files\nodejs\node.exe",
    "C:\Program Files (x86)\nodejs\node.exe"
)
$npmCmd = Resolve-Tool -Name "npm" -Candidates @(
    "C:\Program Files\nodejs\npm.cmd",
    "C:\Program Files (x86)\nodejs\npm.cmd"
)
$npxCmd = Resolve-Tool -Name "npx" -Candidates @(
    "C:\Program Files\nodejs\npx.cmd",
    "C:\Program Files (x86)\nodejs\npx.cmd"
)

if (-not $nodeExe -or -not $npmCmd -or -not $npxCmd) {
    throw "Node.js tooling is missing. Install Node.js LTS and retry."
}

Write-Step "1) Validate installer payload"
Ensure-XamppPayload -ProjectRoot $projectRoot

Write-Step "2) Install npm dependencies"
if ($SkipNpmInstall) {
    Write-Host "Skipping npm install (requested)."
} else {
    if (Test-Path (Join-Path $projectRoot "package-lock.json")) {
        Invoke-Checked -FilePath $npmCmd -Arguments @("ci") -Description "npm ci"
    } else {
        Invoke-Checked -FilePath $npmCmd -Arguments @("install") -Description "npm install"
    }
}

Write-Step "3) Ensure Playwright browser dependency"
if ($SkipPlaywrightBrowserInstall) {
    Write-Host "Skipping Playwright browser install (requested)."
} else {
    $env:PLAYWRIGHT_BROWSERS_PATH = "0"
    Invoke-Checked -FilePath $npxCmd -Arguments @("playwright", "install", "chromium") -Description "npx playwright install chromium"
}

Write-Step "4) Build installer"
if ($Portable) {
    $primaryArgs = @("run", "desktop:portable")
    $primaryDesc = "npm run desktop:portable"
    $fallbackArgs = @("run", "desktop:portable:noedit")
    $fallbackDesc = "npm run desktop:portable:noedit"
} else {
    $primaryArgs = @("run", "desktop:dist")
    $primaryDesc = "npm run desktop:dist"
    $fallbackArgs = @("run", "desktop:dist:noedit")
    $fallbackDesc = "npm run desktop:dist:noedit"
}

try {
    Invoke-Checked -FilePath $npmCmd -Arguments $primaryArgs -Description $primaryDesc
} catch {
    if ($Portable) {
        Write-Host ""
        Write-Host "Primary portable build failed. Retrying with no-edit mode..." -ForegroundColor Yellow
        Invoke-Checked -FilePath $npmCmd -Arguments $fallbackArgs -Description $fallbackDesc
    } else {
        Write-Host ""
        Write-Host "Primary installer build failed. Running no-edit build fallback + icon patch..." -ForegroundColor Yellow
        Invoke-Checked -FilePath $npmCmd -Arguments $fallbackArgs -Description $fallbackDesc

        $unpackedExePath = Join-Path $projectRoot "desktop-dist\win-unpacked\DHSUD Mail Tracker.exe"
        $iconPath = Join-Path $projectRoot "assets\DHSUDLogo.ico"
        Invoke-Checked -FilePath "powershell.exe" -Arguments @(
            "-NoProfile",
            "-ExecutionPolicy", "Bypass",
            "-File", ".\scripts\set-exe-icon.ps1",
            "-ExePath", $unpackedExePath,
            "-IconPath", $iconPath
        ) -Description "Patch unpacked EXE icon"

        Invoke-Checked -FilePath $npmCmd -Arguments @("run", "desktop:dist:from-unpacked:noedit") -Description "npm run desktop:dist:from-unpacked:noedit"
    }
}

Write-Step "5) Done"
$distDir = Join-Path $projectRoot "desktop-dist"
Write-Host "Build output folder: $distDir"
if (Test-Path $distDir) {
    Get-ChildItem -Path $distDir -File | Select-Object Name,Length | Format-Table -AutoSize | Out-String | Write-Host
}
