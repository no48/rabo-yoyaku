<?php
/**
 * 設定ファイル（環境変数から読み込み）
 *
 * Render ダッシュボード > Environment で以下を登録：
 *  - SHOPIFY_SHOP            （例: xdanqs-41.myshopify.com）
 *  - SHOPIFY_ACCESS_TOKEN    （Shopify Admin API token）
 *  - AUTH_USER               （Basic認証ユーザー名）
 *  - AUTH_PASS               （Basic認証パスワード）
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

// 共通: Basic 認証チェック
function require_basic_auth() {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';
    if ($user !== AUTH_USER || $pass !== AUTH_PASS) {
        header('WWW-Authenticate: Basic realm="Sales Report"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'ログインが必要です';
        exit;
    }
}
