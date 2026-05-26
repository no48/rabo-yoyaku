<?php
/**
 * Shopify API ラッパー（売上集計用）
 *
 * fetch_orders_in_range($from, $to): 期間内の注文を全件取得（ページネーション対応）
 * summarize_by_month($orders): 注文配列を月別に集計
 */

/**
 * Shopify から注文を取得（ページネーション対応）
 * @param string $from ISO8601 文字列 (例: 2025-06-01T00:00:00Z)
 * @param string $to   ISO8601 文字列 (例: 2026-06-01T00:00:00Z)
 * @return array Shopify orders[]
 */
function fetch_orders_in_range($from, $to) {
    $headers = [
        'X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN,
    ];
    $url = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/orders.json?' . http_build_query([
        'limit' => 250,
        'status' => 'any',
        'created_at_min' => $from,
        'created_at_max' => $to,
        'fields' => 'id,name,created_at,email,billing_address,shipping_address,line_items,subtotal_price,total_tax,total_discounts,total_price,currency,financial_status,cancelled_at,refunds',
    ]);

    $all = [];
    $next = $url;
    while ($next) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $next,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hdr = substr($resp, 0, $header_size);
        $body = substr($resp, $header_size);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false || $http >= 400) {
            throw new RuntimeException("Shopify API error http=$http err=$err body=" . substr($body, 0, 200));
        }
        $data = json_decode($body, true);
        foreach (($data['orders'] ?? []) as $o) {
            $all[] = $o;
        }
        // Link ヘッダーから次ページを取り出す
        $next = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $hdr, $m)) {
            $next = $m[1];
        }
        usleep(300000); // 0.3秒 sleep (rate limit 配慮)
    }
    return $all;
}

/**
 * 注文配列を月別に集計
 * @return array key='YYYY-MM' => ['count','cancelled','total','tax','discount','refund','net']
 */
function summarize_by_month($orders) {
    $out = [];
    foreach ($orders as $o) {
        $month = substr($o['created_at'] ?? '', 0, 7); // 'YYYY-MM'
        if (!isset($out[$month])) {
            $out[$month] = [
                'count' => 0,
                'cancelled' => 0,
                'total' => 0,
                'tax' => 0,
                'discount' => 0,
                'refund' => 0,
                'net' => 0,
            ];
        }
        $is_cancelled = !empty($o['cancelled_at']);
        $total = (int)round((float)($o['total_price'] ?? 0));
        $tax = (int)round((float)($o['total_tax'] ?? 0));
        $disc = (int)round((float)($o['total_discounts'] ?? 0));
        $refund = 0;
        foreach (($o['refunds'] ?? []) as $r) {
            foreach (($r['transactions'] ?? []) as $t) {
                if (($t['kind'] ?? '') === 'refund' && ($t['status'] ?? '') === 'success') {
                    $refund += (int)round((float)($t['amount'] ?? 0));
                }
            }
        }
        if ($is_cancelled) {
            $out[$month]['cancelled'] += 1;
        } else {
            $out[$month]['count'] += 1;
            $out[$month]['total'] += $total;
            $out[$month]['tax'] += $tax;
            $out[$month]['discount'] += $disc;
            $out[$month]['refund'] += $refund;
            $out[$month]['net'] += ($total - $refund);
        }
    }
    krsort($out); // 新しい月が上
    return $out;
}

/**
 * 注文1件の集計サマリ（テーブル表示・CSV用）
 */
function order_row($o) {
    $addr = $o['billing_address'] ?? ($o['shipping_address'] ?? []);
    $name = trim(($addr['company'] ?? '') ?: (($addr['last_name'] ?? '') . ' ' . ($addr['first_name'] ?? '')));
    $refund = 0;
    foreach (($o['refunds'] ?? []) as $r) {
        foreach (($r['transactions'] ?? []) as $t) {
            if (($t['kind'] ?? '') === 'refund' && ($t['status'] ?? '') === 'success') {
                $refund += (int)round((float)($t['amount'] ?? 0));
            }
        }
    }
    $total = (int)round((float)($o['total_price'] ?? 0));
    return [
        'order_name' => $o['name'] ?? '',
        'created_at' => substr($o['created_at'] ?? '', 0, 10),
        'customer' => $name ?: '-',
        'email' => $o['email'] ?? '',
        'items' => count($o['line_items'] ?? []),
        'total' => $total,
        'tax' => (int)round((float)($o['total_tax'] ?? 0)),
        'refund' => $refund,
        'net' => $total - $refund,
        'financial_status' => $o['financial_status'] ?? '',
        'cancelled' => !empty($o['cancelled_at']) ? 'yes' : '',
    ];
}

function status_label($s) {
    return [
        'paid' => '支払済',
        'pending' => '保留',
        'authorized' => '与信のみ',
        'partially_paid' => '一部入金',
        'partially_refunded' => '一部返金',
        'refunded' => '返金済',
        'voided' => '無効',
    ][$s] ?? $s;
}
