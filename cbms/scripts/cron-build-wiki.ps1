# ============================================================
# CBMS — Weekly wiki draft builder (Task Scheduler)
#
# รัน `php sync.php --build-wiki` แล้ว log ผลลง storage\logs\
# - build เฉพาะเอกสารที่เปลี่ยนหลังรอบ build ล่าสุด (idempotent —
#   สัปดาห์ไหนเอกสารไม่เปลี่ยนก็ไม่เสียค่า LLM)
# - หน้าที่ได้เป็น "ร่าง" เสมอ ต้องเข้า admin > Wiki Knowledge
#   ไป review แล้วกด Publish เอง (by design — กัน hallucination)
#
# ลงทะเบียน (รันครั้งเดียวใน PowerShell แบบ Administrator บนเซิร์ฟเวอร์):
#   schtasks /Create /TN "CBMS Build Wiki Weekly" /SC WEEKLY /D SUN /ST 03:00 /RU SYSTEM /RL HIGHEST /TR "powershell.exe -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\wwwroot\cbms\scripts\cron-build-wiki.ps1"
# ทดสอบทันที:
#   schtasks /Run /TN "CBMS Build Wiki Weekly"
# ============================================================

$AppDir = 'C:\inetpub\wwwroot\cbms'
$Log    = Join-Path $AppDir 'storage\logs\cron_build_wiki.log'

function Write-Log([string]$msg) {
    "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')] $msg" | Out-File $Log -Append -Encoding utf8
}

Write-Log '=== build-wiki start ==='

# Resolve php.exe — บัญชี SYSTEM อาจมี PATH ไม่เหมือน user ปกติ
$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    foreach ($p in @('C:\Program Files\PHP\php.exe', 'C:\PHP\php.exe', 'C:\php\php.exe')) {
        if (Test-Path $p) { $php = $p; break }
    }
}
if (-not $php) {
    Write-Log 'ERROR: php.exe not found in PATH or known locations — edit $php fallback list in this script'
    exit 1
}

try {
    $output = & $php (Join-Path $AppDir 'sync.php') --build-wiki 2>&1 | Out-String
    $output | Out-File $Log -Append -Encoding utf8
    Write-Log "=== done (exit $LASTEXITCODE) ==="
    exit $LASTEXITCODE
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
