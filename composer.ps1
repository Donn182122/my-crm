$ErrorActionPreference = 'Stop'
$env:PATH = 'C:\xampp\php;' + $env:PATH
$env:COMPOSER_HOME = Join-Path $PSScriptRoot '.tools/home'
$env:COMPOSER_CACHE_DIR = Join-Path $PSScriptRoot '.tools/cache'
Push-Location $PSScriptRoot
try {
    & C:/xampp/php/php.exe -d extension=zip -d extension=sodium "$PSScriptRoot/.tools/composer.phar" @args
    exit $LASTEXITCODE
} finally { Pop-Location }
