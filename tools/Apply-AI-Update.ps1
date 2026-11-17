param([string]$Target = 'C:\laragon\www\jobmatch-cole')
$ErrorActionPreference = 'Stop'
$payload = Split-Path -Parent $PSScriptRoot
function Get-TextHash([string]$Path) {
    $text = [IO.File]::ReadAllText($Path).Replace("`r`n", "`n")
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($text)))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}
function Resolve-Child([string]$Root, [string]$Relative) {
    $base = [IO.Path]::GetFullPath($Root).TrimEnd('\') + '\'
    $path = [IO.Path]::GetFullPath((Join-Path $Root $Relative))
    if (!$path.StartsWith($base, [StringComparison]::OrdinalIgnoreCase) -or
        $Relative -eq '.env' -or $Relative -match '^(\.git|vendor|node_modules|storage)[/\\]') {
        throw 'The update manifest contains a protected path.'
    }
    return $path
}
try {
    $manifestPath = Join-Path $payload '.ai-update-manifest.json'
    if (!(Test-Path $manifestPath)) { throw 'This installer runs from the supplied AI update ZIP. Git checkouts should use the normal branch workflow.' }
    $manifest = Get-Content -Raw -Encoding UTF8 $manifestPath | ConvertFrom-Json
    if (!(Test-Path (Join-Path $Target 'artisan'))) {
        Add-Type -AssemblyName System.Windows.Forms
        $picker = New-Object System.Windows.Forms.FolderBrowserDialog
        $picker.Description = 'Select your existing Laravel jobmatch-cole project folder'
        if ($picker.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) { throw 'No project folder was selected.' }
        $Target = $picker.SelectedPath
    }
    if ([IO.Path]::GetFullPath($Target) -eq [IO.Path]::GetFullPath($payload)) { throw 'Choose the existing configured project, not the extracted review folder.' }
    if (!(Test-Path (Join-Path $Target '.env')) -or !(Test-Path (Join-Path $Target 'vendor/autoload.php'))) {
        throw 'The selected folder must be an existing configured Laravel project with its own .env and vendor folder.'
    }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $php = if ($phpCommand) { $phpCommand.Source } else { $null }
    if (!$php -or [int](& $php -r 'echo PHP_VERSION_ID;') -lt 80500) {
        $php = Get-ChildItem 'C:\laragon\bin\php\php-8.5*\php.exe' -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    }
    if (!$php) { throw 'PHP 8.5 is required before updating this project.' }
    $changes = @()
    $conflicts = @()
    foreach ($file in $manifest.files) {
        $source = Resolve-Child $payload $file.path
        $destination = Resolve-Child $Target $file.path
        if (!(Test-Path $source) -or (Get-TextHash $source) -ne $file.updated_hash) { throw ('Invalid update payload: ' + $file.path) }
        if (Test-Path $destination) {
            $hash = Get-TextHash $destination
            if ($hash -eq $file.updated_hash) { continue }
            if ($file.base_hashes -notcontains $hash) { $conflicts += $file.path; continue }
        } elseif ($file.base_hashes.Count -gt 0) { $conflicts += $file.path; continue }
        $changes += $file
    }
    if ($conflicts.Count -gt 0) { throw ("Existing files differ from the expected PR #6/readiness version. Nothing was changed:`n" + ($conflicts -join "`n")) }
    $backup = Join-Path $Target ('.jobmatch-fix-backups\ai-update\' + [guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $backup -Force | Out-Null
    $applied = @()
    try {
        foreach ($file in $changes) {
            $destination = Resolve-Child $Target $file.path
            $old = Resolve-Child $backup $file.path
            if (Test-Path $destination) {
                New-Item -ItemType Directory -Path (Split-Path -Parent $old) -Force | Out-Null
                Copy-Item $destination $old
            }
            $applied += $file
            New-Item -ItemType Directory -Path (Split-Path -Parent $destination) -Force | Out-Null
            Copy-Item (Resolve-Child $payload $file.path) $destination -Force
            if ($file.path.EndsWith('.php')) {
                & $php -l $destination
                if ($LASTEXITCODE -ne 0) { throw ('PHP syntax check failed: ' + $file.path) }
            }
        }
        Push-Location $Target
        try {
            & $php artisan config:clear
            if ($LASTEXITCODE -ne 0) { throw 'Laravel configuration could not be refreshed.' }
            & $php artisan view:clear
            if ($LASTEXITCODE -ne 0) { throw 'Laravel views could not be refreshed.' }
        } finally { Pop-Location }
    } catch {
        foreach ($file in $applied) {
            $destination = Resolve-Child $Target $file.path
            $old = Resolve-Child $backup $file.path
            if (Test-Path $old) { Copy-Item $old $destination -Force }
            elseif (Test-Path $destination) { Remove-Item $destination }
        }
        throw 'Update checks failed. Changed source files were restored from the local backup.'
    }
    Write-Host 'AI source update applied. Your .env, database and uploaded files were preserved.' -ForegroundColor Green
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Target 'tools/Enable-LocalAI.ps1')
    if ($LASTEXITCODE -ne 0) { Write-Host 'Source is updated; local model setup is pending. After installing Ollama, double-click ENABLE-LOCAL-AI.cmd inside your project.' -ForegroundColor Yellow }
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}
