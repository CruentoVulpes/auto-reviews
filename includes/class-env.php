<?php

namespace AutoReviews;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Простая обёртка для работы с переменными окружения и .env в плагине.
 *
 * Приоритет:
 * 1) $_ENV / $_SERVER
 * 2) getenv()
 * 3) .env в корне плагина (AUTO_REVIEWS_PLUGIN_DIR/.env)
 */
class Env {

    /**
     * Кэш значений из .env файла.
     *
     * @var array|null
     */
    protected static $file_vars = null;

    /**
     * Получить значение переменной окружения.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public static function get( $key, $default = null ) {
        // 1) $_ENV / $_SERVER.
        if ( isset( $_ENV[ $key ] ) ) {
            return $_ENV[ $key ];
        }

        if ( isset( $_SERVER[ $key ] ) ) {
            return $_SERVER[ $key ];
        }

        // 2) getenv().
        $val = getenv( $key );
        if ( false !== $val && null !== $val ) {
            return $val;
        }

        // 3) .env файл.
        $vars = self::load_file_vars();
        if ( isset( $vars[ $key ] ) ) {
            return $vars[ $key ];
        }

        return $default;
    }

    /**
     * Загрузка .env файла (лениво, 1 раз за запрос).
     *
     * @return array
     */
    protected static function load_file_vars() {
        if ( null !== self::$file_vars ) {
            return self::$file_vars;
        }

        self::$file_vars = [];

        if ( ! defined( 'AUTO_REVIEWS_PLUGIN_DIR' ) ) {
            return self::$file_vars;
        }

        $path = AUTO_REVIEWS_PLUGIN_DIR . '.env';
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return self::$file_vars;
        }

        $lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( ! $lines ) {
            return self::$file_vars;
        }

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line || ( strlen( $line ) > 0 && '#' === $line[0] ) ) {
                continue;
            }

            $parts = explode( '=', $line, 2 );
            if ( 2 !== count( $parts ) ) {
                continue;
            }

            $name  = trim( $parts[0] );
            $value = trim( $parts[1] );

            $value = trim( $value, '\'"' );

            if ( '' !== $name ) {
                self::$file_vars[ $name ] = $value;
            }
        }

        return self::$file_vars;
    }
}

