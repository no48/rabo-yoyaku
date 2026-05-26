<?php
/**
 * 月別 注文一覧
 * URL: /month?ym=YYYY-MM
 */
require_once 'config.php';
require_basic_auth();
require_once 'lib_shopify.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function yen($n) { return number_format((int)$n); }

$ym = $_GET['ym'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    header('Location: /list/');
    exit;
}

try {
    $start = new DateTimeImmutable($ym . '-01 00:00:00', new DateTimeZone('Asia/Tokyo'));
} catch (Exception $e) {
    header('Location: /list/');
    exit;
}
$end = $start->add(new DateInterval('P1M'));
$from_iso = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$to_iso = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

$error = null;
$rows = [];
$total = 0; $tax = 0; $refund = 0; $count = 0; $cancelled = 0;
try {
    $orders = fetch_orders_in_range($from_iso, $to_iso);
    foreach ($orders as $o) {
        $r = order_row($o);
        $rows[] = $r;
        if ($r['cancelled'] === 'yes') {
            $cancelled++;
        } else {
            $total += $r['total'];
            $tax += $r['tax'];
            $refund += $r['refund'];
            $count++;
        }
    }
    // 注文日 昇順
    usort($rows, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$net = $total - $refund;
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title><?= h($ym) ?> の注文一覧 - 売上集計</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; max-width: 1200px; margin: 24px auto; padding: 0 24px; line-height: 1.6; color: #222; }
h1 { font-size: 1.4rem; margin-bottom: 4px; }
.brand { color: #6b7280; font-size: 0.85rem; }
.actions { display:flex; gap:10px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
.actions a { padding: 8px 14px; background: #6b7280; color: white; text-decoration: none; border-radius: 4px; font-size: 0.9rem; font-weight: 600; }
.actions a.csv { background: #059669; }
.actions a.back { background: #4b5563; }
.summary { display: flex; gap: 24px; padding: 16px 20px; background: #f3f4f6; border-radius: 6px; margin-bottom: 16px; }
.summary .item { flex: 1; }
.summary .label { font-size: 0.8rem; color: #6b7280; }
.summary .value { font-size: 1.3rem; font-weight: 700; }
table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
th, td { padding: 8px 10px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
th { background: #f9fafb; font-size: 0.8rem; color: #374151; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
tr.cancelled { background: #fef3c7; color: #92400e; }
tr.refunded td { color: #b45309; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600; }
.badge.paid { background: #d1fae5; color: #065f46; }
.badge.pending, .badge.authorized { background: #fef3c7; color: #92400e; }
.badge.partially_refunded, .badge.refunded { background: #fecaca; color: #991b1b; }
.badge.cancelled { background: #e5e7eb; color: #4b5563; }
.error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; }
.muted { color: #6b7280; font-size: 0.85rem; }
.row-actions { display: flex; gap: 4px; align-items: center; }
.btn-remove { width: 26px; height: 26px; padding: 0; border: 1px solid #fca5a5; background: #fff; color: #dc2626; cursor: pointer; border-radius: 50%; font-size: 14px; font-weight: 700; line-height: 1; display: inline-flex; align-items: center; justify-content: center; }
.btn-remove:hover { background: #fee2e2; }
tr.removed { display: none; }
.reset-area { margin: 8px 0 12px; font-size: 0.85rem; color: #6b7280; display: none; align-items: center; gap: 12px; }
.reset-area.shown { display: flex; }
.btn-reset { padding: 4px 12px; background: #f59e0b; color: white; border: 0; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 600; }
.btn-reset:hover { background: #d97706; }
.is-filtered { color: #d97706; font-weight: 700; }
</style>
</head>
<body>
<div class="brand">ロープアクセスラボ（4U合同会社）/ 運営者用</div>
<h1><?= h($ym) ?> の注文一覧</h1>

<?php if ($error): ?>
<div class="error"><?= h($error) ?></div>
<?php endif; ?>

<div class="actions">
    <a class="back" href="/list/">← 月別一覧に戻る</a>
    <a class="csv" href="/list/export?ym=<?= h($ym) ?>"><?= h($ym) ?> の注文を CSV ダウンロード</a>
</div>

<div class="summary">
    <div class="item"><div class="label">注文件数</div><div class="value" id="sum-count"><?= yen($count) ?> 件</div></div>
    <div class="item"><div class="label">税込合計</div><div class="value" id="sum-total">¥ <?= yen($total) ?></div></div>
    <div class="item"><div class="label">うち消費税</div><div class="value" id="sum-tax">¥ <?= yen($tax) ?></div></div>
    <div class="item"><div class="label">返金合計</div><div class="value" id="sum-refund">¥ <?= yen($refund) ?></div></div>
    <div class="item"><div class="label">実売上</div><div class="value" id="sum-net">¥ <?= yen($net) ?></div></div>
    <?php if ($cancelled > 0): ?>
    <div class="item"><div class="label">取消件数</div><div class="value"><?= yen($cancelled) ?></div></div>
    <?php endif; ?>
</div>

<div class="muted" style="margin: -8px 0 16px; padding: 10px 14px; background: #fff8e1; border-left: 4px solid #f59e0b; border-radius: 0 4px 4px 0;">
    <strong style="color: #5b4500;">合計の対象：</strong>
    キャンセル注文（黄色背景）を除く全ての注文を集計しています。<br>
    支払状態の意味：
    <span class="badge paid">支払済</span>=入金確認済み
    / <span class="badge pending">保留</span>=未入金（銀行振込待ち等）
    / <span class="badge partially_refunded">一部返金</span>=入金後に一部返金
    / <span class="badge refunded">返金済</span>=全額返金
    / <span class="badge cancelled">無効</span>=取引キャンセル
    <br>
    <strong>未入金（保留）や無効注文を合計から外したい場合は、各行の「×」ボタンで除外できます</strong>（その場で合計が再計算されます。ページ再読込で戻ります）。
</div>

<div id="reset-area" class="reset-area">
    <span class="is-filtered">フィルタ中：<span id="removed-count">0</span> 件を除外しています</span>
    <button type="button" class="btn-reset" onclick="resetRows()">全て表示に戻す</button>
</div>

<table id="orders-table">
    <thead>
        <tr>
            <th style="width: 38px;"></th>
            <th>注文番号</th>
            <th>注文日</th>
            <th>お客様名</th>
            <th>メール</th>
            <th class="num">明細数</th>
            <th class="num">税込合計</th>
            <th class="num">返金</th>
            <th class="num">実売上</th>
            <th>支払状態</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['cancelled'] === 'yes' ? 'cancelled' : ($r['refund'] > 0 ? 'refunded' : '') ?>"
            data-total="<?= (int)$r['total'] ?>" data-tax="<?= (int)$r['tax'] ?>" data-refund="<?= (int)$r['refund'] ?>"
            data-net="<?= (int)$r['net'] ?>" data-cancelled="<?= h($r['cancelled']) ?>">
            <td>
                <button type="button" class="btn-remove" title="この行を合計から除外" onclick="removeRow(this)" aria-label="削除">×</button>
            </td>
            <td><strong><?= h($r['order_name']) ?></strong></td>
            <td><?= h($r['created_at']) ?></td>
            <td><?= h($r['customer']) ?></td>
            <td><?= h($r['email']) ?></td>
            <td class="num"><?= yen($r['items']) ?></td>
            <td class="num">¥ <?= yen($r['total']) ?></td>
            <td class="num"><?= $r['refund'] > 0 ? '¥ ' . yen($r['refund']) : '-' ?></td>
            <td class="num">¥ <?= yen($r['net']) ?></td>
            <td>
                <?php if ($r['cancelled'] === 'yes'): ?>
                    <span class="badge cancelled">キャンセル</span>
                <?php else: ?>
                    <span class="badge <?= h($r['financial_status']) ?>"><?= h(status_label($r['financial_status'])) ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
        <tr><td colspan="10" class="muted" style="text-align:center;"><?= h($ym) ?> の注文はありません</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<p class="muted" style="margin-top: 24px;">
    ※ 「税込合計」は注文時の合計、「実売上」は返金額を差し引いた金額。<br>
    ※ キャンセル注文は売上には含まれません（黄色背景で区別）。<br>
    ※ ×ボタンで行を除外しても Shopify の注文データは消えません（画面上だけ・ページ再読込で戻る）。
</p>

<script>
function fmt(n) { return '¥ ' + Number(n).toLocaleString('ja-JP'); }

function removeRow(btn) {
    const tr = btn.closest('tr');
    if (!tr || tr.classList.contains('removed')) return;
    tr.classList.add('removed');
    recalcSummary();
}

function resetRows() {
    document.querySelectorAll('#orders-table tbody tr.removed').forEach(tr => tr.classList.remove('removed'));
    recalcSummary();
}

function recalcSummary() {
    let count = 0, total = 0, tax = 0, refund = 0, net = 0, removed = 0;
    document.querySelectorAll('#orders-table tbody tr').forEach(tr => {
        if (tr.classList.contains('removed')) { removed++; return; }
        // cancelled は元から合計に入れていない
        if (tr.dataset.cancelled === 'yes') return;
        total  += Number(tr.dataset.total  || 0);
        tax    += Number(tr.dataset.tax    || 0);
        refund += Number(tr.dataset.refund || 0);
        net    += Number(tr.dataset.net    || 0);
        count++;
    });
    document.getElementById('sum-count').textContent  = count.toLocaleString('ja-JP') + ' 件';
    document.getElementById('sum-total').textContent  = fmt(total);
    document.getElementById('sum-tax').textContent    = fmt(tax);
    document.getElementById('sum-refund').textContent = fmt(refund);
    document.getElementById('sum-net').textContent    = fmt(net);
    document.getElementById('removed-count').textContent = removed;
    document.getElementById('reset-area').classList.toggle('shown', removed > 0);
}
</script>
</body>
</html>
