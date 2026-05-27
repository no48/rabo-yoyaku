<?php
/**
 * 請求書ツール 設定（環境変数から読込）
 *
 * 既存共通: SHOPIFY_SHOP / SHOPIFY_ACCESS_TOKEN / AUTH_USER / AUTH_PASS
 * Phase 2 追加: SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS / MAIL_FROM_NAME
 */

define('SHOPIFY_SHOP',          getenv('SHOPIFY_SHOP')          ?: '');
define('SHOPIFY_ACCESS_TOKEN',  getenv('SHOPIFY_ACCESS_TOKEN')  ?: '');
define('AUTH_USER',             getenv('AUTH_USER')             ?: '');
define('AUTH_PASS',             getenv('AUTH_PASS')             ?: '');

// 必須環境変数チェック
foreach ([
    'SHOPIFY_SHOP'         => SHOPIFY_SHOP,
    'SHOPIFY_ACCESS_TOKEN' => SHOPIFY_ACCESS_TOKEN,
    'AUTH_USER'            => AUTH_USER,
    'AUTH_PASS'            => AUTH_PASS,
] as $key => $value) {
    if ($value === '') {
        http_response_code(500);
        echo "環境変数 $key が未設定です。Render ダッシュボードで設定してください。";
        exit;
    }
}

// 自社情報（領収書と統一）
const COMPANY_INFO = [
    'brand'           => 'ロープアクセスラボ',
    'legal'           => '4U合同会社',
    'zip'             => '352-0005',
    'address1'        => '埼玉県新座市中野2-3-9',
    'tel'             => '',
    'email'           => '',
    'invoice_number'  => 'T3030003019429',
];

// 振込先（本番値は今後ユーザー提供時に差し替え。空欄/プレースホルダーで開始）
const BANK_INFO = [
    'bank'    => '【銀行名】',
    'branch'  => '【支店名】',
    'type'    => '普通',
    'number'  => '【口座番号】',
    'name'    => '【口座名義】',
];

// Basic 認証
function require_basic_auth() {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user !== AUTH_USER || $pass !== AUTH_PASS) {
        header('WWW-Authenticate: Basic realm="Invoice"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'ログインが必要です';
        exit;
    }
}
