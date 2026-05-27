<?php
/**
 * Shopify API ラッパー（請求書用）
 * 領収書側の fetch_order_by_name() と同等。注文取得＋返金額集計。
 */

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
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $http >= 400) {
        error_log("[invoice] Shopify error http=$http err=$err");
        return null;
    }
    $data = json_decode($body, true);
    return $data['orders'][0] ?? null;
}

function calc_refund_total($order) {
    $total = 0.0;
    foreach ($order['refunds'] ?? [] as $r) {
        foreach ($r['transactions'] ?? [] as $t) {
            if (($t['kind'] ?? '') === 'refund' && ($t['status'] ?? '') === 'success') {
                $total += (float)($t['amount'] ?? 0);
            }
        }
    }
    return $total;
}
