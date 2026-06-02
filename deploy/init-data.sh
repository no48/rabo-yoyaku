#!/bin/bash
# 採番ファイルの初期化（永続化用）。初回 docker compose up の前に1回だけ実行する。
# 請求書(INV-)・見積書(EST-)の連番を保存するファイルを用意し、コンテナのwww-dataが書けるようにする。
set -e
cd "$(dirname "$0")"
mkdir -p data
for f in invoice_counter.json estimate_counter.json; do
    if [ ! -f "data/$f" ]; then
        echo '{}' > "data/$f"
        echo "作成: data/$f"
    else
        echo "既存: data/$f （保持）"
    fi
done
chmod 666 data/*.json
echo "完了。採番ファイルは data/ で永続化されます（VPS再起動でも番号が飛びません）。"
ls -l data/
