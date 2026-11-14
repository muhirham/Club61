# Club61 Print Agent — jalan otomatis setiap user Windows ini login (Task Scheduler), lalu langsung dinyalakan.
# Jalankan dari folder yang berisi club61-print-agent.exe & agent.json:
#   powershell -ExecutionPolicy Bypass -File .\install-autostart.ps1
$ErrorActionPreference = 'Stop'

$dir = $PSScriptRoot
$exe = Join-Path $dir 'club61-print-agent.exe'
$config = Join-Path $dir 'agent.json'
$log = Join-Path $dir 'agent.log'
if (-not (Test-Path $exe)) { throw "club61-print-agent.exe tidak ada di $dir" }
if (-not (Test-Path $config)) { throw "agent.json belum ada di $dir (salin dari agent.example.json lalu isi)" }

# Port dari agent.json (bawaan 9120) — sekaligus memastikan isinya JSON yang sah sebelum dipasang.
try {
    $port = (Get-Content $config -Raw | ConvertFrom-Json).port
} catch {
    throw "agent.json bukan JSON yang sah (cek tanda koma & kutip): $($_.Exception.Message)"
}
if (-not $port) { $port = 9120 }

$taskName = 'Club61 Print Agent'
# Path diberi tanda kutip: folder dengan spasi (mis. "padel medan") gagal dijalankan Task Scheduler tanpa kutip.
$action = New-ScheduledTaskAction -Execute ('"{0}"' -f $exe) -WorkingDirectory $dir
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Get-Process club61-print-agent -ErrorAction SilentlyContinue | Stop-Process -Force
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -Description 'Jembatan cetak slip stasiun Club61 (Kitchen) dari browser ke printer LAN.' -Force | Out-Null
Start-ScheduledTask -TaskName $taskName

$health = $null
for ($i = 0; $i -lt 10 -and -not $health; $i++) {
    Start-Sleep -Seconds 1
    try { $health = Invoke-RestMethod -Uri "http://127.0.0.1:$port/health" -TimeoutSec 2 } catch {}
}

if ($health) {
    Write-Host "OK - Club61 Print Agent $($health.version) aktif di http://127.0.0.1:$port" -ForegroundColor Green
} else {
    Write-Host "Agent belum menjawab di http://127.0.0.1:$port." -ForegroundColor Yellow
    $info = Get-ScheduledTask -TaskName $taskName | Get-ScheduledTaskInfo
    Write-Host "Status Task Scheduler: hasil terakhir $($info.LastTaskResult) (0 = jalan normal)."
    if (Test-Path $log) {
        Write-Host '--- 5 baris terakhir agent.log ---'
        Get-Content $log -Tail 5
    } else {
        Write-Host 'agent.log belum ada: program belum sempat jalan sama sekali.'
    }
}
