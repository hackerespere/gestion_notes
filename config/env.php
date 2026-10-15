<?php
// Chargement du fichier .env (lignes CLE=valeur) dans $_ENV.
// Le fichier .env n'est pas versionné : copier .env.example en .env et l'adapter.

function loadEnv($path) {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Retirer les guillemets éventuels autour de la valeur
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

// Lire une variable de configuration, avec une valeur par défaut
function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}

loadEnv(dirname(__DIR__) . '/.env');
