<?php
// Contact form handler for theapptor.com.
// Emails each message to contact@theapptor.com with Reply-To set to the visitor,
// so replying in the inbox answers them directly. Uses Hostinger's PHP mail().

const TO = 'contact@theapptor.com';
const FROM = 'contact@theapptor.com';
const RATE_LIMIT = 5;          // messages per IP...
const RATE_WINDOW = 15 * 60;   // ...per 15 minutes

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function respond($ok, $error = null, $status = 200) {
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    } else {
        // Plain form post (JavaScript off): go back to the page.
        header('Location: /?' . ($ok ? 'sent=1' : 'error=1') . '#contact', true, 303);
    }
    exit;
}

function field($name, $max) {
    $value = isset($_POST[$name]) ? trim((string) $_POST[$name]) : '';
    return mb_substr($value, 0, $max);
}

function oneLine($value) {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $value));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'POST only', 405);
}

// Honeypot: real people never fill this hidden field.
if (field('website', 200) !== '') {
    respond(true);
}

$name = oneLine(field('first-name', 100));
$email = oneLine(field('email', 200));
$phone = oneLine(field('phone-number', 50));
$message = field('message', 5000);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 400);
}
if (mb_strlen($message) < 2) {
    respond(false, 'Please write a message.', 400);
}

// Simple per-IP rate limit, stored in a temp file.
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
$rateFile = sys_get_temp_dir() . '/apptor-contact-' . hash('sha256', $ip . __FILE__);
$now = time();
$hits = [];
if (is_file($rateFile)) {
    $hits = array_filter(
        array_map('intval', explode(',', (string) @file_get_contents($rateFile))),
        function ($t) use ($now) { return $t > $now - RATE_WINDOW; }
    );
}
if (count($hits) >= RATE_LIMIT) {
    respond(false, 'Too many messages. Please try again later.', 429);
}

$subject = 'Website enquiry from ' . ($name !== '' ? $name : $email);
$body = "New message from the contact form on theapptor.com\n\n"
    . 'Name: ' . ($name !== '' ? $name : '-') . "\n"
    . 'Email: ' . $email . "\n"
    . 'Phone: ' . ($phone !== '' ? $phone : '-') . "\n\n"
    . "Message:\n" . $message . "\n";

$headers = implode("\r\n", [
    'From: The Apptor website <' . FROM . '>',
    'Reply-To: ' . ($name !== '' ? '"' . str_replace('"', '', $name) . '" ' : '') . '<' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$sent = mail(TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . FROM);
if (!$sent) {
    respond(false, 'Sorry, your message could not be sent. Please email contact@theapptor.com.', 502);
}

$hits[] = $now;
@file_put_contents($rateFile, implode(',', $hits));
respond(true);
