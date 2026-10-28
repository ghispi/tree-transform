<?php

declare(strict_types=1);

// phpbench bootstrap: Composer autoloader plus a PSR-4 loader for the benchmark support code, so composer.json
// needs no autoload-dev entry for benchmarks/.

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ghispi\\TreeTransform\\Benchmarks\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
