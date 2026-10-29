<#
  Backs up the study tracker database to a dated .sql file in the "backups" folder.

  Usage (from the project folder):   powershell -ExecutionPolicy Bypass -File scripts\backup-database.ps1
  Optional:                          -Keep 60      (how many backups to keep; default 30)

  Reads DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD from .env. Only READS the database.
  To restore, see README.md ("Backups and restore").
#>
param([int]$Keep = 30)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env'
if (-not (Test-Path $envFile)) { throw ".env not found in $root" }

$cfg = @{}
foreach ($line in Get-Content $envFile) {
    if ($line -match '^\s*(DB_[A-Z_]+)\s*=\s*(.*)\s*$') { $cfg[$Matches[1]] = $Matches[2].Trim('"').Trim("'") }
}
$db   = $cfg['DB_DATABASE']; if (-not $db) { throw 'DB_DATABASE is not set in .env' }
$dbHost = if ($cfg['DB_HOST']) { $cfg['DB_HOST'] } else { '127.0.0.1' }
$port = if ($cfg['DB_PORT']) { $cfg['DB_PORT'] } else { '3306' }
$user = if ($cfg['DB_USERNAME']) { $cfg['DB_USERNAME'] } else { 'root' }
$pass = $cfg['DB_PASSWORD']

$dump = @('C:\xampp\mysql\bin\mysqldump.exe') + @((Get-Command mysqldump -ErrorAction SilentlyContinue).Source) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
if (-not $dump) { throw 'mysqldump.exe not found. Expected C:\xampp\mysql\bin\mysqldump.exe (XAMPP) or mysqldump on PATH.' }

$dir = Join-Path $root 'backups'
New-Item -ItemType Directory -Force $dir | Out-Null
$file = Join-Path $dir ("{0}_{1}.sql" -f $db, (Get-Date -Format 'yyyy-MM-dd_HHmm'))

$args = @("--host=$dbHost", "--port=$port", "--user=$user", '--single-transaction', '--routines', '--default-character-set=utf8mb4', "--result-file=$file", $db)
if ($pass) { $args = @("--password=$pass") + $args }
& $dump @args
if ($LASTEXITCODE -ne 0) { if (Test-Path $file) { Remove-Item $file }; throw "mysqldump failed (exit $LASTEXITCODE). Is MySQL running?" }

$size = (Get-Item $file).Length
if ($size -lt 2048) { throw "Backup file is suspiciously small ($size bytes): $file" }
"Backup saved: $file ($([math]::Round($size / 1KB)) KB)"

# keep only the newest $Keep backups for this database
Get-ChildItem $dir -Filter "${db}_*.sql" | Sort-Object LastWriteTime -Descending | Select-Object -Skip $Keep | Remove-Item -Force
"Backups kept: $((Get-ChildItem $dir -Filter "${db}_*.sql").Count) (newest $Keep)"
"Tip: also copy the 'backups' folder to a USB drive or cloud folder, so a dead computer does not take your records with it."
