<?php
/**
 * Shopify API ラッパー（見積書用・商品取得のみ。read_products スコープ使用）
 */
function fetch_all_products(): array {
    $next = 'https://' . SHOPIFY_SHOP . '/admin/api/2024-01/products.json?'
        . http_build_query(['limit' => 250, 'fields' => 'id,title,variants', 'status' => 'active']);
    $out = [];
    $guard = 0;
    while ($next && $guard < 20) {
        $guard++;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $next,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['X-Shopify-Access-Token: ' . SHOPIFY_ACCESS_TOKEN],
            CURLOPT_TIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $http >= 400) {
            throw new RuntimeException('Shopify products error http=' . $http);
        }
        $hdr  = substr($resp, 0, $hsize);
        $body = substr($resp, $hsize);
        $data = json_decode($body, true);
        foreach (($data['products'] ?? []) as $p) {
            $variants = [];
            foreach (($p['variants'] ?? []) as $v) {
                $variants[] = ['title' => $v['title'] ?? '', 'price' => (string)($v['price'] ?? '0')];
            }
            $out[] = ['title' => $p['title'] ?? '', 'variants' => $variants];
        }
        $next = null;
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $hdr, $m)) $next = $m[1];
        usleep(200000);
    }
    return $out;
}
