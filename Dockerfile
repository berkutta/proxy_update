FROM php:8-buster

COPY composer.json /
COPY composer.lock /
COPY helper.php /
COPY index.php /
COPY renew_cert.sh /

RUN apt-get update && \
    apt-get install -y apt-transport-https ca-certificates curl gnupg lsb-release && \
    rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /usr/share/keyrings/docker-archive-keyring.gpg
RUN echo "deb [arch=amd64 signed-by=/usr/share/keyrings/docker-archive-keyring.gpg] https://download.docker.com/linux/debian $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null
RUN apt-get update && \
    apt-get install -y docker-ce-cli && \
    rm -rf /var/lib/apt/lists/*

RUN apt-get update && \
    apt-get install -y zip && \
    rm -rf /var/lib/apt/lists/*
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
RUN php composer-setup.php
RUN ./composer.phar install

ENTRYPOINT ["docker-php-entrypoint"]
CMD ["php", "index.php"]
