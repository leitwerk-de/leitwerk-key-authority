FROM debian:trixie-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
apache2 \
libapache2-mod-php8.4 \
php8.4 \
php8.4-ldap \
php8.4-mysql \
php8.4-mbstring \
php8.4-gmp \
php8.4-zip \
git \
cron \
openssh-client \
&& rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

RUN adduser --uid 1000 --shell /bin/bash lka

COPY . /var/www/lka
RUN chown -R lka:lka /var/www/lka
WORKDIR /var/www/lka

ENV APACHE_RUN_DIR=/var/run/apache2 \
    APACHE_PID_FILE=/var/run/apache2/apache2.pid \
    APACHE_LOCK_DIR=/var/lock/apache2 \
    APACHE_LOG_DIR=/var/log/apache2

RUN mkdir -p $APACHE_RUN_DIR $APACHE_LOCK_DIR $APACHE_LOG_DIR \
    && chown -R www-data:www-data $APACHE_RUN_DIR $APACHE_LOCK_DIR $APACHE_LOG_DIR \
    && sed -i "s|\${APACHE_RUN_DIR}|$APACHE_RUN_DIR|g; s|\${APACHE_PID_FILE}|$APACHE_PID_FILE|g; s|\${APACHE_LOCK_DIR}|$APACHE_LOCK_DIR|g" /etc/apache2/apache2.conf

RUN a2enmod authnz_ldap rewrite

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer
USER lka
RUN COMPOSER_CACHE_DIR=/dev/null composer install --no-dev
USER root

RUN echo "* * * * * lka cd /var/www/lka && php scripts/ldap_update.php >> /var/log/cron.log 2>&1" >> /etc/cron.d/lka-cron \
    && echo "* * * * * lka cd /var/www/lka && php scripts/supervise_external_keys.php >> /var/log/cron.log 2>&1" >> /etc/cron.d/lka-cron \
    && echo "* * * * * lka cd /var/www/lka && scripts/keys-sync-service.sh >> /var/log/cron.log 2>&1" >> /etc/cron.d/lka-cron \
    && chmod 0644 /etc/cron.d/lka-cron \
    && crontab /etc/cron.d/lka-cron \
    && touch /var/log/cron.log && chown lka:lka /var/log/cron.log \
    && touch /var/log/keys-sync.log && chown lka:lka /var/log/keys-sync.log

COPY docker/startup.sh /
RUN chmod +x /startup.sh

EXPOSE 80 443
CMD ["/startup.sh"]