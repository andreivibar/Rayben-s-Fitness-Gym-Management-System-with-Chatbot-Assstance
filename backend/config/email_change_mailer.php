<?php

require_once __DIR__ . '/password_reset_service.php';

function emailChangeSendMessage(string $recipient, string $subject, string $htmlBody, string $textBody, string $kind, ?string $code = null): void
{
    if (defined('EMAIL_CHANGE_TESTING') && EMAIL_CHANGE_TESTING === true) {
        $fakeMailer = $GLOBALS['email_change_test_mailer'] ?? null;
        if (!is_callable($fakeMailer)) {
            throw new RuntimeException('The email-change test mailer is unavailable.');
        }
        $fakeMailer($kind, $recipient, $code, $subject, $htmlBody, $textBody);
        return;
    }

    $host = passwordResetEnvironmentVariable('MAIL_HOST');
    $port = filter_var(passwordResetEnvironmentVariable('MAIL_PORT'), FILTER_VALIDATE_INT);
    $encryption = strtolower(passwordResetEnvironmentVariable('MAIL_ENCRYPTION'));
    $username = passwordResetEnvironmentVariable('MAIL_USERNAME');
    $appPassword = str_replace(' ', '', passwordResetEnvironmentVariable('MAIL_PASSWORD'));
    $fromAddress = passwordResetEnvironmentVariable('MAIL_FROM_ADDRESS');
    $fromName = passwordResetEnvironmentVariable('MAIL_FROM_NAME');
    if ($host !== 'smtp.gmail.com'
        || $port !== 587
        || $encryption !== 'tls'
        || $username === ''
        || $appPassword === ''
        || $fromAddress === ''
        || $fromName === '') {
        throw new RuntimeException('Email-change mail configuration is unavailable.');
    }

    require_once __DIR__ . '/../vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $appPassword;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $port;
    $mail->CharSet = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mail->setFrom($fromAddress, $fromName);
    $mail->addAddress($recipient);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;
    $mail->send();
}

function emailChangeSendVerificationEmail(string $recipient, string $code): void
{
    $escapedCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    emailChangeSendMessage(
        $recipient,
        'Your Rayben Gym email verification code',
        '<p>Use this code to verify your new email address:</p>'
            . '<p style="font-size:24px;font-weight:bold;letter-spacing:6px">' . $escapedCode . '</p>'
            . '<p>This code expires in 10 minutes. If you did not request this change, you can ignore this message.</p>',
        "Your email verification code is {$code}. It expires in 10 minutes. If you did not request this change, ignore this message.",
        'verification',
        $code
    );
}

function emailChangeSendPreviousAddressNotification(string $previousAddress, string $newAddress): void
{
    $escapedAddress = htmlspecialchars($newAddress, ENT_QUOTES, 'UTF-8');
    emailChangeSendMessage(
        $previousAddress,
        'Your Rayben Gym account email was changed',
        '<p>The email address on your Rayben Gym account was changed.</p>'
            . '<p>The new address is ' . $escapedAddress . '.</p>'
            . '<p>If you did not make this change, contact gym administration.</p>',
        "The email address on your Rayben Gym account was changed to {$newAddress}. If you did not make this change, contact gym administration.",
        'previous-address-notification'
    );
}
