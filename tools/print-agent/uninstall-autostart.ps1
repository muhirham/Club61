# Hentikan & hapus jadwal otomatis Club61 Print Agent.
#   powershell -ExecutionPolicy Bypass -File .\uninstall-autostart.ps1
$taskName = 'Club61 Print Agent'
Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
Get-Process club61-print-agent -ErrorAction SilentlyContinue | Stop-Process -Force
Write-Host 'Club61 Print Agent dihentikan & tidak lagi jalan otomatis.'
