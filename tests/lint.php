<?php
declare(strict_types=1);

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
$failed = [];
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $output, $code);
    if ($code !== 0) {
        $failed[] = $file->getPathname();
    }
    $output = [];
}
if ($failed !== []) {
    fwrite(STDERR, "Falló lint:\n" . implode("\n", $failed) . "\n");
    exit(1);
}
echo "Sintaxis PHP correcta.\n";

