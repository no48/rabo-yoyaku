<?php
/**
 * 請求書 表示画面（運営者用、Basic認証配下）
 *
 * URL: /invoice/view?order=<Shopify注文番号>
 *
 * 主な振る舞い:
 *   - Shopify から注文を取得（キャンセル済みは拒否）
 *   - 書類番号 (INV-YYYY-NNN) を自動採番
 *   - 宛名・件名・担当者・支払期限・備考 は contenteditable で編集可
 *   - 「PDFとして保存」ボタンでブラウザ印刷
 */

require_once 'config.php';
require_basic_auth();
require_once 'lib_shopify.php';
require_once 'lib_counter.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function yen($n) { return number_format((int)round((float)$n)); }
function format_date_jp($iso) {
    if (!$iso) return '';
    $ts = strtotime($iso);
    return $ts === false ? '' : date('Y年n月j日', $ts);
}

// ============ 入力検証 ============
$order_num = trim(ltrim((string)($_GET['order'] ?? ''), '#'));
if ($order_num === '') {
    http_response_code(400);
    echo '注文番号が指定されていません';
    exit;
}

// ============ 注文取得 ============
$order = fetch_order_by_name($order_num);
if (!$order) {
    http_response_code(404);
    echo '注文 #' . h($order_num) . ' が見つかりませんでした';
    exit;
}
if (!empty($order['cancelled_at'])) {
    http_response_code(400);
    echo 'この注文はキャンセル済みのため、請求書を発行できません。';
    exit;
}

// ============ 書類番号 自動採番 ============
$invoice_no = get_next_invoice_number();

// ============ データ組み立て ============
$addr = $order['billing_address'] ?? ($order['shipping_address'] ?? []);
$display_to = trim(($addr['company'] ?? '') ?: (($addr['last_name'] ?? '') . ' ' . ($addr['first_name'] ?? ''))) ?: '上様';

// 返金額
$total_refund = calc_refund_total($order);
$total = (float)($order['total_price'] ?? 0);
$total_tax = (float)($order['total_tax'] ?? 0);
$real_paid = $total - $total_refund;
$is_partial_refund = ($order['financial_status'] ?? '') === 'partially_refunded' && $total_refund > 0;
$tax_excluded = $total - $total_tax;

$items = [];
foreach ($order['line_items'] ?? [] as $li) {
    $qty = (int)($li['quantity'] ?? 0);
    $unit = (float)($li['price'] ?? 0);
    $items[] = [
        'title'    => $li['title'] ?? '',
        'variant'  => $li['variant_title'] ?? '',
        'sku'      => $li['sku'] ?? '',
        'qty'      => $qty,
        'unit'     => $unit,
        'subtotal' => $unit * $qty,
    ];
}
$tax_lines = [];
foreach ($order['tax_lines'] ?? [] as $t) {
    $tax_lines[] = [
        'rate'  => (float)($t['rate'] ?? 0),
        'price' => (float)($t['price'] ?? 0),
    ];
}

$today = date('c');
$pay_due_default = date('Y-m-d', strtotime('+30 day'));
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>請求書 <?= h($invoice_no) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@page { size: A4; margin: 18mm 16mm; }
* { box-sizing: border-box; }
body { font-family: "Hiragino Sans","Yu Gothic","Noto Sans JP",sans-serif; color:#111; background:#f3f4f6; margin:0; padding:24px; }
.sheet { background:white; width:210mm; min-height:297mm; margin:0 auto; padding:18mm 16mm; box-shadow:0 2px 12px rgba(0,0,0,0.08); position:relative; }
.head { text-align:center; border-bottom:3px double #111; padding-bottom:12px; margin-bottom:24px; }
.head h1 { font-size:28pt; letter-spacing:1em; padding-left:1em; margin:0; }
.head .doc-no { font-size:10pt; color:#555; margin-top:6px; }
.meta { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; }
.to-block .to-name { font-size:18pt; border-bottom:1px solid #444; padding-bottom:6px; min-width:280px; }
.to-block .to-addr { font-size:9pt; color:#555; margin-top:4px; }
.to-block .subject { margin-top:14px; font-size:11pt; }
.to-block .subject .label { color:#666; font-size:9pt; }
.from-block { font-size:9pt; text-align:right; line-height:1.7; position:relative; }
.from-block .company-name { font-size:12pt; font-weight:600; margin-bottom:4px; }
.from-block .stamp-area { position:absolute; right:0; top:6pt; width:60pt; height:60pt; border:1px dashed #ddd; display:flex; align-items:center; justify-content:center; color:#bbb; font-size:8pt; }
.from-block .stamp-area img { width:100%; height:100%; object-fit:contain; }
.dates { display:flex; gap:24px; margin-bottom:16px; font-size:10pt; }
.dates .label { color:#666; font-size:9pt; margin-right:4px; }
.amount-box { border:2px solid #111; padding:18px 20px; margin:18px 0; display:flex; align-items:center; justify-content:space-between; }
.amount-box .label { font-size:11pt; }
.amount-box .yen { font-size:22pt; font-weight:700; }
table.items { width:100%; border-collapse:collapse; margin:12px 0; font-size:9.5pt; }
table.items th, table.items td { border-bottom:1px solid #ccc; padding:6px 8px; text-align:left; vertical-align:top; }
table.items th { background:#f4f4f4; }
table.items td.num { text-align:right; }
.totals { width:280px; margin-left:auto; font-size:9.5pt; margin-top:8px; }
.totals .row { display:flex; justify-content:space-between; padding:4px 8px; }
.totals .row.grand { border-top:1px solid #111; border-bottom:1px solid #111; font-weight:700; font-size:11pt; padding:8px; margin-top:6px; }
.bank-box { margin-top:20px; padding:10px 14px; background:#fffaf0; border:1px solid #f0c878; font-size:9.5pt; line-height:1.7; }
.bank-box .label { color:#7c5a00; font-weight:600; margin-right:6px; }
.section-title { font-size:10pt; font-weight:600; color:#374151; margin-top:18px; border-left:3px solid #6b7280; padding-left:8px; }
.note-text { white-space:pre-wrap; font-size:9.5pt; line-height:1.7; min-height:3em; padding:6px 8px; background:#fafafa; border:1px solid #eee; border-radius:3px; margin-top:6px; }
.staff { margin-top:10px; font-size:10pt; text-align:right; }
.footer-note { margin-top:24px; font-size:9pt; color:#555; line-height:1.7; border-top:1px solid #ccc; padding-top:10px; }

/* 操作バー */
.print-bar { position:sticky; top:0; background:#1f2937; color:white; padding:10px 14px; margin-bottom:16px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; z-index:10; }
.print-bar a, .print-bar button { background:white; color:#1f2937; border:0; padding:6px 14px; border-radius:4px; cursor:pointer; font-size:0.9rem; font-weight:600; text-decoration:none; }
.print-bar a { background:#6b7280; color:white; }
.print-bar .primary { background:#2563eb; color:white; }

/* 編集可能フィールド */
[contenteditable="true"] { cursor:text; transition:background .15s, outline-color .15s; outline:1px dashed transparent; outline-offset:4px; border-radius:2px; }
[contenteditable="true"]:hover { background:#fff8d4; outline-color:#d4a200; }
[contenteditable="true"]:focus { background:#fffbe8; outline:2px dashed #d4a200; }

@media print {
    body { background:white; padding:0; }
    .sheet { box-shadow:none; padding:0; }
    .print-bar { display:none; }
    [contenteditable="true"] { background:transparent !important; outline:none !important; }
    .from-block .stamp-area { border:none; color:transparent; }
}
</style>
</head>
<body>

<div class="print-bar">
    <span>請求書 <?= h($invoice_no) ?> | 注文 <?= h($order['name']) ?> | <strong>黄色枠はクリックで編集可</strong> | Cmd+P で PDF</span>
    <div style="display:flex; gap:8px;">
        <a href="/list/">← 一覧に戻る</a>
        <button class="primary" onclick="window.print()">PDFとして保存 / 印刷</button>
    </div>
</div>

<div class="sheet">
    <div class="head">
        <h1>請求書</h1>
        <div class="doc-no">No. <span contenteditable="true" spellcheck="false"><?= h($invoice_no) ?></span></div>
    </div>

    <div class="meta">
        <div class="to-block">
            <div class="to-name" contenteditable="true" spellcheck="false"><?= h($display_to) ?></div>
            <div class="to-addr" contenteditable="true" spellcheck="false">〒<?= h($addr['zip'] ?? '') ?> <?= h(trim(($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? ''))) ?></div>
            <div class="subject">
                <div><span class="label">件名：</span><span contenteditable="true" spellcheck="false">ご請求の件</span></div>
            </div>
        </div>
        <div class="from-block">
            <div class="stamp-area">
                <?php if (file_exists(__DIR__ . '/../assets/stamp.png')): ?>
                <img src="/assets/stamp.png" alt="印">
                <?php else: ?>
                印
                <?php endif; ?>
            </div>
            <div class="company-name"><?= h(COMPANY_INFO['brand']) ?></div>
            <div><?= h(COMPANY_INFO['legal']) ?></div>
            <div>〒<?= h(COMPANY_INFO['zip']) ?> <?= h(COMPANY_INFO['address1']) ?></div>
            <div style="margin-top:4px; font-weight:600;">登録番号: <?= h(COMPANY_INFO['invoice_number']) ?></div>
        </div>
    </div>

    <div class="dates">
        <div><span class="label">発行日：</span><span contenteditable="true" spellcheck="false"><?= h(format_date_jp($today)) ?></span></div>
        <div><span class="label">お支払期限：</span><span contenteditable="true" spellcheck="false"><?= h(format_date_jp($pay_due_default)) ?></span></div>
    </div>

    <div class="amount-box">
        <div class="label">ご請求金額</div>
        <div class="yen">¥ <?= yen($real_paid) ?> <span style="font-size:10pt; font-weight:normal; margin-left:8px;">（税込<?= $is_partial_refund ? '・返金後の実支払額' : '' ?>）</span></div>
    </div>

    <p style="font-size:9.5pt;">下記の通りご請求申し上げます。</p>

    <h3 class="section-title">明細</h3>
    <table class="items">
        <thead>
        <tr>
            <th>品名</th>
            <th style="width:70px;">数量</th>
            <th style="width:90px; text-align:right;">単価（税込）</th>
            <th style="width:100px; text-align:right;">金額（税込）</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $it): ?>
            <tr>
                <td>
                    <?= h($it['title']) ?>
                    <?php if ($it['variant']): ?><br><small style="color:#666;"><?= h($it['variant']) ?></small><?php endif; ?>
                    <?php if ($it['sku']): ?><br><small style="color:#999;">SKU: <?= h($it['sku']) ?></small><?php endif; ?>
                </td>
                <td class="num"><?= h($it['qty']) ?></td>
                <td class="num">¥ <?= yen($it['unit']) ?></td>
                <td class="num">¥ <?= yen($it['subtotal']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="totals">
        <div class="row"><span>小計（税抜）</span><span>¥ <?= yen($tax_excluded) ?></span></div>
        <?php foreach ($tax_lines as $t): ?>
        <div class="row"><span>消費税（<?= (int)round($t['rate'] * 100) ?>%）</span><span>¥ <?= yen($t['price']) ?></span></div>
        <?php endforeach; ?>
        <div class="row"><span>注文時合計</span><span>¥ <?= yen($total) ?></span></div>
        <?php if ($is_partial_refund): ?>
        <div class="row" style="color:#c00;"><span>返金</span><span>- ¥ <?= yen($total_refund) ?></span></div>
        <?php endif; ?>
        <div class="row grand"><span><?= $is_partial_refund ? 'ご請求額（税込）' : 'ご請求合計（税込）' ?></span><span>¥ <?= yen($real_paid) ?></span></div>
    </div>

    <div class="bank-box">
        <div><span class="label">お振込先</span></div>
        <div><?= h(BANK_INFO['bank']) ?> <?= h(BANK_INFO['branch']) ?>　<?= h(BANK_INFO['type']) ?>　<?= h(BANK_INFO['number']) ?></div>
        <div>口座名義：<?= h(BANK_INFO['name']) ?></div>
        <div style="margin-top:4px; color:#7c5a00;">※ お振込手数料はお客様にてご負担をお願いいたします。</div>
    </div>

    <h3 class="section-title">備考</h3>
    <div class="note-text" contenteditable="true" spellcheck="false">　</div>

    <div class="staff">
        担当：<span contenteditable="true" spellcheck="false">　　　　　　　</span>
    </div>

    <div class="footer-note">
        ※ 本請求書は Shopify 注文 <?= h($order['name']) ?> に基づき発行しています。<br>
        ※ ご不明な点がございましたら、上記担当までお問い合わせください。
    </div>
</div>

</body>
</html>
