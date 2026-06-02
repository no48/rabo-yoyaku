<?php
/**
 * 請求書メール送信エンドポイント (POST)
 *
 * 受け取り (フォームPOST):
 *   order:        Shopify 注文番号
 *   invoice_no:   書類番号 (画面上で確定したもの)
 *   to_name:      宛名
 *   subject:      請求書の件名 (本文ではなくメール件名はサーバー側で組む)
 *   pay_due:      支払期限 (日本語表記)
 *   issue_date:   発行日 (日本語表記)
 *   total_real:   実支払額 (税込)
 *   note:         備考
 *   staff:        担当者名
 *   recipient:    送信先メアド (必須)
 *   cc:           CC (任意、カンマ区切り)
 *   mail_subject: メール件名 (任意、空ならデフォルト)
 *   mail_body:    メール本文 (任意、空ならテンプレート)
 *
 * 振る舞い:
 *   - Shopify 注文を再取得して請求書HTMLを組み立てる
 *   - dompdf で PDF 生成
 *   - PHPMailer + Gmail SMTP で送信 (PDF 添付)
 *   - 結果を JSON で返す
 */

require_once 'config.php';
require_basic_auth();
require_once 'lib_shopify.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\SMTP;

header('Content-Type: application/json; charset=UTF-8');

function fail($http, $msg) {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'POST のみ受け付けます');
}

// SMTP 設定チェック
$smtp_user = getenv('SMTP_USER');
$smtp_pass = getenv('SMTP_PASS');
if (!$smtp_user || !$smtp_pass) {
    fail(500, 'メール送信設定 (SMTP_USER / SMTP_PASS) が未登録です。Render環境変数で設定してください。');
}
$smtp_host    = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtp_port    = (int)(getenv('SMTP_PORT') ?: 587);
$from_name    = getenv('MAIL_FROM_NAME') ?: 'ロープアクセスラボ';

// 入力
$order_num = trim(ltrim((string)($_POST['order'] ?? ''), '#'));
$recipient = trim((string)($_POST['recipient'] ?? ''));
if ($order_num === '' || $recipient === '') {
    fail(400, '注文番号と送信先メールアドレスは必須です');
}
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fail(400, '送信先メールアドレスの形式が正しくありません: ' . $recipient);
}

$invoice_no   = trim((string)($_POST['invoice_no'] ?? ''));
$to_name      = trim((string)($_POST['to_name'] ?? ''));
$subject      = trim((string)($_POST['subject'] ?? 'ご請求の件'));
$pay_due      = trim((string)($_POST['pay_due'] ?? ''));
$issue_date   = trim((string)($_POST['issue_date'] ?? date('Y年n月j日')));
$note         = trim((string)($_POST['note'] ?? ''));
$staff        = trim((string)($_POST['staff'] ?? ''));
$mail_subject = trim((string)($_POST['mail_subject'] ?? ''));
$mail_body    = trim((string)($_POST['mail_body'] ?? ''));
$cc_raw       = trim((string)($_POST['cc'] ?? ''));
$cc_list = [];
if ($cc_raw !== '') {
    foreach (preg_split('/[,;\s]+/', $cc_raw) as $cc) {
        $cc = trim($cc);
        if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $cc_list[] = $cc;
        }
    }
}

// 注文取得
$order = fetch_order_by_name($order_num);
if (!$order) fail(404, "注文 #{$order_num} が見つかりませんでした");
if (!empty($order['cancelled_at'])) fail(400, 'キャンセル済み注文には請求書を発行できません');

// 金額再計算 (画面で渡された値より、サーバー側で再算出した方が安全)
$total_refund = calc_refund_total($order);
$total = (float)($order['total_price'] ?? 0);
$real_paid = $total - $total_refund;

// 添付PDF生成 (HTMLを組み立てて dompdf へ)
$pdf_html = build_invoice_html_for_pdf($order, [
    'invoice_no' => $invoice_no,
    'to_name'    => $to_name,
    'subject'    => $subject,
    'issue_date' => $issue_date,
    'pay_due'    => $pay_due,
    'note'       => $note,
    'staff'      => $staff,
    'real_paid'  => $real_paid,
    'total'      => $total,
    'refund'     => $total_refund,
]);

$dompdf_options = new Options();
$dompdf_options->set('defaultFont', 'Noto Sans CJK JP');
$dompdf_options->set('isRemoteEnabled', false);
$dompdf_options->set('chroot', '/var/www/html');
$dompdf = new Dompdf($dompdf_options);
$dompdf->loadHtml($pdf_html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdf_binary = $dompdf->output();
$pdf_name = ($invoice_no !== '' ? $invoice_no : 'invoice') . '.pdf';

// メール送信
$mailer = new PHPMailer(true);
try {
    $mailer->isSMTP();
    $mailer->Host       = $smtp_host;
    $mailer->Port       = $smtp_port;
    $mailer->SMTPAuth   = true;
    $mailer->Username   = $smtp_user;
    $mailer->Password   = $smtp_pass;
    $mailer->SMTPSecure = ($smtp_port === 465)
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Timeout    = 15; // 接続が滞ったら15秒で打ち切り（送信中フリーズ防止）
    $mailer->CharSet    = 'UTF-8';
    $mailer->Encoding   = 'base64';

    $mailer->setFrom($smtp_user, $from_name);
    $mailer->addAddress($recipient);
    foreach ($cc_list as $cc) $mailer->addCC($cc);
    $mailer->addReplyTo($smtp_user, $from_name);

    $mailer->Subject = $mail_subject !== ''
        ? $mail_subject
        : sprintf('【%s】ご請求書送付のご案内（%s）', $from_name, $invoice_no);

    $mailer->Body = $mail_body !== '' ? $mail_body : default_mail_body($to_name, $invoice_no, $real_paid, $pay_due, $staff);
    $mailer->isHTML(false);

    $mailer->addStringAttachment($pdf_binary, $pdf_name, 'base64', 'application/pdf');

    $mailer->send();
    error_log("[invoice/send] sent {$invoice_no} to {$recipient}");
    echo json_encode(['ok' => true, 'invoice_no' => $invoice_no, 'recipient' => $recipient], JSON_UNESCAPED_UNICODE);
} catch (PHPMailerException $e) {
    error_log("[invoice/send] PHPMailer error: " . $e->getMessage());
    fail(500, '送信に失敗しました: ' . $mailer->ErrorInfo);
} catch (Throwable $e) {
    error_log("[invoice/send] exception: " . $e->getMessage());
    fail(500, '送信中にエラーが発生しました: ' . $e->getMessage());
}


// ============ 補助関数 ============

function default_mail_body($to_name, $invoice_no, $real_paid, $pay_due, $staff) {
    $amount = number_format((int)round((float)$real_paid));
    $bank = sprintf('%s %s　%s %s', BANK_INFO['bank'], BANK_INFO['branch'], BANK_INFO['type'], BANK_INFO['number']);
    $lines = [];
    $lines[] = ($to_name !== '' ? $to_name : 'ご担当者') . ' 様';
    $lines[] = '';
    $lines[] = 'いつもお世話になっております。';
    $lines[] = 'ロープアクセスラボ（4U合同会社）でございます。';
    $lines[] = '';
    $lines[] = '下記の通りご請求書を添付（PDF）にてお送りいたします。';
    $lines[] = 'ご査収のほど、よろしくお願い申し上げます。';
    $lines[] = '';
    $lines[] = '──────────────';
    $lines[] = '■ ご請求内容';
    $lines[] = '　請求書番号：' . $invoice_no;
    $lines[] = '　ご請求金額：¥' . $amount . '（税込）';
    if ($pay_due !== '') $lines[] = '　お支払期限：' . $pay_due;
    $lines[] = '';
    $lines[] = '■ お振込先';
    $lines[] = '　' . $bank;
    $lines[] = '　口座名義：' . BANK_INFO['name'];
    $lines[] = '';
    $lines[] = '※ ご注文日より7日以内にお振込みください。';
    $lines[] = '※ お振込手数料はお客様のご負担となります。';
    $lines[] = '※ ご入金確認後、商品の発送準備を開始いたします。';
    $lines[] = '──────────────';
    $lines[] = '';
    $lines[] = 'ご不明な点がございましたらお気軽にお問い合わせください。';
    $lines[] = '今後ともよろしくお願いいたします。';
    $lines[] = '';
    if ($staff !== '') {
        $lines[] = '担当：' . $staff;
    }
    $lines[] = 'ロープアクセスラボ（4U合同会社）';
    $lines[] = '〒352-0005 埼玉県新座市中野2-3-9';
    $lines[] = '登録番号：T3030003019429';
    return implode("\n", $lines);
}

function build_invoice_html_for_pdf($order, $ctx) {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $yen = fn($n) => number_format((int)round((float)$n));

    $addr = $order['billing_address'] ?? ($order['shipping_address'] ?? []);
    $addr_line = trim(($addr['address1'] ?? '') . ' ' . ($addr['address2'] ?? ''));
    $zip = $addr['zip'] ?? '';

    $items_html = '';
    foreach ($order['line_items'] ?? [] as $li) {
        $qty = (int)($li['quantity'] ?? 0);
        $unit = (float)($li['price'] ?? 0);
        $subtotal = $unit * $qty;
        $items_html .= '<tr>';
        $items_html .= '<td>' . $h($li['title'] ?? '');
        if (!empty($li['variant_title'])) $items_html .= '<br><small>' . $h($li['variant_title']) . '</small>';
        $items_html .= '</td>';
        $items_html .= '<td class="num">' . $qty . '</td>';
        $items_html .= '<td class="num">¥ ' . $yen($unit) . '</td>';
        $items_html .= '<td class="num">¥ ' . $yen($subtotal) . '</td>';
        $items_html .= '</tr>';
    }
    // 送料を明細に1行で出す（合計が合うように）
    $shipping_total = 0.0;
    foreach ($order['shipping_lines'] ?? [] as $sl) {
        $shipping_total += (float)($sl['price'] ?? 0);
    }
    if ($shipping_total > 0) {
        $items_html .= '<tr><td>送料</td><td class="num">1</td><td class="num">¥ ' . $yen($shipping_total)
            . '</td><td class="num">¥ ' . $yen($shipping_total) . '</td></tr>';
    }

    $tax_excluded = (float)$order['total_price'] - (float)$order['total_tax'];
    $tax_lines_html = '';
    foreach ($order['tax_lines'] ?? [] as $t) {
        $rate = (int)round((float)($t['rate'] ?? 0) * 100);
        $tax_lines_html .= '<div class="row"><span>消費税（' . $rate . '%）</span><span>¥ ' . $yen((float)($t['price'] ?? 0)) . '</span></div>';
    }

    $refund_row = '';
    if ((float)$ctx['refund'] > 0) {
        $refund_row = '<div class="row" style="color:#c00;"><span>返金</span><span>- ¥ ' . $yen($ctx['refund']) . '</span></div>';
    }

    $bank_lines = $h(BANK_INFO['bank']) . ' ' . $h(BANK_INFO['branch']) . '　' . $h(BANK_INFO['type']) . '　' . $h(BANK_INFO['number']);

    $stamp_html = '';
    $stamp_path = realpath(__DIR__ . '/../assets/stamp.png');
    if ($stamp_path && file_exists($stamp_path)) {
        $stamp_html = '<img src="' . $stamp_path . '" style="width:60pt; height:60pt; position:absolute; top:6pt; right:0;">';
    }

    // heredoc 内では関数呼び出しが展開できないので、事前にエスケープ済み変数を作る
    $e_invoice_no  = $h($ctx['invoice_no']);
    $e_to_name     = $h($ctx['to_name']);
    $e_zip         = $h($zip);
    $e_addr_line   = $h($addr_line);
    $e_subject     = $h($ctx['subject']);
    $e_issue_date  = $h($ctx['issue_date']);
    $e_pay_due     = $h($ctx['pay_due']);
    $e_real_paid   = $yen($ctx['real_paid']);
    $e_tax_excluded= $yen($tax_excluded);
    $e_total       = $yen($ctx['total']);
    $e_note        = $h($ctx['note']);
    $e_staff       = $h($ctx['staff']);
    $e_bank_name   = $h(BANK_INFO['name']);
    $e_order_name  = $h($order['name']);
    $e_brand       = $h(COMPANY_INFO['brand']);
    $e_legal       = $h(COMPANY_INFO['legal']);
    $e_cz          = $h(COMPANY_INFO['zip']);
    $e_caddr       = $h(COMPANY_INFO['address1']);
    $e_cinv        = $h(COMPANY_INFO['invoice_number']);

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
* { font-family: "Noto Sans CJK JP", sans-serif; }
body { color: #111; font-size: 10pt; }
.head { text-align: center; border-bottom: 3px double #111; padding-bottom: 8pt; margin-bottom: 16pt; }
.head h1 { font-size: 22pt; letter-spacing: 1em; padding-left: 1em; margin: 0; }
.head .doc-no { font-size: 9pt; color: #555; margin-top: 4pt; }
.meta-table { width: 100%; border: 0; }
.meta-table td { vertical-align: top; }
.to-name { font-size: 14pt; border-bottom: 1px solid #444; padding-bottom: 3pt; }
.to-addr { font-size: 8pt; color: #555; margin-top: 2pt; }
.subject { margin-top: 8pt; }
.subject .label { color: #666; font-size: 8pt; }
.from-block { font-size: 8pt; line-height: 1.6; text-align: right; position: relative; padding-top: 60pt; }
.company-name { font-size: 11pt; font-weight: bold; }
.dates { font-size: 9pt; margin-bottom: 10pt; }
.dates .label { color: #666; font-size: 8pt; margin-right: 4pt; }
.amount-box { border: 2px solid #111; padding: 12pt; margin: 12pt 0; }
.amount-box .label { font-size: 10pt; }
.amount-box .yen { font-size: 18pt; font-weight: bold; }
table.items { width: 100%; border-collapse: collapse; margin: 8pt 0; font-size: 9pt; }
table.items th, table.items td { border-bottom: 1px solid #ccc; padding: 4pt 6pt; text-align: left; }
table.items th { background: #f4f4f4; }
table.items td.num { text-align: right; }
.totals { width: 240pt; margin-left: auto; font-size: 9pt; margin-top: 6pt; }
.totals .row { padding: 3pt 6pt; }
.totals .row.grand { border-top: 1px solid #111; border-bottom: 1px solid #111; font-weight: bold; font-size: 10pt; padding: 6pt; margin-top: 4pt; }
.bank-box { margin-top: 12pt; padding: 8pt 10pt; background: #fffaf0; border: 1px solid #f0c878; font-size: 8.5pt; line-height: 1.6; }
.note-text { white-space: pre-wrap; font-size: 8.5pt; line-height: 1.6; padding: 6pt 8pt; background: #fafafa; border: 1px solid #eee; margin-top: 4pt; min-height: 30pt; }
.section-title { font-size: 9pt; font-weight: bold; color: #374151; margin-top: 12pt; border-left: 3px solid #6b7280; padding-left: 6pt; }
.staff { margin-top: 8pt; font-size: 9pt; text-align: right; }
.footer-note { margin-top: 16pt; font-size: 8pt; color: #555; line-height: 1.6; border-top: 1px solid #ccc; padding-top: 6pt; }
</style>
</head><body>

<div class="head">
    <h1>請求書</h1>
    <div class="doc-no">No. {$e_invoice_no}</div>
</div>

<table class="meta-table">
<tr>
<td>
    <div class="to-name">{$e_to_name}</div>
    <div class="to-addr">〒{$e_zip} {$e_addr_line}</div>
    <div class="subject"><span class="label">件名：</span>{$e_subject}</div>
</td>
<td style="text-align:right; width: 45%;">
    <div class="from-block">
        {$stamp_html}
        <div class="company-name">{$e_brand}</div>
        <div>{$e_legal}</div>
        <div>〒{$e_cz} {$e_caddr}</div>
        <div style="margin-top:2pt; font-weight:bold;">登録番号: {$e_cinv}</div>
    </div>
</td>
</tr>
</table>

<div class="dates">
    <span class="label">発行日：</span>{$e_issue_date}
    <span class="label">お支払期限：</span>{$e_pay_due}
</div>

<div class="amount-box">
    <table style="width:100%;"><tr>
        <td class="label">ご請求金額</td>
        <td style="text-align:right;"><span class="yen">¥ {$e_real_paid}</span> <span style="font-size:8pt;">（税込）</span></td>
    </tr></table>
</div>

<p style="font-size:9pt;">下記の通りご請求申し上げます。</p>

<h3 class="section-title">明細</h3>
<table class="items">
    <thead><tr>
        <th>品名</th><th style="width:50pt;">数量</th><th style="width:60pt; text-align:right;">単価</th><th style="width:70pt; text-align:right;">金額</th>
    </tr></thead>
    <tbody>{$items_html}</tbody>
</table>

<div class="totals">
    <div class="row"><table style="width:100%"><tr><td>小計（税抜）</td><td style="text-align:right;">¥ {$e_tax_excluded}</td></tr></table></div>
    {$tax_lines_html}
    <div class="row"><table style="width:100%"><tr><td>注文時合計</td><td style="text-align:right;">¥ {$e_total}</td></tr></table></div>
    {$refund_row}
    <div class="row grand"><table style="width:100%"><tr><td>ご請求合計（税込）</td><td style="text-align:right;">¥ {$e_real_paid}</td></tr></table></div>
</div>

<div class="bank-box">
    <div><strong>お振込先</strong></div>
    <div>{$bank_lines}</div>
    <div>口座名義：{$e_bank_name}</div>
    <div style="margin-top:2pt; color:#7c5a00;">※ お振込手数料はお客様のご負担となります。</div>
    <div style="margin-top:2pt; color:#7c5a00;">※ ご注文日より7日以内にお振込みください。</div>
    <div style="margin-top:2pt; color:#7c5a00;">※ ご入金確認後、商品の発送準備を開始いたします。</div>
</div>

<h3 class="section-title">備考</h3>
<div class="note-text">{$e_note}</div>

<div class="staff">担当：{$e_staff}</div>

<div class="footer-note">
    ※ 本請求書は Shopify 注文 {$e_order_name} に基づき発行しています。
</div>

</body></html>
HTML;
}
