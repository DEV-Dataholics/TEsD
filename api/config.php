<?php
declare(strict_types=1);

function loadPrivateEnvironment(): void
{
    $configuredPath = getenv('APP_ENV_FILE');
    $paths = array_filter([
        $configuredPath !== false ? $configuredPath : null,
        dirname(__DIR__) . '/credentials/database.env',
        dirname(__DIR__, 2) . '/credentials/database.env',
        dirname(__DIR__) . '/credentials/application.env',
        dirname(__DIR__, 2) . '/credentials/application.env',
    ]);

    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $allowed = in_array($name, [
                'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
                'APP_BASE_URL', 'APP_ALLOWED_ORIGINS', 'APP_COOKIE_SECURE',
                'APP_MAIL_FROM', 'OPENAI_API_KEY', 'OPENAI_MODEL',
            ], true);
            if ($allowed && getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}

function envValue(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function database(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = envValue('DB_HOST');
    $name = envValue('DB_NAME');
    $user = envValue('DB_USER');
    $password = envValue('DB_PASSWORD');
    if (!$host || !$name || !$user || $password === null) {
        throw new RuntimeException('Database credentials are not configured.');
    }

    $connection = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}

loadPrivateEnvironment();
