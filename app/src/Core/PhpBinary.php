<?php

declare(strict_types=1);

namespace Sabadgardan\Core;

/**
 * Finds the command-line PHP that matches the PHP serving the site. On cPanel hosts
 * ``/usr/local/bin/php`` is often an older default version than the one chosen for the domain,
 * and cron then fails silently; the per-version binaries live in known places:
 * EasyApache (ea-phpXY), CloudLinux alt-php (alt/phpXY), DirectAdmin (phpXY), Debian (phpX.Y).
 */
final class PhpBinary
{
    /** @return list<string> candidate paths, most specific first */
    public static function candidates(): array
    {
        $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $dotted = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $out = [];
        // The web SAPI binary (lsphp, php-fpm, php-cgi) usually sits next to the CLI one.
        if (PHP_BINARY !== '') {
            $dir = dirname(PHP_BINARY);
            $out[] = basename(PHP_BINARY) === 'php' ? PHP_BINARY : $dir . '/php';
            $out[] = dirname($dir) . '/bin/php';
        }
        array_push($out, "/opt/cpanel/ea-php$v/root/usr/bin/php", "/opt/alt/php$v/usr/bin/php", "/usr/local/php$v/bin/php",
            "/usr/bin/php$dotted", '/usr/local/bin/php', '/usr/bin/php');
        return array_values(array_unique($out));
    }

    /** First candidate that exists, else the most likely one for this kind of host. */
    public static function recommended(): string
    {
        foreach (self::candidates() as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }
        // open_basedir may hide /opt: fall back on what the web binary's path reveals.
        $v = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        return match (true) {
            str_contains(PHP_BINARY, '/opt/alt/') => "/opt/alt/php$v/usr/bin/php",
            str_contains(PHP_BINARY, 'ea-php') => "/opt/cpanel/ea-php$v/root/usr/bin/php",
            default => '/usr/local/bin/php',
        };
    }
}
