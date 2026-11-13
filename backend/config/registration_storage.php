<?php

function registrationUploadDirectory(): string {
    $configured = getenv('REGISTRATION_UPLOAD_DIR');
    $baseDirectory = $configured !== false && trim($configured) !== ''
        ? trim($configured)
        : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'gym-management-private';

    return rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'registration';
}

function paymentProofDirectory(): string {
    return dirname(registrationUploadDirectory()) . DIRECTORY_SEPARATOR . 'payments';
}
