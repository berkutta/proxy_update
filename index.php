<?php

require __DIR__ . '/vendor/autoload.php';

require 'helper.php';

use Polkovnik\Component\DockerClient\DockerClient;

// Add Local Docker Containers
$docker = new DockerClient([
    'unix_socket' => '/var/run/docker.sock'
]);

if (checkIfProxyIsRunning($docker) != true) {
    echo "Proxy is not running! \n";
    exit(1);
}

echo "Proxy cert path: " . $_ENV["PROXY_CERT_PATH_HOST"] . "\n";
echo "Proxy cert email: " . $_ENV["PROXY_CERT_EMAIL"] . "\n";

$endpoints = null;
$previousEndpoints = null;

while (true) {
    $endpoints = getProxyConfigurationObject($docker);

    if ($endpoints != $previousEndpoints) {
        echo "Reload config.. \n";

        $config = null;
        $validEndpoints = [];

        foreach ($endpoints as $endpoint) {
            if (ensureCertificatePresentAndValid($endpoint)) {
                array_push($validEndpoints, $endpoint);
            }

            $config = getProxyConfigurationString($validEndpoints);
        }

        file_put_contents("nginx_proxy.conf", $config);

        reloadProxy($docker);
    }

    $previousEndpoints = $endpoints;

    sleep(5);
}
