<?php

namespace Tests\Unit;

use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_mysql_configuration_does_not_use_deprecated_ssl_ca_constant_on_php_85(): void
    {
        if (PHP_VERSION_ID < 80500 || ! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The PDO MySQL deprecation applies to PHP 8.5+ with pdo_mysql loaded.');
        }

        $deprecations = [];

        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED) {
                $deprecations[] = $message;

                return true;
            }

            return false;
        });

        try {
            require config_path('database.php');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }
}
