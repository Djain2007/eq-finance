FROM php:8.2-apache

# Install required system packages and MySQL client
RUN apt-get update && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    libxml2-dev \
    libonig-dev \
    libssl-dev \
    default-mysql-client \
    curl \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        curl \
        dom \
        mbstring \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configure Apache for .htaccess overrides and virtual directory
RUN echo '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/eq-finance.conf \
    && a2enconf eq-finance \
    && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application code
COPY . /var/www/html/

# Ensure directory permissions and entrypoint execution rights
RUN mkdir -p /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod +x /var/www/html/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["apache2-foreground"]

