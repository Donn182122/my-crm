# Credentials stay in local process memory and a private child-process stdin pipe.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
[System.Windows.Forms.Application]::EnableVisualStyles()
$form = New-Object System.Windows.Forms.Form
$form.Text = 'My CRM - Local administrator setup'
$form.Size = New-Object System.Drawing.Size(550,390)
$form.StartPosition = 'CenterScreen'
$form.FormBorderStyle = 'FixedDialog'
$form.MaximizeBox = $false
$boxes = @{}
$labels = @('Username','Email','Password','Confirm password')
for($i=0; $i -lt 4; $i++) {
    $label = New-Object System.Windows.Forms.Label
    $label.Text = $labels[$i]
    $label.Location = New-Object System.Drawing.Point(24,(30+$i*48))
    $label.Size = New-Object System.Drawing.Size(145,24)
    $box = New-Object System.Windows.Forms.TextBox
    $box.Location = New-Object System.Drawing.Point(180,(27+$i*48))
    $box.Size = New-Object System.Drawing.Size(320,25)
    if($i -ge 2) { $box.UseSystemPasswordChar = $true }
    $boxes[$labels[$i]] = $box
    $form.Controls.Add($label)
    $form.Controls.Add($box)
}
$note = New-Object System.Windows.Forms.Label
$note.Text = 'Choose a strong password of at least 12 characters. Credentials are never saved to a file or sent to chat.'
$note.Location = New-Object System.Drawing.Point(24,225)
$note.Size = New-Object System.Drawing.Size(480,42)
$form.Controls.Add($note)
$button = New-Object System.Windows.Forms.Button
$button.Text = 'Create and verify administrator'
$button.Location = New-Object System.Drawing.Point(180,280)
$button.Size = New-Object System.Drawing.Size(320,36)
$form.Controls.Add($button)
$form.AcceptButton = $button
$button.Add_Click({
    $process = $null
    $payload = $null
    try {
        if($boxes['Password'].Text -cne $boxes['Confirm password'].Text) { throw 'Passwords do not match.' }
        if($boxes['Password'].Text.Length -lt 12) { throw 'Use at least 12 characters.' }
        $button.Enabled = $false
        $note.Text = 'Creating the local account and checking login, permissions, session rotation and logout...'
        $form.Refresh()
        $payload = @{username=$boxes['Username'].Text.Trim(); email=$boxes['Email'].Text.Trim(); password=$boxes['Password'].Text; password_confirm=$boxes['Confirm password'].Text} | ConvertTo-Json -Compress
        $info = New-Object System.Diagnostics.ProcessStartInfo
        $info.FileName = 'C:\xampp\php\php.exe'
        $info.Arguments = '-d zend.exception_ignore_args=1 spark local:admin --no-header'
        $info.WorkingDirectory = $PSScriptRoot
        $info.UseShellExecute = $false
        $info.CreateNoWindow = $true
        $info.RedirectStandardInput = $true
        $info.RedirectStandardOutput = $true
        $info.RedirectStandardError = $true
        # Windows PowerShell 5.1 lacks StandardInputEncoding. ASCII JSON escapes
        # preserve Unicode credentials without relying on a console code page.
        $payload = [regex]::Replace($payload, '[^\x00-\x7F]', { param($match) '\u{0:x4}' -f [int][char]$match.Value })
        $process = New-Object System.Diagnostics.Process
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
        if($null -eq $result) { throw 'Setup did not return a status. Check the local server and project configuration.' }
        if(-not $result.ok) { throw ([string]$result.message) }
        [void][System.Windows.Forms.MessageBox]::Show('Administrator created and verified. You can now sign in at http://localhost:8081/login.','My CRM',[System.Windows.Forms.MessageBoxButtons]::OK,[System.Windows.Forms.MessageBoxIcon]::Information)
        $form.Close()
    } catch {
        # Only fixed/sanitized messages are shown; child process output is never echoed.
        [void][System.Windows.Forms.MessageBox]::Show($_.Exception.Message,'My CRM setup',[System.Windows.Forms.MessageBoxButtons]::OK,[System.Windows.Forms.MessageBoxIcon]::Warning)
        $note.Text = 'Review the message and retry. Password fields have been cleared.'
    } finally {
        $boxes['Password'].Clear()
        $boxes['Confirm password'].Clear()
        $payload = $null
        $output = $null
        $ignoredError = $null
        if($null -ne $process) { $process.Dispose() }
        $button.Enabled = $true
    }
})
[void]$form.ShowDialog()
$form.Dispose()
