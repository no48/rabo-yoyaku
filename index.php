<?php
/**
 * Shopify イベント参加者エクスポートツール（PHP版）
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

/**
 * Shopify APIを呼び出す
 */
function shopify_api($endpoint, $params = []) {
    $url = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/' . $endpoint;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    curl_close($ch);

    // 次のページのリンクを取得
    $next_link = null;
    if (preg_match('/<([^>]+)>;\s*rel="next"/', $header, $matches)) {
        $next_link = $matches[1];
    }

    return [
        'data' => json_decode($body, true),
        'next_link' => $next_link
    ];
}

/**
 * 全注文を取得
 */
function fetch_all_orders() {
    $all_orders = [];
    $url = 'orders.json';
    $params = ['limit' => 250, 'status' => 'any'];

    while (true) {
        $result = shopify_api($url, $params);
        $orders = $result['data']['orders'] ?? [];
        $all_orders = array_merge($all_orders, $orders);

        if (empty($result['next_link'])) {
            break;
        }

        // 次のページを取得
        $parsed = parse_url($result['next_link']);
        parse_str($parsed['query'] ?? '', $params);
        $url = 'orders.json';
    }

    return $all_orders;
}

/**
 * 参加者情報を含む注文のみフィルタリング（キャンセル除外）
 */
function filter_event_orders($orders) {
    return array_filter($orders, function($order) {
        // キャンセルされた注文は除外
        if (!empty($order['cancelled_at'])) {
            return false;
        }

        foreach ($order['line_items'] as $item) {
            if (!empty($item['properties'])) {
                foreach ($item['properties'] as $prop) {
                    if (strpos($prop['name'], '参加者') !== false &&
                        strpos($prop['name'], 'お名前') !== false) {
                        return true;
                    }
                }
            }
        }
        return false;
    });
}

/**
 * Line itemから参加者情報を抽出
 */
function extract_participants($line_item) {
    $participants = [];
    $properties = $line_item['properties'] ?? [];

    // 参加者番号を特定
    $numbers = [];
    foreach ($properties as $prop) {
        if (preg_match('/参加者(\d+)_/', $prop['name'], $matches)) {
            $numbers[$matches[1]] = true;
        }
    }

    // 参加者1のみの共通情報を取得
    $company = '';
    $occupation = '';
    $email = '';
    $receipt = '';
    $memo = '';
    foreach ($properties as $prop) {
        if ($prop['name'] === '会社名') {
            $company = $prop['value'];
        } elseif ($prop['name'] === '職業') {
            $occupation = $prop['value'];
        } elseif ($prop['name'] === 'メールアドレス') {
            $email = $prop['value'];
        } elseif ($prop['name'] === '領収書') {
            $receipt = $prop['value'];
        } elseif ($prop['name'] === 'メモ') {
            $memo = $prop['value'];
        }
    }

    // 各参加者の情報を抽出
    foreach (array_keys($numbers) as $num) {
        $participant = [
            'number' => $num,
            'name' => '',
            'furigana' => '',
            'birthdate' => '',
            'phone' => '',
            'helmet' => '',
            'company' => '',
            'occupation' => '',
            'email' => '',
            'receipt' => '',
            'memo' => ''
        ];

        // 生年月日の年月日を個別に取得
        $birth_year = '';
        $birth_month = '';
        $birth_day = '';

        foreach ($properties as $prop) {
            if ($prop['name'] === "参加者{$num}_お名前") {
                $participant['name'] = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_フリガナ") {
                $participant['furigana'] = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_生年月日") {
                // 旧形式（単一フィールド）
                $participant['birthdate'] = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_生年月日_年") {
                $birth_year = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_生年月日_月") {
                $birth_month = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_生年月日_日") {
                $birth_day = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_電話番号") {
                $participant['phone'] = $prop['value'];
            } elseif ($prop['name'] === "参加者{$num}_ヘルメット") {
                $participant['helmet'] = $prop['value'];
            }
        }

        // 年月日が個別に設定されている場合は結合
        if ($birth_year && $birth_month && $birth_day) {
            $participant['birthdate'] = $birth_year . '年' . $birth_month . '月' . $birth_day . '日';
        }

        // 参加者1のみ会社名・職業・メールアドレス・領収書・メモを設定
        if ($num == 1) {
            $participant['company'] = $company;
            $participant['occupation'] = $occupation;
            $participant['email'] = $email;
            $participant['receipt'] = $receipt;
            $participant['memo'] = $memo;
        }

        $participants[] = $participant;
    }

    return $participants;
}

/**
 * イベント商品リストを取得
 */
function get_event_products($orders) {
    $products = [];

    foreach ($orders as $order) {
        foreach ($order['line_items'] as $item) {
            if (empty($item['product_id'])) continue;

            $has_participant = false;
            foreach ($item['properties'] ?? [] as $prop) {
                if (strpos($prop['name'], '参加者') !== false &&
                    strpos($prop['name'], 'お名前') !== false) {
                    $has_participant = true;
                    break;
                }
            }

            if ($has_participant) {
                $products[$item['product_id']] = [
                    'id' => $item['product_id'],
                    'name' => $item['name']
                ];
            }
        }
    }

    usort($products, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    return array_values($products);
}

/**
 * Excelファイル（実際はCSV）をエクスポート
 */
function export_excel($orders, $product_id = null) {
    $rows = [];

    foreach ($orders as $order) {
        $order_number = $order['name'];
        $order_date = date('Y-m-d', strtotime($order['created_at']));
        $customer_email = $order['contact_email'] ?? $order['email'] ?? '';

        foreach ($order['line_items'] as $item) {
            if ($product_id && $item['product_id'] != $product_id) {
                continue;
            }

            $participants = extract_participants($item);

            foreach ($participants as $p) {
                $rows[] = [
                    $order_number,
                    $order_date,
                    $customer_email,
                    $item['name'],
                    $p['number'],
                    $p['name'],
                    $p['furigana'],
                    $p['company'],
                    $p['occupation'],
                    $p['email'],
                    $p['birthdate'],
                    $p['phone'],
                    $p['helmet'],
                    $p['receipt'],
                    $p['memo']
                ];
            }
        }
    }

    return $rows;
}

// エクスポート処理
if (isset($_GET['export'])) {
    $product_id = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? intval($_GET['product_id']) : null;

    $orders = fetch_all_orders();
    $event_orders = filter_event_orders($orders);
    $rows = export_excel($event_orders, $product_id);

    if (empty($rows)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<script>alert("参加者情報が見つかりませんでした"); history.back();</script>';
        exit;
    }

    // Excel (CSV) 出力
    $filename = 'event_participants_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // BOMを出力（Excelで文字化けしないように）
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // ヘッダー行
    fputcsv($output, [
        '注文番号', '注文日', '注文者メール', '商品名',
        '参加者#', 'お名前', 'フリガナ', '会社名', '職業', '参加者メール', '生年月日', '電話番号', 'ヘルメット', '領収書', 'メモ'
    ], ',', '"', '\\');

    // データ行
    foreach ($rows as $row) {
        fputcsv($output, $row, ',', '"', '\\');
    }

    fclose($output);
    exit;
}

// イベント一覧を取得
$orders = fetch_all_orders();
$event_orders = filter_event_orders($orders);
$event_products = get_event_products($event_orders);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>イベント参加者エクスポート</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            margin-top: 0;
            color: #333;
            font-size: 24px;
        }
        .status {
            background: #e8f5e9;
            border: 1px solid #c8e6c9;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .status.warning {
            background: #fff3e0;
            border-color: #ffcc80;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            margin-bottom: 20px;
        }
        button {
            width: 100%;
            padding: 15px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 18px;
            cursor: pointer;
        }
        button:hover {
            background: #45a049;
        }
        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        .info {
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
            font-size: 14px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>イベント参加者エクスポート</h1>

        <?php if (count($event_products) > 0): ?>
        <div class="status">
            Shopify接続: OK<br>
            イベント注文数: <?= count($event_orders) ?>件<br>
            イベント商品数: <?= count($event_products) ?>件
        </div>

        <form method="GET">
            <input type="hidden" name="export" value="1">

            <label for="product_id">イベントを選択:</label>
            <select name="product_id" id="product_id">
                <option value="">すべてのイベント</option>
                <?php foreach ($event_products as $product): ?>
                <option value="<?= htmlspecialchars($product['id']) ?>">
                    <?= htmlspecialchars($product['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">エクスポート（CSV）</button>
        </form>

        <div class="info">
            CSVファイルはExcelで開けます。文字化けする場合は「UTF-8」を選択してください。
        </div>

        <?php else: ?>
        <div class="status warning">
            参加者情報を含むイベント注文が見つかりませんでした。<br>
            Shopifyでイベント商品が購入されると、ここに表示されます。
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
