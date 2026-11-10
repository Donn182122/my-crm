# Run this yourself in a VS Code PowerShell terminal. Never paste a password in chat.
$ErrorActionPreference = 'Stop'
$process = $null
$passwordSecure = $null
$confirmSecure = $null
$passwordPointer = [IntPtr]::Zero
$confirmPointer = [IntPtr]::Zero
$payload = $null
try {
    $username = Read-Host 'Choose your administrator username'
    $email = Read-Host 'Choose your administrator email'
    $passwordSecure = Read-Host 'Choose a strong password (at least 12 characters)' -AsSecureString
    $confirmSecure = Read-Host 'Confirm your password' -AsSecureString
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($passwordSecure)
    $confirmPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmSecure)
    $passwordText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $confirmText = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmPointer)
    if($passwordText -cne $confirmText) { throw 'Password confirmation does not match.' }
    $payload = @{ username=$username.Trim(); email=$email.Trim(); password=$passwordText; password_confirm=$confirmText } | ConvertTo-Json -Compress
    $payload = [regex]::Replace($payload, '[^\x00-\x7F]', { param($match) '\u{0:x4}' -f [int][char]$match.Value })
    $passwordText = $null
    $confirmText = $null
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = 'C:\xampp\php\php.exe'
    $info.Arguments = '-d zend.exception_ignore_args=1 spark local:admin --no-header'
    $info.WorkingDirectory = $PSScriptRoot
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $info
    [void]$process.Start()
    $process.StandardInput.Write($payload)
    $process.StandardInput.Close()
    $payload = $null
    $output = $process.StandardOutput.ReadToEnd()
    $ignoredError = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    $result = $null
    foreach($line in ($output -split "`r?`n")) {
        if($line.Trim().StartsWith('{')) { try { $result = $line | ConvertFrom-Json } catch {} }
    }
    if($null -eq $result) { throw 'The setup backend returned no status. Check the local server and try again.' }
    if(-not $result.ok) { throw ([string]$result.message) }
    Write-Host ('Administrator verified: '+$result.username) -ForegroundColor Green
    Write-Host ('Group: '+$result.group+'; permissions: '+($result.permissions -join ', '))
    foreach($check in $result.checks) { Write-Host ('PASS: '+$check) }
    Write-Host 'Sign in at http://localhost:8081/login. Only the password-free result is saved in ignored runtime storage.'
} catch {
    # Never echo input, raw subprocess output, or exception argument traces.
    Write-Host ('Setup did not complete: '+$_.Exception.Message) -ForegroundColor Red
    exit 1
} finally {
    if($passwordPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer) }
    if($confirmPointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmPointer) }
    if($null -ne $passwordSecure) { $passwordSecure.Dispose() }
    if($null -ne $confirmSecure) { $confirmSecure.Dispose() }
    $passwordText = $null
    $confirmText = $null
    $payload = $null
    $output = $null
    $ignoredError = $null
    if($null -ne $process) { $process.Dispose() }
}
