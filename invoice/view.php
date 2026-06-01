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

// 送料（明細に1行として出すため合計を算出）
$shipping_total = 0.0;
foreach ($order['shipping_lines'] ?? [] as $sl) {
    $shipping_total += (float)($sl['price'] ?? 0);
}

$today = date('c');
// 支払期限＝注文日+7日（注意書き「ご注文日より7日以内」と整合）
$pay_due_default = date('Y-m-d', strtotime(($order['created_at'] ?? 'now') . ' +7 day'));
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
    .modal-bg { display:none !important; }
}

/* メール送信モーダル */
.modal-bg { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:100; }
.modal-bg.shown { display:flex; }
.modal { background:white; max-width:560px; width:92%; max-height:90vh; overflow-y:auto; padding:20px 24px; border-radius:8px; box-shadow:0 8px 32px rgba(0,0,0,0.2); }
.modal h2 { margin:0 0 12px; font-size:1.15rem; }
.modal label { display:grid; gap:4px; font-size:0.85rem; font-weight:600; margin-bottom:12px; }
.modal input[type=email], .modal input[type=text], .modal textarea { padding:8px 10px; border:1px solid #ccc; border-radius:4px; font-size:0.92rem; font-family:inherit; }
.modal textarea { min-height:180px; resize:vertical; }
.modal .modal-actions { display:flex; gap:8px; justify-content:flex-end; margin-top:16px; }
.modal .modal-actions button { padding:8px 16px; border:0; border-radius:4px; cursor:pointer; font-size:0.92rem; font-weight:600; }
.modal .btn-cancel { background:#e5e7eb; color:#374151; }
.modal .btn-send { background:#2563eb; color:white; }
.modal .btn-send:disabled { background:#9ca3af; cursor:not-allowed; }
.modal .alert { padding:10px 12px; border-radius:4px; margin-top:12px; font-size:0.9rem; display:none; }
.modal .alert.error { background:#fee2e2; color:#991b1b; display:block; }
.modal .alert.success { background:#d1fae5; color:#065f46; display:block; }
.modal .hint { font-size:0.8rem; color:#6b7280; font-weight:normal; margin-top:2px; }
</style>
</head>
<body>

<div class="print-bar">
    <span>請求書 <?= h($invoice_no) ?> | 注文 <?= h($order['name']) ?> | <strong>黄色枠はクリックで編集可</strong> | Cmd+P で PDF</span>
    <div style="display:flex; gap:8px;">
        <a href="/list/">← 一覧に戻る</a>
        <button onclick="openMailModal()" style="background:#059669; color:white;">📧 メールで送信</button>
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
        <?php if ($shipping_total > 0): ?>
            <tr>
                <td>送料</td>
                <td class="num">1</td>
                <td class="num">¥ <?= yen($shipping_total) ?></td>
                <td class="num">¥ <?= yen($shipping_total) ?></td>
            </tr>
        <?php endif; ?>
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
        <div style="margin-top:4px; color:#7c5a00;">※ お振込手数料はお客様のご負担となります。</div>
        <div style="margin-top:2px; color:#7c5a00;">※ ご注文日より7日以内にお振込みください。</div>
        <div style="margin-top:2px; color:#7c5a00;">※ ご入金確認後、商品の発送準備を開始いたします。</div>
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

<!-- メール送信モーダル -->
<div class="modal-bg" id="mailModal" onclick="if(event.target===this) closeMailModal()">
    <div class="modal">
        <h2>📧 請求書をメールで送信</h2>
        <p style="font-size:0.85rem; color:#6b7280; margin:0 0 12px;">画面上で編集中の内容で PDF を生成・添付して送信します。</p>

        <label>送信先メールアドレス <span style="color:#dc2626;">*</span>
            <input type="email" id="m_recipient" required placeholder="client@example.com" value="<?= h($order['email'] ?? '') ?>">
            <span class="hint">注文のメールアドレスを自動入力しています（必要なら変更可）</span>
        </label>

        <label>CC (任意、カンマ区切りで複数可)
            <input type="text" id="m_cc" placeholder="staff@example.com, manager@example.com">
        </label>

        <label>メール件名
            <input type="text" id="m_subject" value="<?= h('【' . COMPANY_INFO['brand'] . '】ご請求書送付のご案内（' . $invoice_no . '）') ?>">
            <span class="hint">自動入力しています（必要なら変更可）</span>
        </label>

        <label>メール本文
            <textarea id="m_body" placeholder="空欄ならデフォルトテンプレートで送信されます"></textarea>
            <span class="hint">空欄なら自動テンプレ（宛名・請求番号・金額・支払期限・振込先・注意事項・担当を含む挨拶文）で送信</span>
        </label>

        <div id="m_alert" class="alert"></div>

        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeMailModal()">キャンセル</button>
            <button class="btn-send" id="m_send_btn" onclick="sendMail()">送信</button>
        </div>
    </div>
</div>

<script>
// 請求書のコンテキスト (画面上の編集内容を読み取って送信)
const ORDER_NUM = <?= json_encode($order_num) ?>;

function $val(sel) { return (document.querySelector(sel)?.innerText || '').trim(); }
function getCtx() {
    return {
        order:      ORDER_NUM,
        invoice_no: $val('.head .doc-no span[contenteditable]'),
        to_name:    $val('.to-name'),
        subject:    $val('.subject span[contenteditable]'),
        issue_date: $val('.dates span:nth-of-type(1)[contenteditable], .dates div:nth-of-type(1) span[contenteditable]') ||
                    $val('.dates span[contenteditable]:nth-of-type(1)'),
        pay_due:    document.querySelectorAll('.dates span[contenteditable]')[1]?.innerText.trim() || '',
        note:       $val('.note-text'),
        staff:      $val('.staff span[contenteditable]')
    };
}

function openMailModal() {
    document.getElementById('m_alert').className = 'alert';
    document.getElementById('m_alert').textContent = '';
    document.getElementById('m_send_btn').disabled = false;
    document.getElementById('m_send_btn').textContent = '送信';
    document.getElementById('mailModal').classList.add('shown');
    setTimeout(() => document.getElementById('m_recipient').focus(), 50);
}

function closeMailModal() {
    document.getElementById('mailModal').classList.remove('shown');
}

async function sendMail() {
    const recipient = document.getElementById('m_recipient').value.trim();
    if (!recipient) {
        showAlert('送信先メールアドレスを入力してください', 'error');
        return;
    }
    const btn = document.getElementById('m_send_btn');
    btn.disabled = true;
    btn.textContent = '送信中...';
    showAlert('', '');

    const ctx = getCtx();
    const fd = new FormData();
    Object.entries(ctx).forEach(([k, v]) => fd.set(k, v));
    fd.set('recipient', recipient);
    fd.set('cc', document.getElementById('m_cc').value.trim());
    fd.set('mail_subject', document.getElementById('m_subject').value.trim());
    fd.set('mail_body', document.getElementById('m_body').value);
    // 金額は表示テキストから取り出すと丸めで違う可能性があるので、サーバー側で再計算する設計
    fd.set('total_real', '');

    try {
        const res = await fetch('/invoice/send.php', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await res.json();
        if (data.ok) {
            showAlert(`✅ ${recipient} に送信しました`, 'success');
            btn.textContent = '送信済';
        } else {
            showAlert('❌ ' + (data.error || '不明なエラー'), 'error');
            btn.disabled = false;
            btn.textContent = '送信';
        }
    } catch (e) {
        showAlert('❌ 通信エラー: ' + e.message, 'error');
        btn.disabled = false;
        btn.textContent = '送信';
    }
}

function showAlert(text, type) {
    const el = document.getElementById('m_alert');
    el.textContent = text;
    el.className = 'alert' + (type ? ' ' + type : '');
}
</script>

</body>
</html>
