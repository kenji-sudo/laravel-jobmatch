$ErrorActionPreference = 'Stop'
$sandbox = Join-Path ([IO.Path]::GetTempPath()) ('jobmatch-updater-test-' + [guid]::NewGuid().ToString())
$package = Join-Path $sandbox 'package'
$target = Join-Path $sandbox 'project'
function Write-Fixture([string]$Path, [string]$Text) {
    New-Item -ItemType Directory -Path (Split-Path -Parent $Path) -Force | Out-Null
    [IO.File]::WriteAllText($Path, $Text, (New-Object Text.UTF8Encoding($false)))
}
function Hash-Fixture([string]$Text) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($Text.Replace("`r`n", "`n"))))).Replace('-', '').ToLowerInvariant() }
    finally { $sha.Dispose() }
}
function Check-Fixture([int]$Expected, [string]$Name) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $package 'tools/Apply-AI-Update.ps1') -Target $target -CheckOnly
    if ($LASTEXITCODE -ne $Expected) { throw ('Unexpected updater result: ' + $Name) }
    if ((Get-Content -Raw (Join-Path $target '.env')) -ne 'FIXTURE_ONLY=true') { throw 'The dry run changed the environment fixture.' }
    if (Test-Path (Join-Path $target '.jobmatch-fix-backups')) { throw 'The dry run created a backup or applied source.' }
    Write-Host ('PASS: ' + $Name)
}
try {
    Write-Fixture (Join-Path $target '.env') 'FIXTURE_ONLY=true'
    Write-Fixture (Join-Path $target 'artisan') '<?php'
    Write-Fixture (Join-Path $target 'vendor/autoload.php') '<?php'
    Write-Fixture (Join-Path $package 'tools/Apply-AI-Update.ps1') (Get-Content -Raw (Join-Path $PSScriptRoot 'Apply-AI-Update.ps1'))
    Write-Fixture (Join-Path $package 'app/required.txt') "new`n"
    Write-Fixture (Join-Path $target 'app/required.txt') "old`r`n"
    $added = @(
        '.github/workflows/laravel-checks.yml',
        'documentation/AI_OPTIONS.md',
        'documentation/VALIDATION_AND_HANDOFF.md',
        'tests/Feature/JobMatchDefaultSeederTest.php',
        'tests/Feature/JobMatchRecommendationEligibilityTest.php'
    )
    $files = @(@{path='app/required.txt'; base_hashes=@((Hash-Fixture "old`n")); updated_hash=(Hash-Fixture "new`n"); allow_missing=$false})
    foreach ($path in $added) {
        Write-Fixture (Join-Path $package $path) 'AI version'
        $files += @{path=$path; base_hashes=@((Hash-Fixture 'readiness version')); updated_hash=(Hash-Fixture 'AI version'); allow_missing=$true}
    }
    Write-Fixture (Join-Path $package '.ai-update-manifest.json') (@{files=$files} | ConvertTo-Json -Depth 5)
    Check-Fixture 0 'PR #6 without the five readiness files, including CRLF source'
    foreach ($path in $added) { Write-Fixture (Join-Path $target $path) 'readiness version' }
    Check-Fixture 0 'readiness installation with the five original files'
    Write-Fixture (Join-Path $target $added[0]) 'custom local edit'
    Check-Fixture 1 'existing local edit is still rejected'
    Write-Fixture (Join-Path $target $added[0]) 'readiness version'
    Remove-Item (Join-Path $target 'app/required.txt')
    Check-Fixture 1 'missing required original file is still rejected'
    Write-Fixture (Join-Path $target 'app/required.txt') "new`n"
    foreach ($path in $added) { Write-Fixture (Join-Path $target $path) 'AI version' }
    Check-Fixture 0 'already updated files are accepted without rewriting'
    Write-Host 'All five Windows updater regression cases passed.'
} finally {
    if (Test-Path $sandbox) { Remove-Item $sandbox -Recurse -Force }
}
