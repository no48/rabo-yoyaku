# rabo-yoyaku VPS デプロイ手順（Render → VPS 移行用）

このディレクトリは、rabo-yoyaku（注文管理・領収書・請求書・**見積書**・売上集計ツール）を
**自分のVPS（ConoHa等）に Docker で乗せる**ための足場です。Render の代わりに常時起動で動かせます。

## VPSにする利点
- **コールドスタートが無い**（Render無料枠のスリープ→初回表示が遅い問題が消える）
- **採番ファイルが永続**（再起動しても請求書INV-/見積EST-の番号が飛ばない。`data/` に保存）
- 自分の管理下・スペック/料金が固定

## 構成
| ファイル | 役割 |
|---|---|
| `docker-compose.yml` | アプリ(PHP+Apache)＋Caddy(自動HTTPS)を起動 |
| `Caddyfile` | ドメイン宛のHTTPSを自動取得するリバースプロキシ設定 |
| `.env.example` | 環境変数テンプレ（→ `.env` にコピーして使う） |
| `init-data.sh` | 採番ファイルの初期化（永続化用） |
| `data/` | 採番ファイルの保存先（Git管理外） |

---

## 手順

### 0. 事前準備（VPS側・サーバー管理作業）
- ConoHa等で VPS を作成（Ubuntu 22.04 推奨）
- **Docker と Docker Compose を導入**
  ```
  curl -fsSL https://get.docker.com | sh
  ```
- ファイアウォール/セキュリティグループで **80・443 ポートを開放**
- 使うドメイン（例 `invoice.rope-lab.jp`）の **DNS Aレコードを VPS のグローバルIP に向ける**
  （※ HTTPS証明書の自動取得に必要。先に向けておくこと）

### 1. 取得
```
git clone https://github.com/no48/rabo-yoyaku.git
cd rabo-yoyaku/deploy
```

### 2. 設定
```
cp .env.example .env
```
`.env` を編集し、**今 Render の Environment に入っている値をそのまま移す**：
- `SHOPIFY_ACCESS_TOKEN`（読み取り専用トークン）
- `AUTH_USER` / `AUTH_PASS`（管理者ログイン）
- `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASS` / `MAIL_FROM_NAME`

`Caddyfile` を開き、`invoice.rope-lab.jp` を**自分のドメイン**に変更。

### 3. 採番ファイルを用意
```
chmod +x init-data.sh
./init-data.sh
```

### 4. 起動
```
docker compose up -d --build
```
（初回はビルドに数分。Caddy がドメインのHTTPS証明書を自動取得します）

### 5. 動作確認（ブラウザ）
`https://あなたのドメイン/` を開き、Basic認証でログインして各機能を確認：
- `/list`（参加者リスト） / `/receipt`（領収書） / `/invoice/view?order=◯◯`（請求書） / `/estimate/`（見積書）

### 6. Render からの切替
VPSで問題なく動くのを確認したら、ドメインを完全にVPSへ向け、Render側のサービスは停止/削除してよい。

---

## 運用メモ
- **更新（コード反映）**：`git pull && docker compose up -d --build`
- **ログ**：`docker compose logs -f`
- **停止**：`docker compose down`（`data/`の採番ファイルとCaddyの証明書ボリュームは残る）
- **バックアップ**：`data/`（採番台帳）を定期的にコピー。請求書/見積の番号管理に重要。
- **秘匿情報**：`.env` は絶対にGitに上げない（このリポジトリの .gitignore で除外済み）。VPS上のファイル権限も最小に。

## 注意
- 0.のVPS作成・Docker導入・DNS設定は**サーバー管理作業**で、VPSの契約・料金が発生します（あなたの判断・操作）。
- このディレクトリは「足場」です。実際の移行はVPSが用意できてから、上の手順で進めてください。
