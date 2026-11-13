<?php

require_once __DIR__ . '/password_reset_service.php';

function passwordResetSendEmail(string $recipient, string $code): void
{
    if (defined('PASSWORD_RESET_TESTING') && PASSWORD_RESET_TESTING === true) {
        $fakeMailer = $GLOBALS['password_reset_test_mailer'] ?? null;
        if (!is_callable($fakeMailer)) {
            throw new RuntimeException('The password reset test mailer is unavailable.');
        }
        $fakeMailer($recipient, $code);
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
        throw new RuntimeException('Password reset mail configuration is unavailable.');
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
    $mail->Subject = 'Your Rayben Gym password reset code';
    $mail->Body = '<p>Your password reset verification code is:</p>'
        . '<p style="font-size:24px;font-weight:bold;letter-spacing:6px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>This code expires in 10 minutes. If you did not request a reset, you can ignore this message.</p>';
    $mail->AltBody = "Your password reset verification code is {$code}. It expires in 10 minutes. If you did not request a reset, ignore this message.";
    $mail->send();
}
