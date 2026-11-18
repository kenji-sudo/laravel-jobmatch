$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot

function Read-Setting([string]$Text, [string]$Name) {
    $match = [regex]::Match($Text, '(?m)^' + [regex]::Escape($Name) + '=([^\r\n]*)')
    if ($match.Success) { return $match.Groups[1].Value.Trim().Trim('"').Trim("'") }
    return ''
}

function Set-Setting([string]$Text, [string]$Name, [string]$Value) {
    if ($Value -match "[`r`n]") { throw 'Invalid setting value.' }
    $pattern = '(?m)^' + [regex]::Escape($Name) + '=.*$'
    $line = $Name + '=' + $Value
    if ([regex]::IsMatch($Text, $pattern)) { return [regex]::Replace($Text, $pattern, $line) }
    return $Text.TrimEnd() + "`r`n" + $line + "`r`n"
}

Push-Location $project
try {
    if (!(Test-Path '.env') -or !(Test-Path 'vendor/autoload.php')) { throw 'Run this launcher inside your configured Laravel project.' }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $php = if ($phpCommand) { $phpCommand.Source } else { $null }
    if (!$php -or [int](& $php -r 'echo PHP_VERSION_ID;') -lt 80500) {
        $php = Get-ChildItem 'C:\laragon\bin\php\php-8.5*\php.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    }
    if (!$php) { throw 'PHP 8.5 was not found. Check the Laragon PHP installation.' }
    $original = [IO.File]::ReadAllText((Join-Path $project '.env'))
    $key = Read-Setting $original 'GEMINI_API_KEY'
    $model = Read-Setting $original 'GEMINI_MODEL'
    if (!$key) {
        Write-Host 'Use your own Google AI Gemini API key. Enter it only here, not in chat or GitHub.'
        $secret = Read-Host 'Gemini API key (hidden); Enter to leave setup pending' -AsSecureString
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
        try { $key = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
        finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    }
    if ($key -and !$model) { $model = Read-Host 'Gemini model ID enabled in your Google AI project' }
    if ($key -and $key -notmatch '^[a-zA-Z0-9_-]+$') { throw 'Invalid API key format.' }
    if ($model -and $model -notmatch '^[a-zA-Z0-9._-]+$') { throw 'Invalid model ID.' }
    if (!$key -or !$model) { throw 'Setup pending: a Gemini key and model are required. Existing settings were preserved.' }
    $updated = Set-Setting $original 'GEMINI_API_KEY' $key
    $updated = Set-Setting $updated 'GEMINI_MODEL' $model
    $updated = Set-Setting $updated 'AI_CHAT_ENABLED' 'true'
    $backup = Join-Path $project ('.jobmatch-fix-backups\assistant\' + [guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $backup -Force | Out-Null
    Copy-Item '.env' (Join-Path $backup '.env')
    try {
        [IO.File]::WriteAllText((Join-Path $project '.env'), $updated, (New-Object Text.UTF8Encoding($false)))
        & $php artisan config:clear
        if ($LASTEXITCODE -ne 0) { throw 'Configuration refresh failed.' }
        & $php artisan view:clear
        if ($LASTEXITCODE -ne 0) { throw 'View refresh failed.' }
    } catch {
        Copy-Item (Join-Path $backup '.env') '.env' -Force
        & $php artisan config:clear
        throw 'Setup failed; original environment settings were restored.'
    }
    try { $null = Invoke-WebRequest 'http://127.0.0.1:8000' -UseBasicParsing -TimeoutSec 2 }
    catch { Start-Process -FilePath $php -ArgumentList 'artisan serve --host=127.0.0.1 --port=8000 --tries=1' -WorkingDirectory $project }
    Start-Process 'http://127.0.0.1:8000/portal/assistant'
    Write-Host 'Assistant configuration saved. Sign in and send a short test message to confirm the live Gemini connection.' -ForegroundColor Green
    Write-Host 'No ML.NET training or Ollama installation is needed for this chat feature.'
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally { Pop-Location }
