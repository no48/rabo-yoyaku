<?php
/**
 * 領収書発行 - 入力フォーム（お客様向け、Basic認証なし）
 *
 * URL: /receipt.php または /receipt（.htaccessでリダイレクト想定）
 * お客様が注文番号とメールアドレスを入力 → /receipt_view.php に遷移して領収書表示
 */

require_once 'config.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$order = $_GET['order'] ?? '';
$email = $_GET['email'] ?? '';
$error = $_GET['error'] ?? '';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>領収書発行 - ロープアクセスラボ</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: -apple-system, "Hiragino Sans", "Yu Gothic", sans-serif; max-width: 560px; margin: 40px auto; padding: 0 24px; line-height: 1.6; color: #222; }
h1 { font-size: 1.4rem; margin-bottom: 8px; }
.lead { color: #555; font-size: 0.95rem; margin-bottom: 24px; }
form { display: grid; gap: 14px; padding: 24px; border: 1px solid #ddd; border-radius: 8px; background: #fafafa; }
label { display: grid; gap: 4px; font-size: 0.9rem; font-weight: 600; }
.field-hint { font-weight: normal; color: #6b7280; font-size: 0.82rem; margin-top: 2px; line-height: 1.5; }
.field-hint .ex { color: #4b5563; }
input { padding: 10px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; }
input:focus { outline: 2px solid #2563eb; outline-offset: -1px; border-color: #2563eb; }
button { padding: 12px 16px; background: #2563eb; color: white; border: 0; border-radius: 4px; cursor: pointer; font-size: 1rem; font-weight: 600; }
button:hover { background: #1d4ed8; }
.hint { color: #666; font-size: 0.85rem; margin-top: 24px; padding: 12px 16px; background: #f3f4f6; border-radius: 6px; line-height: 1.7; }
.error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; font-size: 0.95rem; }
.brand { font-size: 0.85rem; color: #6b7280; margin-bottom: 4px; }
</style>
</head>
<body>
<div class="brand">ロープアクセスラボ（4U合同会社）</div>
<h1>領収書の発行</h1>
<p class="lead">ご注文時の <strong>注文番号</strong> と <strong>メールアドレス</strong> を入力してください。確認のうえ領収書を表示します。</p>
<?php if ($error): ?>
<div class="error"><?= h($error) ?></div>
<?php endif; ?>
<form method="get" action="/receipt/view">
    <label>◆ 注文番号
        <input type="text" name="order" required placeholder="1056" value="<?= h($order) ?>" autocomplete="off">
        <div class="field-hint">注文確認メール記載の数字のみ入力してください。<br>
        <span class="ex">例：<strong>#1056</strong> → <strong>1056</strong></span></div>
    </label>
    <label>◆ ご注文時に使用したメールアドレス
        <input type="email" name="email" required placeholder="example@example.com" value="<?= h($email) ?>">
        <div class="field-hint">※ 異なるメールアドレスでは表示できません。</div>
    </label>
    <label>◆ 宛名（領収書のお名前）
        <input type="text" name="to" placeholder="例: 株式会社○○ 御中">
        <div class="field-hint">
        <span class="ex">例：<br>
        　株式会社○○ 御中<br>
        　山田太郎 様</span><br>
        ※ 敬称（様 / 御中）もご自身でご入力ください。<br>
        ※ 空欄の場合はご注文者名で発行されます。
        </div>
    </label>
    <button type="submit">領収書を表示</button>
</form>
<div class="hint">
表示された画面で <strong>「PDFとして保存 / 印刷」ボタン</strong> または <kbd>Cmd</kbd>+<kbd>P</kbd>（Windowsは <kbd>Ctrl</kbd>+<kbd>P</kbd>）でPDFとして保存できます。<br>
※ 但し書きは注文内容に応じて自動で「ご注文商品代」または「受講料」が入ります。変更したい場合は表示後の画面で直接クリックして編集できます。<br>
※ 注文番号とメールアドレスが一致しない場合、領収書は表示されません。<br>
※ 領収書は何度でも発行できます。二重計上を避けるためお客様ご自身でご注意ください。
</div>
</body>
</html>
