<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

// JSON odpověď
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =======================
   Nastavení
   ======================= */
$toEmail      = 'adamkostelenec520@gmail.com';
$fromEmail    = 'kontakt@adamkostelenec.cz'; // z tvojí domény
$fromName     = 'Webový formulář';
$envelopeFrom = 'bounce@adamkostelenec.cz';  // envelope sender pro -f

/* =======================
   Anti-spam a validace
   ======================= */
// Honeypot
if (!empty($_POST['company'] ?? '')) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// (volitelné) lehká kontrola CORS/CSRF pro stejnou doménu
/*
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== 'adamkostelenec.cz') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF check failed'], JSON_UNESCAPED_UNICODE);
    exit;
}
*/

// Vstupy
$nameRaw    = (string)($_POST['name'] ?? '');
$emailRaw   = (string)($_POST['email'] ?? '');
$messageRaw = (string)($_POST['message'] ?? '');

// Trim a základní očista neviditelných znaků
$stripInvis = static function(string $s): string {
    // Odstranění neviditelných separátorů, normalizace
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return trim($s);
};
$name    = $stripInvis($nameRaw);
$email   = $stripInvis($emailRaw);
$message = $stripInvis($messageRaw);

// Základní validace
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Neplatný vstup'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Délkové limity
if (mb_strlen($name) > 100 || mb_strlen($email) > 254 || mb_strlen($message) > 5000) {
    http_response_code(413); // Payload Too Large
    echo json_encode(['ok' => false, 'error' => 'Vstup je příliš dlouhý'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ochrana proti header injection v name + email
if (preg_match('/\r|\n|%0a|%0d/i', $name) || preg_match('/\r|\n|%0a|%0d/i', $email)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Header injection'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Očista obsahu zprávy + normalizace EOL na CRLF
$message = preg_replace("/\r\n|\r|\n/u", "\r\n", $message) ?? '';
$message = trim($message);

// Sestavení zprávy
$subject = 'Nová zpráva z webu';
$body = "Jméno: {$name}\r\nE-mail: {$email}\r\n\r\nZpráva:\r\n{$message}\r\n";

// MIME kódování hlaviček s diakritikou
$encodedSubject      = mb_encode_mimeheader($subject, 'UTF-8', 'B');
$encodedFromName     = mb_encode_mimeheader($fromName, 'UTF-8', 'B');
$encodedReplyToName  = mb_encode_mimeheader($name, 'UTF-8', 'B');

// Hlavičky
$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "From: {$encodedFromName} <{$fromEmail}>\r\n";
$headers .= "Reply-To: {$encodedReplyToName} <{$email}>\r\n";

// Některé hostingy doručují lépe s -f (envelope sender)
$additionalParams = '';
if (filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL)) {
    // escapeshellarg je důležité kvůli bezpečnosti
    $additionalParams = '-f ' . escapeshellarg($envelopeFrom);
}

// Odeslání
$sent = @mail($toEmail, $encodedSubject, $body, $headers, $additionalParams);

if ($sent) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(500);
// Ladicí log na server
$lastError = error_get_last();
error_log('Mail send failed: ' . print_r([
    'to'           => $toEmail,
    'from'         => $fromEmail,
    'envelopeFrom' => $envelopeFrom,
    'error'        => $lastError,
], true));

echo json_encode(['ok' => false, 'error' => 'Odeslání selhalo'], JSON_UNESCAPED_UNICODE);
exit;
