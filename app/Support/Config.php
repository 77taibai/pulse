<?php
declare(strict_types=1);

namespace App\Support;

final class Config
{
    private static array $items = [];
    private static ?bool $installed = null;

    public static function load(string $file): void
    {
        $config = require $file;
        $local = dirname($file) . '/config.local.php';
        if (is_file($local)) {
            $localConfig = require $local;
            if (is_array($localConfig)) {
                $config = self::merge($config, $localConfig);
            }
        }
        self::$items = $config;
        self::$installed = null;
    }

    /** 是否已完成安装（存在 config.local.php 且填了数据库信息） */
    public static function installed(): bool
    {
        if (self::$installed !== null) {
            return self::$installed;
        }
        self::$installed = (string) self::get('db.name', '') !== ''
            && (string) self::get('db.user', '') !== ''
            && (string) self::get('auth.password_hash', '') !== '';
        return self::$installed;
    }

    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /** 运行期覆盖（如自动识别出的 base_path / url_style） */
    public static function set(string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $node = &self::$items;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $node[$part] = $value;
                break;
            }
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
    }
}
