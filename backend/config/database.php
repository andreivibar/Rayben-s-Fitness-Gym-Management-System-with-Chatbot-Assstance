<?php

$host = getenv('GYM_DB_HOST') ?: 'localhost';
$port = getenv('GYM_DB_PORT') ?: '3306';
$dbname = getenv('GYM_DB_NAME') ?: 'gym_management';
$db_username = getenv('GYM_DB_USER') ?: 'root';
$db_password = getenv('GYM_DB_PASSWORD') ?: '';

try {
    $conn = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname",
        $db_username,
        $db_password
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set MySQL session timezone to Asia/Manila (UTC+08:00)
    $conn->exec("SET time_zone='+08:00'");

} catch(PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'The database is currently unavailable. Start the MySQL service and try again.'
    ]);
    exit;
}

?>