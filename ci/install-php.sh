#!/bin/sh
# Installs PHP 8.5 and Composer on the Forgejo runner, which is a plain node:20-bookworm image.
# code.forgejo.org does not mirror shivammathur/setup-php, so PHP comes from the sury.org apt repository.
# Kept as a script so the same steps can be tried locally in that image before they run in CI.
set -eu

apt-get update -qq
apt-get install -y -qq --no-install-recommends ca-certificates curl unzip lsb-release
curl -sSLo /tmp/sury.gpg https://packages.sury.org/php/apt.gpg
install -m 644 /tmp/sury.gpg /etc/apt/trusted.gpg.d/sury.gpg
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury.list
apt-get update -qq
apt-get install -y -qq --no-install-recommends php8.5-cli php8.5-mbstring php8.5-xml
curl -sSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

php -v
composer --version
