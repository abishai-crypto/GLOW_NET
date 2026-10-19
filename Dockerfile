# =====================================================================
# GLOW NET V4 - Cloud Production Dockerfile (Optimized for Render.com)
# =====================================================================
FROM php:8.2-apache

# Sakinisha viendelezi muhimu vya PHP kwa ajili ya MikroTik na Database
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    zip \
    unzip \
    curl \
    libpng-dev \
    libonig-dev \
    && docker-php-ext-install pdo pdo_mysql sockets bcmath zip gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Wezesha moduli za Apache
RUN a2enmod rewrite headers

# Weka folda ya kazi
WORKDIR /var/www/html

# Nakili msimbo wa mfumo
COPY . /var/www/html/

# Rekebisha script ya kuwasha container
RUN chmod +x /var/www/html/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Bandari (Ports)
EXPOSE 80 10000

# Washa mfumo kupitia entrypoint
ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
