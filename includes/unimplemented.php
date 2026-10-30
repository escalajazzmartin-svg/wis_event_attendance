<?php

if (!function_exists('rejectUnimplementedPhpFile')) {
    function rejectUnimplementedPhpFile(string $sourceFile): void
    {
        $requestedFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
        $sourcePath = realpath($sourceFile);

        if ($requestedFile !== false && $sourcePath === $requestedFile) {
            http_response_code(501);
            header('Content-Type: text/plain; charset=utf-8');
            exit('This endpoint is not implemented.');
        }

        throw new LogicException(
            'This PHP module is not implemented: ' . basename($sourceFile)
        );
    }
}
