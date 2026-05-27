FROM php:8.2-apache

# Apache設定: .htaccess を有効化
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# 必要なシステムパッケージ + 日本語フォント + PHP 拡張 (dompdf は mbstring / gd 推奨)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libonig-dev libzip-dev unzip git \
        fonts-noto-cjk \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install opcache mbstring gd zip \
    && rm -rf /var/lib/apt/lists/*

# composer インストール
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# composer 依存をインストール (キャッシュ効率のため先に composer.json だけコピー)
WORKDIR /var/www/html
COPY composer.json /var/www/html/composer.json
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# アプリケーション本体を配置
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \;

# Render が割り当てる $PORT で Apache を起動するためのエントリポイント
RUN printf '#!/bin/bash\nset -e\nPORT="${PORT:-80}"\nsed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -i "s|:80>|:${PORT}>|" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground "$@"\n' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
