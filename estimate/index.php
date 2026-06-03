<?php
/**
 * 見積書ビルダー（運営者用・Basic認証配下）
 * URL: /estimate/
 *
 *  - 明細を「Shopify商品から追加」または「手入力」で組み立て
 *  - 小計(税抜)・消費税(10%)・お見積金額(税込) を自動計算（単価は税込前提）
 *  - 「PDF保存/印刷」= ブラウザ印刷／「メールで送信」= send.php へPOST
 */
require_once 'config.php';
require_basic_auth();
require_once 'lib_counter.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_jp($iso) { $ts = strtotime($iso); return $ts === false ? '' : date('Y年n月j日', $ts); }

$estimate_no = get_next_estimate_number();
$issue_default = fmt_jp(date('Y-m-d'));
$valid_default = fmt_jp(date('Y-m-d', strtotime('+30 day')));  // 有効期限＝発行日+30日
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>見積書 <?= h($estimate_no) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@page { size: A4; margin: 18mm 16mm; }
* { box-sizing: border-box; }
body { font-family:"Hiragino Sans","Yu Gothic","Noto Sans JP",sans-serif; color:#111; background:#f3f4f6; margin:0; padding:24px; }
.toolbar { max-width:210mm; margin:0 auto 16px; display:flex; gap:10px; flex-wrap:wrap; align-items:center; background:#fff; padding:12px 16px; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
.toolbar button { padding:8px 14px; border:0; border-radius:6px; cursor:pointer; font-size:.9rem; font-weight:600; }
.toolbar .primary { background:#2563eb; color:#fff; }
.toolbar .add { background:#059669; color:#fff; }
.toolbar .mail { background:#7c3aed; color:#fff; }
.picker { position:relative; flex:1; min-width:220px; }
.picker input { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:4px; font-size:.9rem; }
.picker .drop { position:absolute; z-index:30; left:0; right:0; top:100%; background:#fff; border:1px solid #ddd; border-radius:0 0 6px 6px; max-height:280px; overflow:auto; display:none; box-shadow:0 4px 12px rgba(0,0,0,.12); }
.picker .drop.show { display:block; }
.picker .opt { padding:7px 10px; font-size:.85rem; cursor:pointer; border-bottom:1px solid #f0f0f0; }
.picker .opt:hover { background:#eff6ff; }
.picker .opt .p { color:#059669; font-weight:600; margin-left:6px; }

.sheet { background:#fff; width:210mm; min-height:297mm; margin:0 auto; padding:18mm 16mm; box-shadow:0 2px 12px rgba(0,0,0,.08); position:relative; }
.head { text-align:center; border-bottom:3px double #111; padding-bottom:12px; margin-bottom:24px; }
.head h1 { font-size:28pt; letter-spacing:1em; padding-left:1em; margin:0; }
.head .doc-no { font-size:10pt; color:#555; margin-top:6px; }
.meta { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; }
.to-block .to-name { font-size:18pt; border-bottom:1px solid #444; padding-bottom:6px; min-width:280px; }
.to-block .to-addr { font-size:9pt; color:#555; margin-top:4px; }
.to-block .subject { margin-top:14px; font-size:11pt; }
.to-block .subject .label { color:#666; font-size:9pt; }
.from-block { font-size:9pt; text-align:right; line-height:1.7; }
.from-block .company-name { font-size:12pt; font-weight:600; margin-bottom:4px; }
.dates { display:flex; gap:24px; margin-bottom:16px; font-size:10pt; }
.dates .label { color:#666; font-size:9pt; margin-right:4px; }
.amount-box { border:2px solid #111; padding:18px 20px; margin:18px 0; display:flex; align-items:center; justify-content:space-between; }
.amount-box .label { font-size:11pt; }
.amount-box .yen { font-size:22pt; font-weight:700; }
table.items { width:100%; border-collapse:collapse; margin:12px 0; font-size:9.5pt; }
table.items th, table.items td { border-bottom:1px solid #ccc; padding:6px 8px; text-align:left; vertical-align:middle; }
table.items th { background:#f4f4f4; }
table.items td.num, table.items th.num { text-align:right; }
table.items input { border:0; border-bottom:1px dashed #cbd5e1; background:#fff8d4; font:inherit; width:100%; padding:2px 0; }
table.items input.num { text-align:right; }
table.items input:focus { outline:0; border-bottom:1px solid #2563eb; background:#fffbe8; }
.row-del { width:30px; text-align:center; }
.btn-del { border:1px solid #fca5a5; background:#fff; color:#dc2626; width:22px; height:22px; border-radius:50%; cursor:pointer; font-weight:700; line-height:1; }
.totals { width:280px; margin-left:auto; font-size:9.5pt; margin-top:8px; }
.totals .row { display:flex; justify-content:space-between; padding:4px 8px; }
.totals .row.grand { border-top:1px solid #111; border-bottom:1px solid #111; font-weight:700; font-size:11pt; padding:8px; margin-top:6px; }
.section-title { font-size:10pt; font-weight:600; color:#374151; margin-top:18px; border-left:3px solid #6b7280; padding-left:8px; }
.note-text { white-space:pre-wrap; font-size:9.5pt; line-height:1.7; min-height:3em; padding:6px 8px; background:#fafafa; border:1px solid #eee; border-radius:3px; margin-top:6px; }
.staff { margin-top:10px; font-size:10pt; text-align:right; }
.footer-note { margin-top:24px; font-size:9pt; color:#555; line-height:1.7; border-top:1px solid #ccc; padding-top:10px; }
[contenteditable="true"] { background:#fff8d4; border-radius:2px; }
[contenteditable]:focus { outline:1px solid #2563eb; background:#fffbe8; }

.modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.4); display:none; align-items:center; justify-content:center; z-index:50; }
.modal-bg.shown { display:flex; }
.modal { background:#fff; width:440px; max-width:92vw; border-radius:10px; padding:20px; }
.modal h2 { font-size:1.1rem; margin:0 0 12px; }
.modal label { display:block; font-size:.85rem; margin-bottom:10px; }
.modal input[type=email], .modal input[type=text], .modal textarea { width:100%; padding:8px 10px; border:1px solid #ccc; border-radius:4px; font-size:.92rem; font-family:inherit; margin-top:3px; }
.modal textarea { min-height:90px; }
.modal .hint { color:#6b7280; font-size:.78rem; }
.modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:8px; }
.btn-cancel { background:#e5e7eb; border:0; padding:9px 16px; border-radius:6px; cursor:pointer; }
.btn-send { background:#7c3aed; color:#fff; border:0; padding:9px 16px; border-radius:6px; cursor:pointer; font-weight:600; }
.alert { font-size:.85rem; margin:8px 0; padding:8px 10px; border-radius:4px; display:none; }
.alert.error { display:block; background:#fee2e2; color:#991b1b; }
.alert.success { display:block; background:#d1fae5; color:#065f46; }

@media print {
  body { background:#fff; padding:0; }
  .no-print { display:none !important; }
  .sheet { box-shadow:none; width:auto; min-height:auto; padding:0; margin:0; }
  table.items input { border-bottom:0; background:transparent !important; }
  [contenteditable] { background:transparent !important; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
  <div class="picker">
    <input type="text" id="prodSearch" placeholder="🔍 Shopify商品を検索して明細に追加（商品名）" autocomplete="off">
    <div class="drop" id="prodDrop"></div>
  </div>
  <button class="add" onclick="addRow()">＋ 手入力で行追加</button>
  <button class="primary" onclick="window.print()">PDF保存 / 印刷</button>
  <button class="mail" onclick="openMailModal()">メールで送信</button>
</div>

<div class="sheet">
  <div class="head">
    <h1>見積書</h1>
    <div class="doc-no">No. <span id="estNo"><?= h($estimate_no) ?></span></div>
  </div>

  <div class="meta">
    <div class="to-block">
      <div class="to-name" contenteditable="true" spellcheck="false">御中</div>
      <div class="to-addr no-print" style="font-size:8pt;color:#9ca3af;">（宛名を入力してください）</div>
      <div class="subject"><span class="label">件名：</span><span contenteditable="true" spellcheck="false">お見積もりの件</span></div>
    </div>
    <div class="from-block">
      <div class="company-name"><?= h(COMPANY_INFO['brand']) ?></div>
      <div><?= h(COMPANY_INFO['legal']) ?></div>
      <div>〒<?= h(COMPANY_INFO['zip']) ?> <?= h(COMPANY_INFO['address1']) ?></div>
      <div style="margin-top:4px;font-weight:600;">登録番号: <?= h(COMPANY_INFO['invoice_number']) ?></div>
    </div>
  </div>

  <div class="dates">
    <div><span class="label">発行日：</span><span contenteditable="true" spellcheck="false"><?= h($issue_default) ?></span></div>
    <div><span class="label">有効期限：</span><span contenteditable="true" spellcheck="false"><?= h($valid_default) ?></span></div>
  </div>

  <div class="amount-box">
    <div class="label">お見積金額</div>
    <div class="yen">¥ <span id="grandTotal">0</span> <span style="font-size:10pt;font-weight:normal;margin-left:8px;">（税込）</span></div>
  </div>

  <p style="font-size:9.5pt;">下記の通りお見積もり申し上げます。</p>

  <h3 class="section-title">明細</h3>
  <table class="items">
    <thead>
      <tr>
        <th>品名</th>
        <th class="num" style="width:70px;">数量</th>
        <th class="num" style="width:110px;">単価（税込）</th>
        <th class="num" style="width:120px;">金額（税込）</th>
        <th class="row-del no-print"></th>
      </tr>
    </thead>
    <tbody id="itemBody"></tbody>
  </table>

  <div class="totals">
    <div class="row"><span>小計（税抜）</span><span>¥ <span id="subExcl">0</span></span></div>
    <div class="row"><span>消費税（10%）</span><span>¥ <span id="taxAmt">0</span></span></div>
    <div class="row grand"><span>お見積金額（税込）</span><span>¥ <span id="grandTotal2">0</span></span></div>
  </div>

  <h3 class="section-title">備考</h3>
  <div class="note-text" contenteditable="true" spellcheck="false">　</div>

  <div class="staff">担当：<span contenteditable="true" spellcheck="false">　　　　　　</span></div>

  <div class="footer-note">
    ※ 本見積書の有効期限は発行日より30日間です。<br>
    ※ ご不明な点がございましたら、上記担当までお問い合わせください。
  </div>
</div>

<div class="modal-bg no-print" id="mailModal" onclick="if(event.target===this) closeMailModal()">
  <div class="modal">
    <h2>📧 見積書をメールで送信</h2>
    <p style="font-size:.85rem;color:#6b7280;margin:0 0 12px;">画面の内容で見積書PDFを生成・添付して送信します。</p>
    <label>送信先メールアドレス <span style="color:#dc2626;">*</span>
      <input type="email" id="m_recipient" required placeholder="client@example.com">
    </label>
    <label>CC（任意、カンマ区切り）
      <input type="text" id="m_cc" placeholder="staff@example.com">
    </label>
    <label>メール件名
      <input type="text" id="m_subject" value="<?= h('【' . COMPANY_INFO['brand'] . '】お見積書送付のご案内（' . $estimate_no . '）') ?>">
    </label>
    <label>メール本文
      <textarea id="m_body" placeholder="空欄なら自動テンプレで送信されます"></textarea>
      <span class="hint">空欄なら自動テンプレ（宛名・見積番号・金額・有効期限・担当を含む挨拶文）で送信</span>
    </label>
    <div id="m_alert" class="alert"></div>
    <div class="modal-actions">
      <button class="btn-cancel" onclick="closeMailModal()">キャンセル</button>
      <button class="btn-send" id="m_send_btn" onclick="sendMail()">送信</button>
    </div>
  </div>
</div>

<script>
let PRODUCTS = [];

fetch('/estimate/products', { credentials:'same-origin' })
  .then(r => r.json())
  .then(d => {
    if (!d.ok) return;
    (d.products || []).forEach(p => {
      (p.variants || []).forEach(v => {
        const vt = (v.title && v.title !== 'Default Title') ? '（' + v.title + '）' : '';
        PRODUCTS.push({ label: p.title + vt, price: Math.round(parseFloat(v.price || '0')) });
      });
    });
  })
  .catch(() => {});

const searchEl = document.getElementById('prodSearch');
const dropEl = document.getElementById('prodDrop');
searchEl.addEventListener('input', () => {
  const q = searchEl.value.trim();
  if (!q) { dropEl.classList.remove('show'); return; }
  const ql = q.toLowerCase();
  const hits = PRODUCTS.filter(p => p.label.toLowerCase().includes(ql)).slice(0, 40);
  dropEl._hits = hits;
  while (dropEl.firstChild) dropEl.removeChild(dropEl.firstChild);
  if (hits.length === 0) {
    const d = document.createElement('div'); d.className = 'opt'; d.style.color = '#999'; d.textContent = '該当なし';
    dropEl.appendChild(d);
  } else {
    hits.forEach((p, i) => {
      const d = document.createElement('div'); d.className = 'opt'; d.dataset.i = i;
      d.appendChild(document.createTextNode(p.label));
      const span = document.createElement('span'); span.className = 'p'; span.textContent = '¥' + p.price.toLocaleString();
      d.appendChild(span);
      dropEl.appendChild(d);
    });
  }
  dropEl.classList.add('show');
});
dropEl.addEventListener('click', e => {
  const opt = e.target.closest('.opt'); if (!opt || opt.dataset.i === undefined) return;
  const p = dropEl._hits[parseInt(opt.dataset.i, 10)];
  addRow(p.label, 1, p.price);
  searchEl.value = ''; dropEl.classList.remove('show');
});
document.addEventListener('click', e => { if (!e.target.closest('.picker')) dropEl.classList.remove('show'); });

function makeInput(cls, type, value) {
  const inp = document.createElement('input');
  inp.type = type; inp.className = cls; inp.value = value;
  if (type === 'number') { inp.min = '0'; inp.step = '1'; }
  inp.addEventListener('input', recalc);
  return inp;
}

function addRow(title, qty, unit) {
  title = title || ''; qty = (qty === undefined ? 1 : qty); unit = (unit === undefined ? 0 : unit);
  const tr = document.createElement('tr');
  const tdTitle = document.createElement('td'); tdTitle.appendChild(makeInput('i-title', 'text', title));
  const tdQty = document.createElement('td'); tdQty.className = 'num'; tdQty.appendChild(makeInput('num i-qty', 'number', qty));
  const tdUnit = document.createElement('td'); tdUnit.className = 'num'; tdUnit.appendChild(makeInput('num i-unit', 'number', unit));
  const tdAmt = document.createElement('td'); tdAmt.className = 'num i-amt'; tdAmt.textContent = '¥ 0';
  const tdDel = document.createElement('td'); tdDel.className = 'row-del no-print';
  const del = document.createElement('button'); del.type = 'button'; del.className = 'btn-del'; del.textContent = '×'; del.title = '削除';
  del.addEventListener('click', () => { tr.remove(); recalc(); });
  tdDel.appendChild(del);
  tr.appendChild(tdTitle); tr.appendChild(tdQty); tr.appendChild(tdUnit); tr.appendChild(tdAmt); tr.appendChild(tdDel);
  document.getElementById('itemBody').appendChild(tr);
  recalc();
}

function recalc() {
  let inclTotal = 0;
  document.querySelectorAll('#itemBody tr').forEach(tr => {
    const qty = parseFloat(tr.querySelector('.i-qty') ? tr.querySelector('.i-qty').value : '0') || 0;
    const unit = parseFloat(tr.querySelector('.i-unit') ? tr.querySelector('.i-unit').value : '0') || 0;
    const amt = Math.round(qty * unit);
    tr.querySelector('.i-amt').textContent = '¥ ' + amt.toLocaleString();
    inclTotal += amt;
  });
  const excl = Math.round(inclTotal / 1.1);
  const tax = inclTotal - excl;
  document.getElementById('subExcl').textContent = excl.toLocaleString();
  document.getElementById('taxAmt').textContent = tax.toLocaleString();
  document.getElementById('grandTotal').textContent = inclTotal.toLocaleString();
  document.getElementById('grandTotal2').textContent = inclTotal.toLocaleString();
}

function collectItems() {
  const items = [];
  document.querySelectorAll('#itemBody tr').forEach(tr => {
    const title = tr.querySelector('.i-title').value.trim();
    const qty = parseFloat(tr.querySelector('.i-qty').value || '0') || 0;
    const unit = parseFloat(tr.querySelector('.i-unit').value || '0') || 0;
    if (title === '' && qty === 0 && unit === 0) return;
    items.push({ title: title, qty: qty, unit: unit });
  });
  return items;
}

function $txt(sel) { const el = document.querySelector(sel); return (el ? el.innerText : '').trim(); }
function openMailModal() {
  const m = document.getElementById('m_alert'); m.className = 'alert'; m.textContent = '';
  const b = document.getElementById('m_send_btn'); b.disabled = false; b.textContent = '送信';
  document.getElementById('mailModal').classList.add('shown');
  setTimeout(() => document.getElementById('m_recipient').focus(), 50);
}
function closeMailModal() { document.getElementById('mailModal').classList.remove('shown'); }

async function sendMail() {
  const recipient = document.getElementById('m_recipient').value.trim();
  if (!recipient) { showAlert('送信先メールアドレスを入力してください', 'error'); return; }
  const items = collectItems();
  if (items.length === 0) { showAlert('明細が空です。商品を追加してください', 'error'); return; }
  const btn = document.getElementById('m_send_btn'); btn.disabled = true; btn.textContent = '送信中...';

  const fd = new FormData();
  fd.set('estimate_no', $txt('#estNo'));
  fd.set('to_name', $txt('.to-name'));
  fd.set('subject', $txt('.subject span[contenteditable]'));
  fd.set('issue_date', document.querySelectorAll('.dates span[contenteditable]')[0] ? document.querySelectorAll('.dates span[contenteditable]')[0].innerText.trim() : '');
  fd.set('valid_until', document.querySelectorAll('.dates span[contenteditable]')[1] ? document.querySelectorAll('.dates span[contenteditable]')[1].innerText.trim() : '');
  fd.set('note', $txt('.note-text'));
  fd.set('staff', $txt('.staff span[contenteditable]'));
  fd.set('items', JSON.stringify(items));
  fd.set('recipient', recipient);
  fd.set('cc', document.getElementById('m_cc').value.trim());
  fd.set('mail_subject', document.getElementById('m_subject').value.trim());
  fd.set('mail_body', document.getElementById('m_body').value);

  try {
    const res = await fetch('/estimate/send', { method:'POST', body:fd, credentials:'same-origin' });
    const data = await res.json();
    if (data.ok) { showAlert('✅ ' + recipient + ' に送信しました', 'success'); btn.textContent = '送信済'; }
    else { showAlert('❌ ' + (data.error || '不明なエラー'), 'error'); btn.disabled = false; btn.textContent = '送信'; }
  } catch (e) { showAlert('❌ 通信エラー: ' + e.message, 'error'); btn.disabled = false; btn.textContent = '送信'; }
}
function showAlert(t, type) { const a = document.getElementById('m_alert'); a.className = 'alert ' + (type || ''); a.textContent = t; }

addRow();
</script>
</body>
</html>
