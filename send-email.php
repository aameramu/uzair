<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid name, email, and message.']);
    exit;
}

$configPath = __DIR__ . '/smtp-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SMTP is not configured on the server.']);
    exit;
}

$config = require $configPath;

try {
    sendSmtpMail($config, $name, $email, $message);
    echo json_encode(['ok' => true]);
} catch (Throwable $exception) {
    error_log('Contact form email failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Message could not be sent right now.']);
}

function sendSmtpMail(array $config, string $name, string $replyTo, string $message): void
{
    $host = (string)($config['host'] ?? '');
    $port = (int)($config['port'] ?? 587);
    $username = (string)($config['username'] ?? '');
    $password = (string)($config['password'] ?? '');
    $secure = (string)($config['secure'] ?? 'tls');
    $fromEmail = (string)($config['from_email'] ?? $username);
    $fromName = (string)($config['from_name'] ?? 'U & I Consultancy Website');
    $toEmail = (string)($config['to_email'] ?? '');
    $toName = (string)($config['to_name'] ?? 'U & I Consultancy');

    if ($host === '' || $username === '' || $password === '' || $fromEmail === '' || $toEmail === '') {
        throw new RuntimeException('Missing SMTP configuration.');
    }

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        throw new RuntimeException("SMTP connection failed: {$errstr}");
    }

    stream_set_timeout($socket, 20);
    smtpExpect($socket, [220]);
    smtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

    if ($secure === 'tls') {
        smtpCommand($socket, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not start TLS encryption.');
        }
        smtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
    }

    smtpCommand($socket, 'AUTH LOGIN', [334]);
    smtpCommand($socket, base64_encode($username), [334]);
    smtpCommand($socket, base64_encode($password), [235]);
    smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
    smtpCommand($socket, 'DATA', [354]);

    $subject = 'Website inquiry from ' . $name;
    $body = "Name: {$name}\r\nEmail: {$replyTo}\r\n\r\nMessage:\r\n{$message}\r\n";
    $headers = [
        'From: ' . encodeHeader($fromName) . ' <' . $fromEmail . '>',
        'To: ' . encodeHeader($toName) . ' <' . $toEmail . '>',
        'Reply-To: ' . $replyTo,
        'Subject: ' . encodeHeader($subject),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $emailData = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    smtpCommand($socket, str_replace("\n.", "\n..", $emailData) . "\r\n.", [250]);
    smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($response));
    }

    return $response;
}

function encodeHeader(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}
