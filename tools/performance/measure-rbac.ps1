param([string]$Php = 'C:/Users/hp/.config/herd/bin/php84/php.exe')

$ErrorActionPreference = 'Stop'
$samples = @()
foreach ($route in @('/finance/cash', '/reports', '/sales/orders')) {
    foreach ($run in 0..5) {
        $modes = if ($run % 2 -eq 0) { @('baseline', 'memo') } else { @('memo', 'baseline') }
        foreach ($mode in $modes) {
            $raw = & $Php tools/performance/rbac-benchmark.php $route 1 $mode
            if ($LASTEXITCODE -ne 0) { throw "Benchmark failed: $route $mode" }
            $row = $raw | ConvertFrom-Json
            if ($row.status -ne 200 -or -not $row.debugbar) { throw 'Unexpected response or Debugbar state' }
            $row | Add-Member run $run
            $samples += $row
        }
    }
}
$samples | ConvertTo-Json -Depth 5 | Set-Content tools/performance/results/rbac-paired.json
$summary = @()
foreach ($route in @('/finance/cash', '/reports', '/sales/orders')) {
    foreach ($memo in @($false, $true)) {
        $rows = @($samples | Where-Object { $_.route -eq $route -and $_.rbac_memo -eq $memo -and $_.run -gt 0 })
        $result = [ordered]@{ route = $route; rbac_memo = $memo; samples = $rows.Count }
        foreach ($metric in @('query_count', 'duplicated_queries', 'super_admin_queries', 'sql_ms', 'kernel_ms', 'total_ms')) {
            $values = @($rows | ForEach-Object { $_.$metric } | Sort-Object)
            $result[$metric] = $values[2]
        }
        $summary += [pscustomobject]$result
    }
}
$summary | ConvertTo-Json | Set-Content tools/performance/results/rbac-summary.json
$summary | Format-Table route,rbac_memo,query_count,duplicated_queries,super_admin_queries,sql_ms,total_ms
