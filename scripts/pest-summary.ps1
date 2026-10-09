param([string]$Target = '')

$raw = & php vendor/bin/pest $Target 2>&1 | Out-String
try {
    $j = $raw | ConvertFrom-Json
} catch {
    $raw
    exit 1
}

"result=$($j.result) tests=$($j.tests) passed=$($j.passed) failed=$($j.failed) errors=$($j.errors) duration_ms=$($j.duration_ms)"
foreach ($f in @($j.failures) + @($j.error_details)) {
    if (-not $f) { continue }
    $msg = ($f.message | Out-String)
    "---- $($f.test)"
    $msg.Substring(0, [Math]::Min(900, $msg.Length))
}
