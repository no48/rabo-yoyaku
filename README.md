# rabo-yoyaku — Shopify イベント参加者エクスポート

ロープアクセスラボの Shopify ストアから、イベント商品の注文・参加者情報を抽出して CSV ダウンロードできる管理ツール。

## 機能

- Shopify 全注文を取得 → イベント商品（line item properties に「参加者#_*」を含む）でフィルタ
- イベント商品ごとに参加者一覧を CSV 出力
- 出力カラム: 注文番号 / 注文日 / 参加者# / お名前 / フリガナ / 会社名 / 職業 / 参加者メール / 生年月日 / 電話番号 / ヘルメット / 領収書 / メモ
- Basic 認証で保護

## Render デプロイ手順

### 1. Web Service 作成

1. https://dashboard.render.com/ にログイン
2. **New +** → **Web Service** 選択
3. このリポジトリ（`no48/rabo-yoyaku`）を接続
4. 設定:
   - **Name**: 任意（例: `rabo-yoyaku`）
   - **Region**: Singapore（日本に最も近い）
   - **Branch**: `main`
   - **Runtime**: `Docker`（自動検出されるはず）
   - **Instance Type**: `Free` または `Starter ($7/月)`

### 2. 環境変数を登録

**Environment** タブで以下を追加：

| Key | Value |
|---|---|
| `SHOPIFY_SHOP` | `xdanqs-41.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | Shopify Admin API トークン |
| `AUTH_USER` | Basic認証ユーザー名（例: `admin`） |
| `AUTH_PASS` | Basic認証パスワード（強力なものに変更推奨） |

### 3. デプロイ

- **Create Web Service** クリック → 自動ビルド・デプロイ開始
- 5分程度でビルド完了
- 発行された URL（例: `https://rabo-yoyaku.onrender.com/`）でアクセス
- Basic認証ダイアログ → `AUTH_USER` / `AUTH_PASS` で入る

## ローカル動作確認

```bash
docker build -t rabo-yoyaku .
docker run -p 8080:80 \
  -e SHOPIFY_SHOP=xdanqs-41.myshopify.com \
  -e SHOPIFY_ACCESS_TOKEN=shpat_xxx \
  -e AUTH_USER=admin \
  -e AUTH_PASS=changeme \
  rabo-yoyaku
```
ブラウザで `http://localhost:8080/` にアクセス。

## ファイル構成

| ファイル | 用途 |
|---|---|
| `Dockerfile` | PHP 8.2 + Apache の最小構成（`$PORT` 対応） |
| `index.php` | UI + CSV 出力本体 |
| `config.php` | 環境変数読込（必須項目欠落で500） |
| `debug.php` | デバッグ用（本番では Basic認証下） |
| `.htaccess` | `config.php` 直接アクセス禁止 |

## セキュリティ

- Shopify トークン・Basic認証パスは **Render 環境変数で管理**（リポジトリにハードコードしない）
- リポジトリは public でも問題ないが private 推奨
- Render Web Service は HTTPS 自動有効化
