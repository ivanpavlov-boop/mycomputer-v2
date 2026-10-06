<?php

// Run only through verify-backend-images.sh, as www-data in an isolated image.
// Do not bootstrap Laravel: the probe must not need .env, a database or storage.
$checkReadable = static function (string $path): void {
    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("UNREADABLE_FILE: {$path}");
    }

    fclose($handle);
};

try {
    foreach (['artisan', 'bootstrap/app.php', 'public/index.php', 'vendor/autoload.php'] as $path) {
        $checkReadable($path);
    }

    $checked = 0;

    foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'resources/views', 'vendor'] as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $checkReadable($file->getPathname());
                $checked++;
            }
        }
    }

    require 'vendor/autoload.php';

    foreach ([
        App\Http\Controllers\Api\V1\ProductController::class,
        App\Http\Resources\ProductCardResource::class,
        App\Services\Reviews\ReviewStatsService::class,
    ] as $class) {
        if (! class_exists($class)) {
            throw new RuntimeException("AUTOLOAD_FAILED: {$class}");
        }

        printf("AUTOLOAD_OK: %s\n", $class);
    }

    printf("PHP_READABILITY_OK: %d files\n", $checked);
} catch (Throwable $error) {
    file_put_contents('php://stderr', 'BACKEND_IMAGE_CHECK_FAILED: '.$error->getMessage().PHP_EOL);
    exit(1);
}
