<?php
/**
 * デバッグ用 - 注文データの構造を確認
 */

require_once 'config.php';

// Basic認証
$auth_user = $_SERVER['PHP_AUTH_USER'] ?? '';
$auth_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

if ($auth_user !== AUTH_USER || $auth_pass !== AUTH_PASS) {
    header('WWW-Authenticate: Basic realm="Event Exporter"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'ログインが必要です';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo '<h1>APIスコープ確認</h1>';
echo '<pre>';

// スコープを確認
$scope_url = 'https://' . SHOPIFY_SHOP . '/admin/oauth/access_scopes.json';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $scope_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
    'Content-Type: application/json'
]);
$scope_response = curl_exec($ch);
curl_close($ch);

$scope_data = json_decode($scope_response, true);
echo "=== 現在のアクセススコープ ===\n";
if (!empty($scope_data['access_scopes'])) {
    foreach ($scope_data['access_scopes'] as $scope) {
        echo "- " . $scope['handle'] . "\n";
    }
} else {
    echo "スコープ取得失敗\n";
    print_r($scope_data);
}
echo "\n";

// 1件だけ注文を取得
$url = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/orders.json?limit=1&status=any';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

header('Content-Type: text/html; charset=utf-8');
echo '<h1>注文データ構造（1件目）</h1>';
echo '<pre>';

if (!empty($data['orders'][0])) {
    $order = $data['orders'][0];

    echo "=== メール関連フィールド ===\n";
    echo "email: " . ($order['email'] ?? '(なし)') . "\n";
    echo "contact_email: " . ($order['contact_email'] ?? '(なし)') . "\n";

    echo "\n=== customer オブジェクト ===\n";
    if (!empty($order['customer'])) {
        echo "customer.email: " . ($order['customer']['email'] ?? '(なし)') . "\n";
        echo "customer.id: " . ($order['customer']['id'] ?? '(なし)') . "\n";

        // 顧客APIから詳細を取得
        $customer_id = $order['customer']['id'];
        $customer_url = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/customers/' . $customer_id . '.json';

        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $customer_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, [
            'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
            'Content-Type: application/json'
        ]);
        $customer_response = curl_exec($ch2);
        curl_close($ch2);

        $customer_data = json_decode($customer_response, true);

        echo "\n=== 顧客API から取得 ===\n";
        if (!empty($customer_data['customer'])) {
            echo "customer.email: " . ($customer_data['customer']['email'] ?? '(なし)') . "\n";
            echo "customer.phone: " . ($customer_data['customer']['phone'] ?? '(なし)') . "\n";
            echo "customer.first_name: " . ($customer_data['customer']['first_name'] ?? '(なし)') . "\n";
            echo "customer.last_name: " . ($customer_data['customer']['last_name'] ?? '(なし)') . "\n";
        } else {
            echo "顧客情報取得失敗\n";
            print_r($customer_data);
        }
    } else {
        echo "(customerオブジェクトなし)\n";
    }

    echo "\n=== 注文全体のキー一覧 ===\n";
    print_r(array_keys($order));

} else {
    echo "注文が見つかりません\n";
    print_r($data);
}

echo '</pre>';
