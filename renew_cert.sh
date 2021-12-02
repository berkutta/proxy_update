#!/bin/bash

domain=$1
email=$2

echo "Renew cert for domain $1 with $2"

echo "Cert Path: $PROXY_CERT_PATH_HOST/cert"
echo "Challenge Path: $PROXY_CERT_PATH_HOST/challenge"

# The webroot plugin works by creating a temporary file for 
# each of your requested domains in ${webroot-path}/.well-known/acme-challenge

docker run --rm -it \
  -v $PROXY_CERT_PATH_HOST/cert:/etc/letsencrypt \
  -v $PROXY_CERT_PATH_HOST/challenge:/var/www/challenge/.well-known/acme-challenge \
  --name certbot \
  "certbot/certbot" \
  certonly --webroot -w /var/www/challenge -d $domain -m $email -n --text --agree-tos --debug-challenges