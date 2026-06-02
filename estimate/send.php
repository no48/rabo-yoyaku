<?php
/**
 * 見積書メール送信エンドポイント (POST)
 *
 * 受け取り (FormData):
 *   estimate_no, to_name, subject, issue_date, valid_until, note, staff,
 *   items (JSON: [{title,qty,unit}, ...]),
 *   recipient(必須), cc(任意), mail_subject(任意), mail_body(任意)
 *
 * 振る舞い: 明細から見積書HTMLを組み立て → dompdf でPDF → PHPMailer + SMTP で送信
 * 単価は税込前提。小計(税抜)=合計/1.1、消費税=合計-税抜。
 */
require_once 'config.php';
require_basic_auth();
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json; charset=UTF-8');

function fail($http, $msg) {
    http_response_code($http);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'POST のみ受け付けます');

$smtp_user = getenv('SMTP_USER');
$smtp_pass = getenv('SMTP_PASS');
if (!$smtp_user || !$smtp_pass) {
    fail(500, 'メール送信設定 (SMTP_USER / SMTP_PASS) が未登録です。Render環境変数で設定してください。');
}
$smtp_host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtp_port = (int)(getenv('SMTP_PORT') ?: 587);
$from_name = getenv('MAIL_FROM_NAME') ?: COMPANY_INFO['brand'];

$recipient = trim((string)($_POST['recipient'] ?? ''));
if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fail(400, '送信先メールアドレスが正しくありません');
}

$estimate_no  = trim((string)($_POST['estimate_no'] ?? ''));
$to_name      = trim((string)($_POST['to_name'] ?? ''));
$subject      = trim((string)($_POST['subject'] ?? 'お見積もりの件'));
$issue_date   = trim((string)($_POST['issue_date'] ?? date('Y年n月j日')));
$valid_until  = trim((string)($_POST['valid_until'] ?? ''));
$note         = trim((string)($_POST['note'] ?? ''));
$staff        = trim((string)($_POST['staff'] ?? ''));
$mail_subject = trim((string)($_POST['mail_subject'] ?? ''));
$mail_body    = trim((string)($_POST['mail_body'] ?? ''));
$cc_raw       = trim((string)($_POST['cc'] ?? ''));

$items_in = json_decode((string)($_POST['items'] ?? '[]'), true);
if (!is_array($items_in) || count($items_in) === 0) fail(400, '明細が空です');

// 集計（単価は税込前提）
$items = [];
$incl_total = 0;
foreach ($items_in as $it) {
    $title = trim((string)($it['title'] ?? ''));
    $qty   = (int)round((float)($it['qty'] ?? 0));
    $unit  = (int)round((float)($it['unit'] ?? 0));
    if ($title === '' && $qty === 0 && $unit === 0) continue;
    $amt = $qty * $unit;
    $items[] = ['title' => $title, 'qty' => $qty, 'unit' => $unit, 'amt' => $amt];
    $incl_total += $amt;
}
if (count($items) === 0) fail(400, '明細が空です');
$tax_excluded = (int)round($incl_total / 1.1);
$tax_amount   = $incl_total - $tax_excluded;

$cc_list = [];
if ($cc_raw !== '') {
    foreach (preg_split('/[,;\s]+/', $cc_raw) as $cc) {
        $cc = trim($cc);
        if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL)) $cc_list[] = $cc;
    }
}

$pdf_html = build_estimate_html([
    'estimate_no' => $estimate_no, 'to_name' => $to_name, 'subject' => $subject,
    'issue_date' => $issue_date, 'valid_until' => $valid_until, 'note' => $note, 'staff' => $staff,
    'items' => $items, 'incl_total' => $incl_total, 'tax_excluded' => $tax_excluded, 'tax_amount' => $tax_amount,
]);

$opt = new Options();
$opt->set('defaultFont', 'Noto Sans CJK JP');
$opt->set('isRemoteEnabled', false);
$opt->set('chroot', '/var/www/html');
$dompdf = new Dompdf($opt);
$dompdf->loadHtml($pdf_html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$pdf_binary = $dompdf->output();
$pdf_name = ($estimate_no !== '' ? $estimate_no : 'estimate') . '.pdf';

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
        : sprintf('【%s】お見積書送付のご案内（%s）', $from_name, $estimate_no);
    $mailer->Body = $mail_body !== ''
        ? $mail_body
        : default_estimate_body($to_name, $estimate_no, $incl_total, $valid_until, $staff);
    $mailer->isHTML(false);
    $mailer->addStringAttachment($pdf_binary, $pdf_name, 'base64', 'application/pdf');
    $mailer->send();
    error_log("[estimate/send] sent {$estimate_no} to {$recipient}");
    echo json_encode(['ok' => true, 'estimate_no' => $estimate_no, 'recipient' => $recipient], JSON_UNESCAPED_UNICODE);
} catch (PHPMailerException $e) {
    error_log("[estimate/send] PHPMailer error: " . $e->getMessage());
    fail(500, '送信に失敗しました: ' . $mailer->ErrorInfo);
} catch (Throwable $e) {
    error_log("[estimate/send] exception: " . $e->getMessage());
    fail(500, '送信中にエラーが発生しました: ' . $e->getMessage());
}


// ============ 補助関数 ============

function default_estimate_body($to_name, $estimate_no, $incl_total, $valid_until, $staff) {
    $amount = number_format((int)round((float)$incl_total));
    $lines = [];
    $lines[] = ($to_name !== '' ? $to_name : 'ご担当者') . ' 様';
    $lines[] = '';
    $lines[] = 'いつもお世話になっております。';
    $lines[] = 'ロープアクセスラボ（4U合同会社）でございます。';
    $lines[] = '';
    $lines[] = '下記の通りお見積書を添付（PDF）にてお送りいたします。';
    $lines[] = 'ご査収のほど、よろしくお願い申し上げます。';
    $lines[] = '';
    $lines[] = '──────────────';
    $lines[] = '■ お見積内容';
    $lines[] = '　見積番号：' . $estimate_no;
    $lines[] = '　お見積金額：¥' . $amount . '（税込）';
    if ($valid_until !== '') $lines[] = '　有効期限：' . $valid_until;
    $lines[] = '──────────────';
    $lines[] = '';
    $lines[] = 'ご検討のほど、よろしくお願い申し上げます。';
    $lines[] = '';
    if ($staff !== '') $lines[] = '担当：' . $staff;
    $lines[] = 'ロープアクセスラボ（4U合同会社）';
    $lines[] = '〒352-0005 埼玉県新座市中野2-3-9';
    $lines[] = '登録番号：T3030003019429';
    return implode("\n", $lines);
}

function build_estimate_html($ctx) {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $yen = fn($n) => number_format((int)round((float)$n));

    $items_html = '';
    foreach ($ctx['items'] as $it) {
        $items_html .= '<tr>'
            . '<td>' . $h($it['title']) . '</td>'
            . '<td class="num">' . (int)$it['qty'] . '</td>'
            . '<td class="num">¥ ' . $yen($it['unit']) . '</td>'
            . '<td class="num">¥ ' . $yen($it['amt']) . '</td>'
            . '</tr>';
    }

    $e_no    = $h($ctx['estimate_no']);
    $e_to    = $h($ctx['to_name']);
    $e_sub   = $h($ctx['subject']);
    $e_issue = $h($ctx['issue_date']);
    $e_valid = $h($ctx['valid_until']);
    $e_note  = $h($ctx['note']);
    $e_staff = $h($ctx['staff']);
    $e_incl  = $yen($ctx['incl_total']);
    $e_excl  = $yen($ctx['tax_excluded']);
    $e_tax   = $yen($ctx['tax_amount']);
    $e_brand = $h(COMPANY_INFO['brand']);
    $e_legal = $h(COMPANY_INFO['legal']);
    $e_cz    = $h(COMPANY_INFO['zip']);
    $e_caddr = $h(COMPANY_INFO['address1']);
    $e_cinv  = $h(COMPANY_INFO['invoice_number']);

    return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
* { font-family: "Noto Sans CJK JP", sans-serif; }
body { color:#111; font-size:10pt; }
.head { text-align:center; border-bottom:3px double #111; padding-bottom:8pt; margin-bottom:16pt; }
.head h1 { font-size:22pt; letter-spacing:1em; padding-left:1em; margin:0; }
.head .doc-no { font-size:9pt; color:#555; margin-top:4pt; }
.meta-table { width:100%; border:0; }
.meta-table td { vertical-align:top; }
.to-name { font-size:14pt; border-bottom:1px solid #444; padding-bottom:3pt; }
.subject { margin-top:8pt; }
.subject .label { color:#666; font-size:8pt; }
.from-block { font-size:8pt; line-height:1.6; text-align:right; }
.company-name { font-size:11pt; font-weight:bold; }
.dates { font-size:9pt; margin-bottom:10pt; }
.dates .label { color:#666; font-size:8pt; margin-right:4pt; }
.amount-box { border:2px solid #111; padding:12pt; margin:12pt 0; }
.amount-box .yen { font-size:18pt; font-weight:bold; }
table.items { width:100%; border-collapse:collapse; margin:8pt 0; font-size:9pt; }
table.items th, table.items td { border-bottom:1px solid #ccc; padding:4pt 6pt; text-align:left; }
table.items th { background:#f4f4f4; }
table.items td.num, table.items th.num { text-align:right; }
.totals { width:240pt; margin-left:auto; font-size:9pt; margin-top:6pt; }
.totals .row { padding:3pt 6pt; }
.totals .row.grand { border-top:1px solid #111; border-bottom:1px solid #111; font-weight:bold; font-size:10pt; padding:6pt; margin-top:4pt; }
.note-text { white-space:pre-wrap; font-size:8.5pt; line-height:1.6; padding:6pt 8pt; background:#fafafa; border:1px solid #eee; margin-top:4pt; min-height:30pt; }
.section-title { font-size:9pt; font-weight:bold; color:#374151; margin-top:12pt; border-left:3px solid #6b7280; padding-left:6pt; }
.staff { margin-top:8pt; font-size:9pt; text-align:right; }
.footer-note { margin-top:16pt; font-size:8pt; color:#555; line-height:1.6; border-top:1px solid #ccc; padding-top:6pt; }
</style>
</head><body>

<div class="head">
    <h1>見積書</h1>
    <div class="doc-no">No. {$e_no}</div>
</div>

<table class="meta-table">
<tr>
<td>
    <div class="to-name">{$e_to}</div>
    <div class="subject"><span class="label">件名：</span>{$e_sub}</div>
</td>
<td style="text-align:right; width:45%;">
    <div class="from-block">
        <div class="company-name">{$e_brand}</div>
        <div>{$e_legal}</div>
        <div>〒{$e_cz} {$e_caddr}</div>
        <div style="margin-top:2pt; font-weight:bold;">登録番号: {$e_cinv}</div>
    </div>
</td>
</tr>
</table>

<div class="dates">
    <span class="label">発行日：</span>{$e_issue}
    <span class="label">有効期限：</span>{$e_valid}
</div>

<div class="amount-box">
    <table style="width:100%;"><tr>
        <td>お見積金額</td>
        <td style="text-align:right;"><span class="yen">¥ {$e_incl}</span> <span style="font-size:8pt;">（税込）</span></td>
    </tr></table>
</div>

<p style="font-size:9pt;">下記の通りお見積もり申し上げます。</p>

<h3 class="section-title">明細</h3>
<table class="items">
    <thead><tr>
        <th>品名</th><th class="num" style="width:50pt;">数量</th><th class="num" style="width:70pt;">単価（税込）</th><th class="num" style="width:80pt;">金額（税込）</th>
    </tr></thead>
    <tbody>{$items_html}</tbody>
</table>

<div class="totals">
    <div class="row"><table style="width:100%"><tr><td>小計（税抜）</td><td style="text-align:right;">¥ {$e_excl}</td></tr></table></div>
    <div class="row"><table style="width:100%"><tr><td>消費税（10%）</td><td style="text-align:right;">¥ {$e_tax}</td></tr></table></div>
    <div class="row grand"><table style="width:100%"><tr><td>お見積金額（税込）</td><td style="text-align:right;">¥ {$e_incl}</td></tr></table></div>
</div>

<h3 class="section-title">備考</h3>
<div class="note-text">{$e_note}</div>

<div class="staff">担当：{$e_staff}</div>

<div class="footer-note">
    ※ 本見積書の有効期限は発行日より30日間です。
</div>

</body></html>
HTML;
}
