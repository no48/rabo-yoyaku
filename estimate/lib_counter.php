<?php
/**
 * 見積番号 自動採番  EST-YYYY-NNN
 * 保存先: estimate_counter.json（Render再起動でリセットされうる。請求書と同方針）
 */
function get_next_estimate_number(): string {
    $file = __DIR__ . '/estimate_counter.json';
    $year = date('Y');
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) $data = json_decode($raw, true) ?: [];
    }
    $n = (int)($data[$year] ?? 0) + 1;
    $data[$year] = $n;
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    return sprintf('EST-%s-%03d', $year, $n);
}
