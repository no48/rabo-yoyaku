<?php
/**
 * CSV ダウンロード
 *  - /export?type=monthly&months=24 : 月別合計 CSV
 *  - /export?ym=YYYY-MM             : 指定月の注文一覧 CSV
 *  - /export?from=YYYY-MM-DD&to=YYYY-MM-DD : 期間指定の注文一覧 CSV
 */
require_once 'config.php';
require_basic_auth();
require_once 'lib_shopify.php';

$type = $_GET['type'] ?? '';
$ym = $_GET['ym'] ?? '';

if ($type === 'monthly') {
    // 月別合計 CSV
    $months = max(1, min(60, (int)($_GET['months'] ?? 24)));
    $end = new DateTimeImmutable('first day of next month 00:00:00', new DateTimeZone('Asia/Tokyo'));
    $start = $end->sub(new DateInterval("P{$months}M"));
    $from_iso = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $to_iso = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $orders = fetch_orders_in_range($from_iso, $to_iso);
    $monthly = summarize_by_month($orders);

    output_csv("monthly_summary_{$months}m.csv", function ($fh) use ($monthly) {
        fputcsv($fh, ['月', '注文件数', '税込合計', 'うち消費税', '割引合計', '返金合計', '実売上(返金後)', '取消件数']);
        $data_start_row = 2; // ヘッダーの次行
        foreach ($monthly as $ym => $m) {
            fputcsv($fh, [
                $ym,
                $m['count'],
                $m['total'],
                $m['tax'],
                $m['discount'],
                $m['refund'],
                $m['net'],
                $m['cancelled'],
            ]);
        }
        $data_end_row = $data_start_row + count($monthly) - 1;
        if (count($monthly) > 0) {
            // 合計行（Excel関数で SUM、行を消したら自動再計算される）
            fputcsv($fh, [
                '合計',
                "=SUM(B{$data_start_row}:B{$data_end_row})",
                "=SUM(C{$data_start_row}:C{$data_end_row})",
                "=SUM(D{$data_start_row}:D{$data_end_row})",
                "=SUM(E{$data_start_row}:E{$data_end_row})",
                "=SUM(F{$data_start_row}:F{$data_end_row})",
                "=SUM(G{$data_start_row}:G{$data_end_row})",
                "=SUM(H{$data_start_row}:H{$data_end_row})",
            ]);
        }
    });
    exit;
}

// 単月の注文一覧 CSV
if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $start = new DateTimeImmutable($ym . '-01 00:00:00', new DateTimeZone('Asia/Tokyo'));
    $end = $start->add(new DateInterval('P1M'));
} elseif (!empty($_GET['from']) && !empty($_GET['to'])) {
    $start = new DateTimeImmutable($_GET['from'] . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));
    $end = (new DateTimeImmutable($_GET['to'] . ' 00:00:00', new DateTimeZone('Asia/Tokyo')))->add(new DateInterval('P1D'));
    $ym = $_GET['from'] . '_' . $_GET['to'];
} else {
    header('Location: /list/');
    exit;
}
$from_iso = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$to_iso = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$orders = fetch_orders_in_range($from_iso, $to_iso);

output_csv("orders_{$ym}.csv", function ($fh) use ($orders) {
    fputcsv($fh, ['注文番号', '注文日', 'お客様名', 'メール', '明細数', '税込合計', 'うち消費税', '返金', '実売上', '支払状態', '取消']);
    // 注文日 昇順
    usort($orders, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));
    $data_start_row = 2;
    foreach ($orders as $o) {
        $r = order_row($o);
        fputcsv($fh, [
            $r['order_name'],
            $r['created_at'],
            $r['customer'],
            $r['email'],
            $r['items'],
            $r['total'],
            $r['tax'],
            $r['refund'],
            $r['net'],
            status_label($r['financial_status']),
            $r['cancelled'],
        ]);
    }
    $data_end_row = $data_start_row + count($orders) - 1;
    if (count($orders) > 0) {
        // 空行を1行入れて見やすく
        fputcsv($fh, ['']);
        // 合計行1: 全件合計（キャンセル含む）
        fputcsv($fh, [
            '合計（全件）', '', '', '', '',
            "=SUM(F{$data_start_row}:F{$data_end_row})",
            "=SUM(G{$data_start_row}:G{$data_end_row})",
            "=SUM(H{$data_start_row}:H{$data_end_row})",
            "=SUM(I{$data_start_row}:I{$data_end_row})",
            '', '',
        ]);
        // 合計行2: キャンセル除外 (K列=取消が空のものだけ集計)
        fputcsv($fh, [
            '合計（キャンセル除く）', '', '', '', '',
            "=SUMIFS(F{$data_start_row}:F{$data_end_row},K{$data_start_row}:K{$data_end_row},\"\")",
            "=SUMIFS(G{$data_start_row}:G{$data_end_row},K{$data_start_row}:K{$data_end_row},\"\")",
            "=SUMIFS(H{$data_start_row}:H{$data_end_row},K{$data_start_row}:K{$data_end_row},\"\")",
            "=SUMIFS(I{$data_start_row}:I{$data_end_row},K{$data_start_row}:K{$data_end_row},\"\")",
            '', '',
        ]);
    }
});

/**
 * CSV を Excel が文字化けしない形式 (UTF-8 BOM 付き) で出力
 */
function output_csv($filename, callable $writer) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM (Excelの文字化け回避)
    $fh = fopen('php://output', 'w');
    $writer($fh);
    fclose($fh);
}
