$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Plugin = Join-Path $Root "echo-platform"
$Build = Join-Path $Root "build"
$Main = Join-Path $Plugin "echo-motorworks-core.php"
$Version = "dev"
$Match = Select-String -Path $Main -Pattern '^ \* Version:\s*(.+)$' | Select-Object -First 1
if ($Match) { $Version = $Match.Matches[0].Groups[1].Value.Trim() }
New-Item -ItemType Directory -Force -Path $Build | Out-Null
$Out = Join-Path $Build "echo-platform-os-v$Version.zip"
if (Test-Path $Out) { Remove-Item $Out -Force }
$Temp = Join-Path ([System.IO.Path]::GetTempPath()) ("echo-platform-build-" + [guid]::NewGuid())
New-Item -ItemType Directory -Force -Path $Temp | Out-Null
Copy-Item -Recurse -Force $Plugin (Join-Path $Temp "echo-motorworks-core")
Compress-Archive -Path (Join-Path $Temp "echo-motorworks-core") -DestinationPath $Out -CompressionLevel Optimal
Remove-Item -Recurse -Force $Temp
Write-Host "Built: $Out"
