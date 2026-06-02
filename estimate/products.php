<?php
/**
 * 商品一覧 JSON API（見積ビルダーの商品ピッカー用）
 * GET /estimate/products  → { ok, products: [ { title, variants:[{title,price}] } ] }
 */
require_once 'config.php';
require_basic_auth();
require_once 'lib_shopify.php';
header('Content-Type: application/json; charset=UTF-8');
try {
    echo json_encode(['ok' => true, 'products' => fetch_all_products()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
