<?php
/**
 * 領収書表示（お客様向け、Basic認証なし）
 *
 * URL: /receipt/view?order=...&email=...&to=...&note=...
 *  以下を満たす場合のみ領収書HTMLを返す（それ以外は /receipt にエラー付きリダイレクト）：
 *   - 注文番号と Shopify 注文時のメールアドレスが一致
 *   - 注文がキャンセルされていない
 *   - financial_status === 'paid'（未払いには領収書を発行しない）
 *  領収書には「何度でも発行可能・二重計上注意」の注意喚起文を固定で表示。
 *  発行履歴の永続化は行わない（Renderコンテナ再起動でリセットされ信頼性が低いため）。
 */

require_once 'config.php';

// ============ ユーティリティ ============
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function yen($n) {
    return number_format((int)round((float)$n));
}
function format_date_jp($iso) {
    if (!$iso) return '';
    $ts = strtotime($iso);
    if ($ts === false) return '';
    return date('Y年n月j日', $ts);
}
function redirect_with_error($order, $msg) {
    $qs = http_build_query(['order' => $order, 'error' => $msg]);
    header('Location: /receipt?' . $qs);
    exit;
}

// ============ Shopify API: 注文番号で1件取得 ============
function fetch_order_by_name($order_name) {
    $url = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/orders.json?'
         . http_build_query(['name' => '#' . $order_name, 'status' => 'any', 'limit' => 1]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $http >= 400) {
        error_log("[receipt] Shopify error http=$http err=$err");
        return null;
    }
    $data = json_decode($body, true);
    $orders = $data['orders'] ?? [];
    return $orders[0] ?? null;
}

// ============ Shopify API: 商品IDリストから tags を取得 ============
function fetch_products_tags($product_ids) {
    $product_ids = array_values(array_unique(array_filter($product_ids)));
    if (empty($product_ids)) return [];
    $url = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/products.json?'
        . http_build_query(['ids' => implode(',', $product_ids), 'fields' => 'id,tags', 'limit' => 250]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $body = curl_exec($ch);
    curl_close($ch);
    if ($body === false) return [];
    $data = json_decode($body, true);
    $out = [];
    foreach (($data['products'] ?? []) as $p) {
        $tags = array_map('trim', explode(',', $p['tags'] ?? ''));
        $out[(int)$p['id']] = $tags;
    }
    return $out;
}

/**
 * 注文の line_items を見て、講習/物販/混在 を判定
 * 'training' = 講習のみ / 'product' = 物販のみ / 'mixed' = 講習+物販の同時購入
 */
function detect_order_type($order) {
    $product_ids = array_filter(array_map(fn($li) => $li['product_id'] ?? null, $order['line_items'] ?? []));
    if (empty($product_ids)) return 'product'; // 不明時は物販扱い
    $tags_by_id = fetch_products_tags($product_ids);
    $has_training = false;
    $has_product = false;
    foreach ($tags_by_id as $tags) {
        if (in_array('講習', $tags, true)) {
            $has_training = true;
        } else {
            $has_product = true;
        }
    }
    if ($has_training && $has_product) return 'mixed';
    if ($has_training) return 'training';
    return 'product';
}

// ============ 入力検証 ============
$order_num = trim(ltrim((string)($_GET['order'] ?? ''), '#'));
$req_email = strtolower(trim((string)($_GET['email'] ?? '')));
$to_name_input = trim((string)($_GET['to'] ?? ''));
$note_input_raw = trim((string)($_GET['note'] ?? ''));
// 末尾の「として」を取り除く（重複防止の保険）
$note_input_raw = preg_replace('/[\s　]*として[\s　]*$/u', '', $note_input_raw);

if ($order_num === '' || $req_email === '') {
    redirect_with_error($order_num, '注文番号とメールアドレスを入力してください');
}

// ============ 注文取得 ============
$order = fetch_order_by_name($order_num);
if (!$order) {
    redirect_with_error($order_num, '該当する注文が見つかりませんでした。注文番号をご確認ください。');
}

// ============ メールアドレス一致確認 ============
$order_email = strtolower(trim((string)($order['email'] ?? '')));
if ($order_email === '' || $order_email !== $req_email) {
    error_log("[receipt] auth fail: order={$order['name']} expected=$order_email got=$req_email");
    redirect_with_error($order_num, 'メールアドレスがご注文時のものと一致しません。');
}

// ============ 支払い状態の確認 ============
// 発行可能: paid（支払完了）/ partially_refunded（一部返金済→実支払額で発行）
// 拒否:    pending / authorized / partially_paid / refunded / voided / cancelled
$financial_status = (string)($order['financial_status'] ?? '');
if (!empty($order['cancelled_at'])) {
    redirect_with_error($order_num, 'このご注文はキャンセルされているため、領収書を発行できません。');
}
$allowed_statuses = ['paid', 'partially_refunded'];
if (!in_array($financial_status, $allowed_statuses, true)) {
    $status_label_map = [
        'pending' => 'お支払い保留中',
        'authorized' => '与信のみ（未決済）',
        'partially_paid' => '一部入金済（未完了）',
        'refunded' => '全額返金済',
        'voided' => '無効化済',
    ];
    $label = $status_label_map[$financial_status] ?? '未払い';
    redirect_with_error($order_num, "このご注文は「{$label}」のため領収書を発行できません。お支払い完了後に再度お試しください。");
}

// ============ 注文タイプ判定（講習 / 物販 / 混在）→ 但し書きのデフォルト ============
$order_type = detect_order_type($order); // 'training' / 'product' / 'mixed'
$default_note = match ($order_type) {
    'training' => '受講料',
    'mixed'    => '受講料および商品代',
    default    => 'ご注文商品代',
};
$note_input = $note_input_raw !== '' ? $note_input_raw : $default_note;

// ============ 返金額の集計（partially_refunded の場合に実支払額を計算） ============
$total_refund = 0.0;
foreach ($order['refunds'] ?? [] as $r) {
    foreach ($r['transactions'] ?? [] as $t) {
        if (($t['kind'] ?? '') === 'refund' && ($t['status'] ?? '') === 'success') {
            $total_refund += (float)($t['amount'] ?? 0);
        }
    }
}
$is_partial_refund = $financial_status === 'partially_refunded' && $total_refund > 0;

// ============ 発行記録（ログのみ・保存はしない） ============
$now_iso = date('c');
error_log("[receipt] issued {$order['name']} to=$req_email");

// ============ 領収書データ組み立て ============
$addr = $order['billing_address'] ?? ($order['shipping_address'] ?? []);
if ($to_name_input !== '') {
    // お客様入力は敬称（様/御中）も含めて入力する前提なので、そのまま使う
    $display_to = $to_name_input;
} else {
    // 注文者名で自動補完。会社名があれば「○○ 御中」、個人名は「姓 名 様」
    $company = trim((string)($addr['company'] ?? ''));
    $person = trim(((string)($addr['last_name'] ?? '')) . ' ' . ((string)($addr['first_name'] ?? '')));
    if ($company !== '') {
        $display_to = $company . ' 御中';
    } elseif ($person !== '') {
        $display_to = $person . ' 様';
    } else {
        $display_to = '上様';
    }
}

$total = (float)($order['total_price'] ?? 0);
$total_tax = (float)($order['total_tax'] ?? 0);
$subtotal = (float)($order['subtotal_price'] ?? 0);
$discount = (float)($order['total_discounts'] ?? 0);
$shipping = 0.0;
foreach ($order['shipping_lines'] ?? [] as $s) {
    $shipping += (float)($s['price'] ?? 0);
}
$tax_excluded = $total - $total_tax;

// 返金後の実支払額（部分返金の場合のみ調整）
$real_paid = $total - $total_refund;

$items = [];
foreach ($order['line_items'] ?? [] as $li) {
    $qty = (int)($li['quantity'] ?? 0);
    $unit = (float)($li['price'] ?? 0);
    $items[] = [
        'title' => $li['title'] ?? '',
        'variant' => $li['variant_title'] ?? '',
        'sku' => $li['sku'] ?? '',
        'qty' => $qty,
        'unit' => $unit,
        'subtotal' => $unit * $qty,
    ];
}
$tax_lines = [];
foreach ($order['tax_lines'] ?? [] as $t) {
    $tax_lines[] = [
        'title' => $t['title'] ?? '消費税',
        'rate' => (float)($t['rate'] ?? 0),
        'price' => (float)($t['price'] ?? 0),
    ];
}

// 会社情報
$COMPANY = [
    'brand' => 'ロープアクセスラボ',
    'legal' => '4U合同会社',
    'zip' => '352-0005',
    'address1' => '埼玉県新座市中野2-3-9',
    'invoice_number' => 'T3030003019429',
];
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>領収書 <?= h($order['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@page { size: A4; margin: 18mm 16mm; }
* { box-sizing: border-box; }
body { font-family: "Hiragino Sans","Yu Gothic","Noto Sans JP",sans-serif; color:#111; background:#f3f4f6; margin:0; padding:24px; }
.sheet { background:white; width:210mm; min-height:297mm; margin:0 auto; padding:18mm 16mm; box-shadow:0 2px 12px rgba(0,0,0,0.08); position:relative; }
.head { text-align:center; border-bottom:3px double #111; padding-bottom:12px; margin-bottom:28px; }
.head h1 { font-size:28pt; letter-spacing:1em; padding-left:1em; margin:0; }
.meta { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:28px; }
.to-block .to-name { font-size:18pt; border-bottom:1px solid #444; padding-bottom:6px; min-width:280px; }
.to-block .to-addr { font-size:9pt; color:#555; margin-top:4px; }
.from-block { font-size:9pt; text-align:right; line-height:1.7; }
.from-block .company-name { font-size:12pt; font-weight:600; margin-bottom:4px; }
.amount-box { border:2px solid #111; padding:18px 20px; margin:24px 0; display:flex; align-items:center; justify-content:space-between; }
.amount-box .label { font-size:11pt; }
.amount-box .yen { font-size:22pt; font-weight:700; }
.note-line { margin:4px 0 24px; font-size:10pt; }
.note-line .note-text { border-bottom:1px solid #888; padding:0 8px; min-width:240px; display:inline-block; }
table.items { width:100%; border-collapse:collapse; margin:16px 0; font-size:9.5pt; }
table.items th, table.items td { border-bottom:1px solid #ccc; padding:6px 8px; text-align:left; vertical-align:top; }
table.items th { background:#f4f4f4; }
table.items td.num { text-align:right; }
.totals { width:280px; margin-left:auto; font-size:9.5pt; margin-top:12px; }
.totals .row { display:flex; justify-content:space-between; padding:4px 8px; }
.totals .row.grand { border-top:1px solid #111; border-bottom:1px solid #111; font-weight:700; font-size:11pt; padding:8px; margin-top:6px; }
.notice-box { margin-top:24px; padding:10px 14px; background:#fff8e1; border-left:4px solid #f59e0b; font-size:9pt; color:#5b4500; line-height:1.7; }
.footer-note { margin-top:16px; font-size:9pt; color:#555; line-height:1.8; border-top:1px solid #ccc; padding-top:12px; }
.print-bar { position:sticky; top:0; background:#1f2937; color:white; padding:10px 14px; margin-bottom:16px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
.print-bar a, .print-bar button { background:white; color:#1f2937; border:0; padding:6px 14px; border-radius:4px; cursor:pointer; font-size:0.9rem; font-weight:600; text-decoration:none; }
.print-bar a { background:#6b7280; color:white; }
[contenteditable="true"] { cursor:text; transition:background .15s, outline-color .15s; outline:1px dashed transparent; outline-offset:4px; border-radius:2px; }
[contenteditable="true"]:hover { background:#fff8d4; outline-color:#d4a200; }
[contenteditable="true"]:focus { background:#fffbe8; outline:2px dashed #d4a200; }
@media print {
    body { background:white; padding:0; }
    .sheet { box-shadow:none; padding:0; }
    .print-bar { display:none; }
    [contenteditable="true"] { background:transparent !important; outline:none !important; }
}
</style>
</head>
<body>
<div class="print-bar">
    <span>領収書 <?= h($order['name']) ?> 表示中　|　<strong>宛名・但し書きはクリックで編集可</strong>　|　Cmd+P でPDF保存</span>
    <div style="display:flex; gap:8px;">
        <a href="/receipt">← 入力画面に戻る</a>
        <button onclick="window.print()">PDFとして保存 / 印刷</button>
    </div>
</div>
<div class="sheet">
    <div class="head"><h1>領収書</h1></div>
    <div class="meta">
        <div class="to-block">
            <div class="to-name" contenteditable="true" spellcheck="false"><?= h($display_to) ?></div>
            <div class="to-addr" contenteditable="true" spellcheck="false">〒<?= h($addr['zip'] ?? '') ?> <?= h(trim(($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? ''))) ?></div>
        </div>
        <div class="from-block">
            <div>発行日: <?= h(format_date_jp($now_iso)) ?></div>
            <div>注文番号: <?= h($order['name']) ?></div>
            <div>注文日: <?= h(format_date_jp($order['created_at'] ?? '')) ?></div>
            <hr style="margin:8px 0; border:0; border-top:1px solid #ccc;">
            <div class="company-name"><?= h($COMPANY['brand']) ?></div>
            <div><?= h($COMPANY['legal']) ?></div>
            <div>〒<?= h($COMPANY['zip']) ?> <?= h($COMPANY['address1']) ?></div>
            <div style="margin-top:4px; font-weight:600;">登録番号: <?= h($COMPANY['invoice_number']) ?></div>
        </div>
    </div>
    <div class="amount-box">
        <div class="label">金額</div>
        <div class="yen">¥ <?= yen($real_paid) ?> <span style="font-size:10pt; font-weight:normal; margin-left:8px;">（税込<?= $is_partial_refund ? '・返金後の実支払額' : '' ?>）</span></div>
    </div>
    <div class="note-line">
        但し　<span class="note-text" contenteditable="true" spellcheck="false"><?= h($note_input) ?></span>　として
    </div>
    <p style="font-size:9.5pt;">上記正に領収いたしました。</p>
    <h3 style="font-size:11pt; border-bottom:1px solid #888; padding-bottom:4px; margin-top:24px;">明細</h3>
    <table class="items">
        <thead><tr><th>品名</th><th style="width:70px;">数量</th><th style="width:90px; text-align:right;">単価（税込）</th><th style="width:100px; text-align:right;">金額（税込）</th></tr></thead>
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
        <?php if ($discount > 0): ?>
        <div class="row" style="color:#c00;"><span>割引</span><span>- ¥ <?= yen($discount) ?></span></div>
        <?php endif; ?>
        <?php if ($shipping > 0): ?>
        <div class="row"><span>送料</span><span>¥ <?= yen($shipping) ?></span></div>
        <?php endif; ?>
        <div class="row"><span>注文時合計</span><span>¥ <?= yen($total) ?></span></div>
        <?php if ($is_partial_refund): ?>
        <div class="row" style="color:#c00;"><span>返金</span><span>- ¥ <?= yen($total_refund) ?></span></div>
        <?php endif; ?>
        <div class="row grand"><span><?= $is_partial_refund ? '実支払額（税込）' : '合計（税込）' ?></span><span>¥ <?= yen($real_paid) ?></span></div>
    </div>
    <div class="notice-box">
        <strong>ご注意：</strong>本領収書は同じ注文番号から何度でも発行できます。<br>
        二重計上による経理上のトラブルを避けるため、お客様ご自身で保管・管理にご注意ください。
        <?php if ($is_partial_refund): ?>
        <br><br>
        <strong>※ 一部返金処理について：</strong>本領収書は ¥<?= yen($total_refund) ?> の返金処理を反映した実支払額（¥<?= yen($real_paid) ?>）で発行しています。
        <?php endif; ?>
    </div>
    <div class="footer-note">
        ※ 本書は Shopify 注文 <?= h($order['name']) ?> に基づき発行しています。
    </div>
</div>
</body>
</html>
