<?php

declare(strict_types=1);

// For projects without Composer: require this file instead of vendor/autoload.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Moonetix\\ReferralEngine\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/src/' . substr($class, strlen($prefix)) . '.php';
    }
});
