<?php
/**
 * 月別売上集計 トップページ
 *
 * 直近 N ヶ月の月別合計を表示。各月の行をクリックすると /month?ym=YYYY-MM へ遷移。
 */
require_once 'config.php';
require_basic_auth();
require_once 'lib_shopify.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function yen($n) { return number_format((int)$n); }

// 何ヶ月分集計するか（デフォルト24ヶ月）
$months = max(1, min(60, (int)($_GET['months'] ?? 24)));

// 集計期間
$end = new DateTimeImmutable('first day of next month 00:00:00', new DateTimeZone('Asia/Tokyo'));
$start = $end->sub(new DateInterval("P{$months}M"));
$from_iso = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$to_iso = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

$error = null;
$monthly = [];
$grand_total = 0;
$grand_count = 0;
$grand_refund = 0;
try {
    $orders = fetch_orders_in_range($from_iso, $to_iso);
    $monthly = summarize_by_month($orders);
    foreach ($monthly as $m) {
        $grand_total += $m['total'];
        $grand_count += $m['count'];
        $grand_refund += $m['refund'];
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$grand_net = $grand_total - $grand_refund;
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>売上集計 - ロープアクセスラボ</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; max-width: 1000px; margin: 24px auto; padding: 0 24px; line-height: 1.6; color: #222; }
h1 { font-size: 1.5rem; margin-bottom: 4px; }
.brand { color: #6b7280; font-size: 0.85rem; }
.lead { color: #555; font-size: 0.9rem; margin-bottom: 16px; }
.actions { display:flex; gap:10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
.actions form { display:inline-flex; gap:6px; align-items:center; }
.actions select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; }
.actions a, .actions button { padding: 8px 14px; background: #2563eb; color: white; text-decoration: none; border: 0; border-radius: 4px; cursor: pointer; font-size: 0.9rem; font-weight: 600; }
.actions .csv { background: #059669; }
.summary { display: flex; gap: 24px; padding: 16px 20px; background: #f3f4f6; border-radius: 6px; margin-bottom: 16px; }
.summary .item { flex: 1; }
.summary .label { font-size: 0.8rem; color: #6b7280; }
.summary .value { font-size: 1.4rem; font-weight: 700; }
table { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
th, td { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
th { background: #f9fafb; font-size: 0.85rem; color: #374151; text-align: left; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
tr:hover { background: #fafafa; }
tr.zero td { color: #9ca3af; }
a.month-link { color: #2563eb; text-decoration: none; font-weight: 600; }
a.month-link:hover { text-decoration: underline; }
.error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
.muted { color: #6b7280; font-size: 0.85rem; }
</style>
</head>
<body>
<div class="brand">ロープアクセスラボ（4U合同会社）/ 運営者用</div>
<h1>売上集計</h1>
<p class="lead">直近 <strong><?= h($months) ?>ヶ月</strong>の月別売上を表示しています。月をクリックすると、その月の注文一覧が見られます。</p>

<?php if ($error): ?>
<div class="error"><?= h($error) ?></div>
<?php endif; ?>

<div class="actions">
    <form method="get">
        <label class="muted">期間：</label>
        <select name="months" onchange="this.form.submit()">
            <?php foreach ([3, 6, 12, 24, 36, 60] as $opt): ?>
            <option value="<?= $opt ?>"<?= $opt === $months ? ' selected' : '' ?>><?= $opt ?>ヶ月</option>
            <?php endforeach; ?>
        </select>
    </form>
    <a class="csv" href="/list/export?type=monthly&months=<?= h($months) ?>">月別合計 CSV ダウンロード</a>
</div>

<div class="summary">
    <div class="item"><div class="label">期間合計（税込）</div><div class="value">¥ <?= yen($grand_total) ?></div></div>
    <div class="item"><div class="label">期間合計（返金後）</div><div class="value">¥ <?= yen($grand_net) ?></div></div>
    <div class="item"><div class="label">注文件数</div><div class="value"><?= yen($grand_count) ?> 件</div></div>
    <div class="item"><div class="label">返金合計</div><div class="value">¥ <?= yen($grand_refund) ?></div></div>
</div>

<table>
    <thead>
        <tr>
            <th>月</th>
            <th class="num">注文件数</th>
            <th class="num">税込合計</th>
            <th class="num">うち消費税</th>
            <th class="num">割引</th>
            <th class="num">返金</th>
            <th class="num">実売上（返金後）</th>
            <th class="num">取消件数</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($monthly as $ym => $m): ?>
        <tr class="<?= $m['count'] === 0 ? 'zero' : '' ?>">
            <td>
                <?php if ($m['count'] > 0 || $m['cancelled'] > 0): ?>
                <a class="month-link" href="/list/month?ym=<?= h($ym) ?>"><?= h($ym) ?></a>
                <?php else: ?>
                <?= h($ym) ?>
                <?php endif; ?>
            </td>
            <td class="num"><?= yen($m['count']) ?></td>
            <td class="num">¥ <?= yen($m['total']) ?></td>
            <td class="num">¥ <?= yen($m['tax']) ?></td>
            <td class="num"><?= $m['discount'] > 0 ? '¥ ' . yen($m['discount']) : '-' ?></td>
            <td class="num"><?= $m['refund'] > 0 ? '¥ ' . yen($m['refund']) : '-' ?></td>
            <td class="num">¥ <?= yen($m['net']) ?></td>
            <td class="num"><?= $m['cancelled'] > 0 ? $m['cancelled'] : '-' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($monthly)): ?>
        <tr><td colspan="8" class="muted" style="text-align:center;">期間内に注文がありません</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<p class="muted" style="margin-top: 24px;">
    ※ 「税込合計」は注文時の合計（返金前）です。実売上は返金額を差し引いた金額。<br>
    ※ 取消（cancelled）は別カウントで売上には含まれません。
</p>
</body>
</html>
