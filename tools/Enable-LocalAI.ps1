$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
Push-Location $project
try {
    if (!(Test-Path '.env') -or !(Test-Path 'vendor/autoload.php') -or !(Test-Path 'app/Services/Matching/SemanticJobMatcher.php')) {
        throw 'Run this from a configured checkout of the local-semantic-matching branch. It does not replace or install the Laravel project.'
    }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $php = if ($phpCommand) { $phpCommand.Source } else { $null }
    if (!$php -or [int](& $php -r 'echo PHP_VERSION_ID;') -lt 80500) {
        $php = Get-ChildItem 'C:\laragon\bin\php\php-8.5*\php.exe' -ErrorAction SilentlyContinue |
            Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    }
    if (!$php) { throw 'PHP 8.5 was not found. Select the project PHP version in Laragon first.' }
    $ollamaCommand = Get-Command ollama -ErrorAction SilentlyContinue
    $ollama = if ($ollamaCommand) { $ollamaCommand.Source } else { Join-Path $env:LOCALAPPDATA 'Programs\Ollama\ollama.exe' }
    if (!(Test-Path $ollama)) {
        Start-Process 'https://ollama.com/download/windows'
        throw 'Install Ollama from the official page that opened, then double-click ENABLE-LOCAL-AI.cmd again.'
    }
    $env:OLLAMA_HOST = '127.0.0.1:11434'
    try { $null = Invoke-RestMethod 'http://127.0.0.1:11434/api/tags' -TimeoutSec 2 }
    catch {
        Start-Process -FilePath $ollama -ArgumentList 'serve' -WindowStyle Minimized
        $ready = $false
        for ($i = 0; $i -lt 15; $i++) {
            Start-Sleep -Seconds 1
            try {
                $null = Invoke-RestMethod 'http://127.0.0.1:11434/api/tags' -TimeoutSec 2
                $ready = $true
                break
            } catch { }
        }
        if (!$ready) { throw 'Ollama did not start on the local address. Open Ollama and try again.' }
    }
    $model = 'all-minilm:22m-l6-v2-fp16'
    Write-Host 'Downloading/checking the local MiniLM model. Internet is required for the first download.'
    & $ollama pull $model
    if ($LASTEXITCODE -ne 0) { throw 'Model download failed. Laravel settings were not changed.' }

    $envPath = Join-Path $project '.env'
    $backup = Join-Path $project ('.jobmatch-fix-backups\ai-setup\' + [guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $backup -Force | Out-Null
    Copy-Item $envPath (Join-Path $backup '.env')
    $original = [IO.File]::ReadAllText($envPath)
    $updated = $original
    $settings = [ordered]@{
        MATCHING_AI_ENABLED = 'true'
        MATCHING_OLLAMA_URL = 'http://127.0.0.1:11434'
        MATCHING_EMBEDDING_MODEL = $model
    }
    foreach ($key in $settings.Keys) {
        $line = $key + '=' + $settings[$key]
        $pattern = '(?m)^' + [regex]::Escape($key) + '=.*$'
        if ([regex]::IsMatch($updated, $pattern)) { $updated = [regex]::Replace($updated, $pattern, $line) }
        else { $updated = $updated.TrimEnd() + "`r`n" + $line + "`r`n" }
    }
    try {
        [IO.File]::WriteAllText($envPath, $updated, (New-Object Text.UTF8Encoding($false)))
        & $php artisan config:clear
        if ($LASTEXITCODE -ne 0) { throw 'Laravel configuration could not be refreshed.' }
        & $php artisan matching:check
        if ($LASTEXITCODE -ne 0) { throw 'The local model health check failed.' }
    } catch {
        Copy-Item (Join-Path $backup '.env') $envPath -Force
        & $php artisan config:clear
        throw 'Setup check failed; the original environment file was restored. The model download remains installed.'
    }
    Write-Host 'Local AI enabled. Restart the Laravel server if it is already running.' -ForegroundColor Green
    Write-Host 'Sign in as a student and open Job Recommendations. Existing database settings and application key were preserved.'
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally {
    Pop-Location
}
