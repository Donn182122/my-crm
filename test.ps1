$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    & C:/xampp/php/php.exe -d extension=sqlite3 vendor/phpunit/phpunit/phpunit --no-coverage @args
    exit $LASTEXITCODE
} finally { Pop-Location }
