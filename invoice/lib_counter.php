<?php
/**
 * 請求書番号 自動採番
 *
 * 番号フォーマット: INV-YYYY-NNN
 * 保存先: invoice_counter.json (コンテナ内ファイル、Render再起動でリセットされうる)
 *
 * 将来の永続化:
 *  - DB（Render Postgres / Supabase / Neon）
 *  - Google Sheets API
 *  - Render Disk (有料$1/月)
 * いずれに移行する場合も get_next_invoice_number() の中身だけ差し替えれば良いよう隔離。
 */

function get_next_invoice_number(): string {
    $file = __DIR__ . '/invoice_counter.json';
    $year = date('Y');
    $data = [];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = json_decode($raw, true) ?: [];
        }
    }
    $n = (int)($data[$year] ?? 0) + 1;
    $data[$year] = $n;
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    return sprintf('INV-%s-%03d', $year, $n);
}
