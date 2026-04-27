#!/bin/bash
set -e

CONFIG_DIR=/var/www/lka/config
SAMPLE=$CONFIG_DIR/config-sample.ini
TARGET=$CONFIG_DIR/config.ini

if [ ! -f $TARGET ]; then
  cp $SAMPLE $TARGET
fi

KEYS=$CONFIG_DIR/keys-sync

if [ ! -f $KEYS ]; then
    ssh-keygen -b 4096 -m PEM -f '$KEYS' -N '' -q
fi

chown -R lka:lka $CONFIG_DIR

touch /var/log/cron.log
service cron start

su lka -c "cd /var/www/lka && nohup php /var/www/lka/scripts/syncd.php --systemd > /var/log/keys-sync.log 2>&1 &"

source /etc/apache2/envvars

a2ensite 000-default && service apache2 reload

exec apache2 -DFOREGROUND