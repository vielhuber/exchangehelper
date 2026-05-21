<?php
declare(strict_types=1);

foreach (
    [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
        __DIR__ . '/../../../../autoload.php'
    ]
    as $autoload_path
) {
    if (is_file($autoload_path)) {
        require_once $autoload_path;
        break;
    }
}

use vielhuber\simplemcp\simplemcp;

$project_dir = getcwd();
if ($project_dir === false) {
    $project_dir = dirname(__DIR__);
}
$env_path = $project_dir . '/.env';
if (!is_file($env_path) && @file_put_contents($env_path, "MCP_TOKEN=\n") === false) {
    fwrite(STDERR, 'exchangehelper-mcp-server: failed to create ' . $env_path . ' (check permissions on the project directory)' . PHP_EOL);
    exit(1);
}

new simplemcp(
    name: 'exchangehelper-mcp-server',
    log: 'mcp-server.log',
    discovery: '.',
    auth: 'static',
    env: $env_path
);
