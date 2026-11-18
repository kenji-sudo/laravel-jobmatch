$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
function Set-Setting([string]$Text, [string]$Name, [string]$Value) {
    if ($Value -match "[`r`n]") { throw 'Invalid setting value.' }
    $pattern = '(?m)^' + [regex]::Escape($Name) + '=.*$'
    $line = $Name + '=' + $Value
    if ([regex]::IsMatch($Text, $pattern)) { return [regex]::Replace($Text, $pattern, $line) }
    return $Text.TrimEnd() + "`r`n" + $line + "`r`n"
}
function Read-Setting([string]$Text, [string]$Name) {
    $match = [regex]::Match($Text, '(?m)^' + [regex]::Escape($Name) + '=([^\r\n]*)')
    if ($match.Success) { return $match.Groups[1].Value.Trim().Trim('"').Trim("'") }
    return ''
}
Push-Location $project
try {
    if (!(Test-Path '.env') -or !(Test-Path 'vendor/autoload.php')) { throw 'Use this launcher inside the configured working Laravel project.' }
    $dotnet = Get-Command dotnet -ErrorAction SilentlyContinue
    if (!$dotnet -or !((& $dotnet.Source --list-sdks) -match '^10\.')) {
        Start-Process 'https://dotnet.microsoft.com/en-us/download/dotnet/10.0'
        throw 'Install the official .NET 10 SDK, then run START-COLE-AI.cmd again.'
    }
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    $php = if ($phpCommand) { $phpCommand.Source } else { $null }
    if (!$php -or [int](& $php -r 'echo PHP_VERSION_ID;') -lt 80500) {
        $php = Get-ChildItem 'C:\laragon\bin\php\php-8.5*\php.exe' -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
    }
    if (!$php) { throw 'PHP 8.5 was not found.' }
    $original = [IO.File]::ReadAllText((Join-Path $project '.env'))
    $updated = $original
    $geminiKey = Read-Setting $original 'GEMINI_API_KEY'
    if (!$geminiKey) {
        Write-Host 'Gemini extraction needs your own Google AI API key. Do not paste the key into chat or GitHub.'
        $secret = Read-Host 'Enter Gemini key here (hidden), or Enter to leave extraction pending' -AsSecureString
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
        try { $geminiKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
        finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    }
    $geminiModel = Read-Setting $original 'GEMINI_MODEL'
    if ($geminiKey -and !$geminiModel) { $geminiModel = Read-Host 'Enter the Gemini model ID enabled in your Google AI project' }
    if ($geminiKey -and $geminiKey -notmatch '^[a-zA-Z0-9_-]+$') { throw 'Invalid API key format.' }
    if ($geminiModel -and $geminiModel -notmatch '^[a-zA-Z0-9._-]+$') { throw 'Invalid Gemini model ID.' }
    $internalKey = Read-Setting $original 'MATCHING_INTEGRATION_KEY'
    if ($internalKey.Length -lt 32) { $internalKey = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N') }
    foreach ($setting in @(@('MATCHING_DRIVER','gemini_mlnet'), @('MATCHING_AI_ENABLED','true'), @('MATCHING_MLNET_URL','http://127.0.0.1:5291'), @('MATCHING_INTEGRATION_KEY',$internalKey), @('GEMINI_API_KEY',$geminiKey), @('GEMINI_MODEL',$geminiModel))) {
        $updated = Set-Setting $updated $setting[0] $setting[1]
    }
    $backup = Join-Path $project ('.jobmatch-fix-backups\cole-ai\' + [guid]::NewGuid().ToString())
    New-Item -ItemType Directory -Path $backup -Force | Out-Null
    Copy-Item '.env' (Join-Path $backup '.env')
    [IO.File]::WriteAllText((Join-Path $project '.env'), $updated, (New-Object Text.UTF8Encoding($false)))
    & $php artisan config:clear
    if ($LASTEXITCODE -ne 0) { Copy-Item (Join-Path $backup '.env') '.env' -Force; throw 'Config refresh failed; the original environment was restored.' }
    $server = Join-Path $project 'local-ai/server'
    & $dotnet.Source publish 'services/JobMatch.Inference/JobMatch.Inference.csproj' -c Release -o $server --nologo
    if ($LASTEXITCODE -ne 0) { throw 'C# build failed. Source and environment backup are preserved; no model was trained.' }
    $model = Join-Path $project 'local-ai/model.zip'
    if (!(Test-Path $model)) {
        Write-Host 'A reviewed labelled dataset is required. No dummy production model will be created.' -ForegroundColor Yellow
        $train = Read-Host 'Do you have a labelled CSV ready to train? (y/N)'
        if ($train -eq 'y') {
            Add-Type -AssemblyName System.Windows.Forms
            $picker = New-Object System.Windows.Forms.OpenFileDialog
            $picker.Filter = 'Labelled training CSV (*.csv)|*.csv'
            if ($picker.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
                & $dotnet.Source (Join-Path $server 'JobMatch.Inference.dll') train $picker.FileName $model
                if ($LASTEXITCODE -ne 0) { throw 'Training did not pass validation. The site will retain skill-based results.' }
            }
        }
    }
    $env:Integration__ApiKey = $internalKey
    $env:Matching__ModelPath = $model
    Start-Process -FilePath $dotnet.Source -ArgumentList ('"' + (Join-Path $server 'JobMatch.Inference.dll') + '"') -WorkingDirectory $server
    try { $null = Invoke-WebRequest 'http://127.0.0.1:8000' -UseBasicParsing -TimeoutSec 2 }
    catch { Start-Process -FilePath $php -ArgumentList 'artisan serve --host=127.0.0.1 --port=8000 --tries=1' -WorkingDirectory $project }
    Start-Process 'http://127.0.0.1:8000/portal/recommendations'
    if (!$geminiKey -or !$geminiModel) { Write-Host 'Pending: Gemini credentials/model configuration.' -ForegroundColor Yellow }
    if (!(Test-Path $model)) { Write-Host 'Pending: real labelled data and trained model. Skill-based fallback remains available.' -ForegroundColor Yellow }
    Write-Host 'Cole-aligned services launched. Check the separate server windows and review the browser results.'
} catch {
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
} finally { Pop-Location }
