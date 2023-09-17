FROM debian:bullseye-slim as latest

ARG php_version=8.2
ARG app_user_id=1000
ARG app_group_id=1000

ARG DEBIAN_FRONTEND=noninteractive

ENV PHP_VERSION=${php_version}

RUN set -eux; \
	apt-get update; \
    apt-get -y install apt-transport-https lsb-release ca-certificates curl wget git unzip; \
    wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg; \
    sh -c 'echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list'; \
    apt-get clean

RUN apt-get update;  \
    apt-get -y install \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-zip \
    ; \
    apt-get clean

RUN set -eux; \
    echo "error_log = /var/log/php_cli_errors.log" >> /etc/php/${PHP_VERSION}/cli/php.ini; \
    touch /var/log/php_cli_errors.log; \
    addgroup --gid $app_group_id groupphp; \
    useradd --uid $app_user_id --gid $app_group_id -m userphp ;

COPY update.php /opt/git-tracking/