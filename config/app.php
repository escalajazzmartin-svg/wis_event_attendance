<?php

require_once __DIR__ . '/timezone.php';

if (!defined('BASE_URL')) {
    $appRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

    if ($appRoot === false || $documentRoot === false) {
        throw new RuntimeException('Unable to determine the application URL.');
    }

    $appRoot = str_replace('\\', '/', $appRoot);
    $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
    $documentRootLength = strlen($documentRoot);

    if (
        strncasecmp($appRoot, $documentRoot, $documentRootLength) !== 0 ||
        (
            strlen($appRoot) > $documentRootLength &&
            substr($appRoot, $documentRootLength, 1) !== '/'
        )
    ) {
        throw new RuntimeException(
            'The application directory is outside the web document root.'
        );
    }

    $relativePath = ltrim(substr($appRoot, $documentRootLength), '/');
    define('BASE_URL', $relativePath === '' ? '' : '/' . $relativePath);
}
