<?php

function checkIfProxyIsRunning($docker)
{
    $containers = $docker->listContainers();

    $proxy_container_running = false;

    foreach ($containers as $container) {
        if ($container['Names'][0] == "/" . $_ENV["PROXY_CONTAINER_NAME"]) {
            $proxy_container_running = true;
        }
    }

    return $proxy_container_running;
}

function reloadProxy($docker)
{
    echo "Reload proxy.. \n";

    echo shell_exec("docker exec " . $_ENV["PROXY_CONTAINER_NAME"] . " nginx -s reload");
}

function ensureCertificatePresentAndValid($endpoint)
{
    if (
        !empty($endpoint->virtual_host) &&
        file_exists("/cert" . "/live/" . $endpoint->virtual_host . "/fullchain.pem") &&
        file_exists("/cert" . "/live/" . $endpoint->virtual_host . "/privkey.pem") &&
        file_exists("/cert" . "/live/" . $endpoint->virtual_host . "/certbot_run")
    ) {

        if (
            time() - filemtime("/cert" . "/live/" . $endpoint->virtual_host . "/certbot_run") > 7 * 24 * 3600
        ) {
            // file older than 7 days
            renewCertificate($endpoint->virtual_host, $_ENV["PROXY_CERT_EMAIL"]);

            return true;
        } else {
            // Certificate new enough
            return true;
        }
    }

    renewCertificate($endpoint->virtual_host, $_ENV["PROXY_CERT_EMAIL"]);

    return true;
}

function renewCertificate($domain, $email)
{
    echo shell_exec("bash renew_cert.sh " . $domain . " " . $email);

    touch("/cert" . "/live/" . $domain . "/certbot_run");
}

function appendProxyConfig($config, $endpoint)
{
    if (
        !empty($endpoint->virtual_host) &&
        file_exists("/cert" . "/live/" . $endpoint->virtual_host . "/fullchain.pem") &&
        file_exists("/cert" . "/live/" . $endpoint->virtual_host . "/privkey.pem")
    ) {

        $config .= "server {
    listen       443 ssl;
    server_name  " . $endpoint->virtual_host . ";

    access_log /logs/" . $endpoint->virtual_host . ".access.log;
    error_log /logs/" . $endpoint->virtual_host . ".error.log;

";

        if (isset($_ENV["PROXY_ERROR_PAGES"]) && $_ENV["PROXY_ERROR_PAGES"] == "true") {
            $config .= "include snippets/error_pages.conf;";
        }

        $config .= "
    ssl_certificate      " . "/cert" . "/live/" . $endpoint->virtual_host . "/fullchain.pem;
    ssl_certificate_key  " . "/cert" . "/live/" . $endpoint->virtual_host . "/privkey.pem;

    ssl_protocols TLSv1.3 TLSv1.2;
    ssl_prefer_server_ciphers on;
    ssl_ecdh_curve secp521r1:secp384r1;
    ssl_ciphers EECDH+AESGCM:EECDH+AES256;

    ssl_session_cache shared:TLS:2m;
    ssl_buffer_size 4k;

    location / {
        proxy_pass " . $endpoint->virtual_proto . "://" . $endpoint->ip . ":" . $endpoint->virtual_port . ";
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection \"upgrade\";
    }
}

";
    } else {
        echo "Certificate for " . $endpoint->virtual_host . " not present \n";
    }

    return $config;
}

function getProxyConfigurationObject($docker)
{
    $endpoints = array();

    $containers = $docker->listContainers();

    foreach ($containers as $container) {

        $endpoint = new stdClass;

        if (!isset($container['Id'])) {
            continue;
        }
        $endpoint->id = $container['Id'];
        
        if (!isset($container['Names'][0])) {
            continue;
        }
        $endpoint->name = $container['Names'][0];

        if (!isset($container['NetworkSettings']['Networks']['bridge']['IPAddress'])) {
            continue;
        }
        $endpoint->ip = $container['NetworkSettings']['Networks']['bridge']['IPAddress'];

        $inspect = $docker->inspectContainer($container['Id']);

        // Prefill if key is not set..
        $endpoint->virtual_proto = "http";
        $endpoint->virtual_port = 80;

        foreach ($inspect['Config']['Env'] as $env) {
            $env_parts = explode("=", $env);

            switch ($env_parts[0]) {
                case "LETSENCRYPT_HOST":
                    $endpoint->virtual_host = $env_parts[1];
                    break;
                case "VIRTUAL_HOST":
                    $endpoint->virtual_host = $env_parts[1];
                    break;
                case "VIRTUAL_PROTO":
                    $endpoint->virtual_proto = $env_parts[1];
                    break;
                case "VIRTUAL_PORT":
                    $endpoint->virtual_port = $env_parts[1];
                    break;
            }
        }

        if (!empty($endpoint->virtual_host)) {
            array_push($endpoints, $endpoint);
        }
    }

    if (is_file("static_hosts.json")) {
        // Add static hosts
        $static_hosts = json_decode(file_get_contents("static_hosts.json"));

        if (isset($static_hosts)) {
            foreach ($static_hosts as $static_host) {

                $endpoint = new stdClass;

                $endpoint->ip = $static_host->ip;
                $endpoint->virtual_host = $static_host->virtual_host;
                $endpoint->virtual_proto = $static_host->virtual_proto;
                $endpoint->virtual_port = $static_host->virtual_port;

                if (!empty($endpoint->virtual_host)) {
                    array_push($endpoints, $endpoint);
                }
            }
        }
    }

    return $endpoints;
}

function getProxyConfigurationString($endpoints)
{
    $config = "server {
    listen 80 default_server;
    server_name _;
";

    if (isset($_ENV["PROXY_ERROR_PAGES"]) && $_ENV["PROXY_ERROR_PAGES"] == "true") {
        $config .= "include snippets/error_pages.conf;";
    }

    $config .= "

    location ^~ /.well-known/acme-challenge/ {
        alias /var/www/challenge/;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

";

    if (isset($_ENV["PROXY_DEFAULT_HOST"]) && $_ENV["PROXY_DEFAULT_HOST"]) {

        if (
            file_exists("/cert" . "/live/" . $_ENV["PROXY_DEFAULT_HOST"] . "/fullchain.pem") &&
            file_exists("/cert" . "/live/" . $_ENV["PROXY_DEFAULT_HOST"] . "/privkey.pem")
        ) {
            $config .= "server {
    listen 443 ssl;
    server_name _;

    ssl_certificate      " . "/cert" . "/live/" . $_ENV["PROXY_DEFAULT_HOST"] . "/fullchain.pem;
    ssl_certificate_key  " . "/cert" . "/live/" . $_ENV["PROXY_DEFAULT_HOST"] . "/privkey.pem;

    return 444;
}

";
        }
    }

    foreach ($endpoints as $endpoint) {
        $config = appendProxyConfig($config, $endpoint);
    }

    return $config;
}
