# Setup
Make sure to touch the config files as docker will otherwise create folders

```
touch nginx_proxy.conf static_hosts.json
```

# Start nginx
```
sudo docker run -d \
    --name nginx_proxy \
    -p 80:80 \
    -p 443:443 \
    -v $(pwd)/certbot/cert:/cert \
    -v $(pwd)/certbot/challenge:/var/www/challenge \
    -v $(pwd)/nginx_proxy.conf:/etc/nginx/conf.d/default.conf \
    -v $(pwd)/logs:/logs \
    --restart unless-stopped \
    nginx
```

# Start proxy-update
```
sudo docker run -it -d \
    --name proxy_certificate_update \
    -e "PROXY_CONTAINER_NAME=nginx_proxy" \
    -e "PROXY_DEFAULT_HOST=example.com" \
    -e "PROXY_CERT_EMAIL=certbot@example.com" \
    -e "PROXY_CERT_PATH_HOST=$(pwd)/certbot" \
    -v /var/run/docker.sock:/var/run/docker.sock \
    -v $(pwd)/certbot/cert:/cert \
    -v $(pwd)/static_hosts.json:/static_hosts.json \
    -v $(pwd)/nginx_proxy.conf:/nginx_proxy.conf \
    berkutta/proxy_update
```

# Build proxy-update
```
docker build -t proxy-update .
```
