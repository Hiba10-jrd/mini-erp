param([string]$Php = 'C:/Users/hp/.config/herd/bin/php84/php.exe')
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path .).Path
$resultDirectory = Join-Path $root 'tools/performance/results'
New-Item -ItemType Directory -Force $resultDirectory | Out-Null
$initial = @{}
foreach ($name in @('config.php','routes-v7.php','events.php')) {
    $path = Join-Path $root "bootstrap/cache/$name"
    $initial[$name] = if (Test-Path -LiteralPath $path) { [IO.File]::ReadAllBytes($path) } else { $null }
}
$rows = @()
try {
    foreach ($stage in @('clear','view-cache','optimize')) {
        foreach ($route in @('/reports','/sales/orders','/finance/cash','/administration/suppliers')) {
            foreach ($command in @('config:clear','route:clear','event:clear','view:clear')) {
                & $Php artisan $command | Out-Null
                if ($LASTEXITCODE -ne 0) { throw "Clear failed: $command" }
            }
            if ($stage -eq 'view-cache') { & $Php artisan view:cache | Out-Null }
            if ($stage -eq 'optimize') { & $Php artisan optimize | Out-Null }
            if ($LASTEXITCODE -ne 0) { throw 'Preparation failed' }
            foreach ($pass in 1..3) {
                $raw = & $Php tools/performance/cold-probe.php $route
                $row = $raw | ConvertFrom-Json
                if ($row.status -ne 200 -or $row.debugbar) { throw 'Unexpected response/debugbar' }
                $row | Add-Member stage $stage
                $row | Add-Member pass $pass
                $rows += $row
                $rows | ConvertTo-Json -Depth 8 | Set-Content "$resultDirectory/cold-matrix.json"
            }
        }
    }
} finally {
    foreach ($name in $initial.Keys) {
        $path = Join-Path $root "bootstrap/cache/$name"
        if ($null -ne $initial[$name]) { [IO.File]::WriteAllBytes($path, $initial[$name]) }
        elseif (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path }
    }
}
$rows | Format-Table stage,route,pass,created_count,compile_ms,kernel_ms,total_ms,queries,sql_ms
