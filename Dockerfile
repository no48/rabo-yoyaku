FROM php:8.2-apache

# Apache設定: .htaccess を有効化
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# PHP拡張
RUN docker-php-ext-install opcache

# アプリケーション配置
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && find /var/www/html -type d -exec chmod 755 {} \;

# Render が割り当てる $PORT で Apache を起動するためのエントリポイント
RUN printf '#!/bin/bash\nset -e\nPORT="${PORT:-80}"\nsed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf\nsed -i "s|:80>|:${PORT}>|" /etc/apache2/sites-available/000-default.conf\nexec apache2-foreground "$@"\n' > /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
