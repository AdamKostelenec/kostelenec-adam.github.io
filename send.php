<?php
declare(strict_types=1);
mb_internal_encoding('UTF-8');

// Pro odpověď frontendem vracíme JSON
header('Content-Type: application/json; charset=UTF-8');

// Povolená metoda
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

/* =======================
   Nastavení
   ======================= */
$toEmail       = 'adamkostelenec520@gmail.com';   // příjemce
$fromEmail     = 'noreply@adamkostelenec.cz';     // MUSÍ být tvoje doména
$fromName      = 'Webový formulář';
$envelopeFrom  = 'bounce@adamkostelenec.cz';      // envelope sender pro -f
;


/* =======================
   Anti-spam a validace
   ======================= */
// Honeypot
if (!empty($_POST['company'])) {
  echo json_encode(['ok' => true]); // potichu „úspěch“ pro boty
  exit;
}

// Vstupy
$name    = trim((string)($_POST['name'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

// Základní validace
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Neplatný vstup']);
  exit;
}

// Ochrana proti header injection
$bad = ["\r", "\n", "%0a", "%0d"];
if (str_contains($name, "\r") || str_contains($name, "\n")) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Header injection']);
  exit;
}

// Očista obsahu
$name    = strip_tags($name);
$message = preg_replace("/\r\n|\r|\n/", "\r\n", $message); // normalizace konců řádků
$message = trim($message);

// Sestavení zprávy
$subject = 'Nová zpráva z webu';
$body = "Jméno: {$name}\r\nE-mail: {$email}\r\n\r\nZpráva:\r\n{$message}\r\n";

// HLAVIČKY
$headers  = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "From: {$fromName} <{$fromEmail}>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";

// Některé hostingy lépe doručují s parametrem -f (envelope sender)
$additionalParams = '';
if (filter_var($envelopeFrom, FILTER_VALIDATE_EMAIL)) {
  $additionalParams = '-f ' . escapeshellarg($envelopeFrom);
}

// Odeslání
$sent = @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, $additionalParams);

if ($sent) {
  echo json_encode(['ok' => true]);
} else {
  http_response_code(500);
  // Ladicí log pro tebe na serveru
  error_log('Mail send failed: ' . print_r([
    'to' => $toEmail,
    'from' => $fromEmail,
    'envelopeFrom' => $envelopeFrom
  ], true));
  echo json_encode(['ok' => false, 'error' => 'Odeslání selhalo']);
}
