$ErrorActionPreference = 'Stop'
Push-Location $PSScriptRoot
try {
    & C:/xampp/php/php.exe -d extension=zip -d extension=sodium -S localhost:8081 -t public vendor/codeigniter4/framework/system/rewrite.php
} finally { Pop-Location }
