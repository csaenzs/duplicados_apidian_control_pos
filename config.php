<?php
/**
 * Archivo de configuración centralizado
 * Carga las variables de entorno y define constantes de seguridad
 */

class Config {
    private static $env = [];
    private static $loaded = false;

    /**
     * Cargar archivo .env
     */
    public static function load($path = __DIR__ . '/.env') {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            throw new Exception("Archivo .env no encontrado. Por favor, cree el archivo .env basándose en .env.example");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parsear línea key=value
            list($key, $value) = explode('=', $line, 2) + [NULL, NULL];
            if ($key && $value !== NULL) {
                $key = trim($key);
                $value = trim($value);

                // Remover comillas si existen
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }

                self::$env[$key] = $value;

                // También setear como variable de entorno de PHP
                putenv("$key=$value");
            }
        }

        self::$loaded = true;
    }

    /**
     * Obtener valor de configuración
     */
    public static function get($key, $default = null) {
        if (!self::$loaded) {
            self::load();
        }

        return self::$env[$key] ?? $_ENV[$key] ?? getenv($key) ?? $default;
    }

    /**
     * Obtener configuración de base de datos
     */
    public static function getDatabaseConfig() {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'port' => self::get('DB_PORT', '3306'),
            'database' => self::get('DB_DATABASE'),
            'username' => self::get('DB_USERNAME'),
            'password' => self::get('DB_PASSWORD'),
            'charset' => self::get('DB_CHARSET', 'utf8mb4')
        ];
    }

    /**
     * Validar que todas las variables requeridas estén presentes
     */
    public static function validate() {
        $required = [
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD'
        ];

        $missing = [];
        foreach ($required as $key) {
            if (!self::get($key)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new Exception("Variables de entorno faltantes: " . implode(', ', $missing));
        }

        return true;
    }

    /**
     * Verificar si estamos en modo debug
     */
    public static function isDebug() {
        return self::get('APP_DEBUG', 'false') === 'true';
    }

    /**
     * Verificar si estamos en producción
     */
    public static function isProduction() {
        return self::get('APP_ENV', 'production') === 'production';
    }
}

// Funciones helper para acceso rápido
function config($key, $default = null) {
    return Config::get($key, $default);
}

function db_config() {
    return Config::getDatabaseConfig();
}

// Cargar configuración automáticamente
try {
    Config::load();
    Config::validate();
} catch (Exception $e) {
    // En producción, no mostrar detalles del error
    if (Config::isProduction()) {
        die("Error de configuración. Por favor, contacte al administrador.");
    } else {
        die("Error de configuración: " . $e->getMessage());
    }
}
?>