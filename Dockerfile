FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    libldap2-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    unzip \
    curl \
    && docker-php-ext-install \
    intl \
    gd \
    zip \
    mysqli \
    pdo_mysql \
    mbstring \
    opcache \
    curl \
    xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -L https://github.com/glpi-project/glpi/releases/download/10.0.18/glpi-10.0.18.tgz \
    -o /tmp/glpi.tgz \
    && tar xzf /tmp/glpi.tgz -C /var/www/html/ \
    && rm /tmp/glpi.tgz \
    && chown -R www-data:www-data /var/www/html/glpi

COPY lagapenak/ /var/www/html/glpi/plugins/lagapenak/
RUN chown -R www-data:www-data /var/www/html/glpi/plugins/lagapenak

RUN a2enmod rewrite

RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/glpi\n\
    <Directory /var/www/html/glpi>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

EXPOSE 80
