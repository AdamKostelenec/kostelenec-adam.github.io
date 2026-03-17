<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

mb_internal_encoding('UTF-8');

// budeme vracet JSON (stejně jako dřív)
header('Content-Type: application/json; charset=UTF-8');

// pouze POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =======================
   PHPMailer autoload
   (musíš mít nainstalováno přes Composer:
    composer require phpmailer/phpmailer)
   ======================= */
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =======================
   Nastavení
   ======================= */
$toEmail   = 'adamkostelenec520@gmail.com';      // kam to má dorazit
$fromEmail = 'kontakt@adamkostelenec.cz';        // odesílatel z tvé domény
$fromName  = "=?UTF-8?B?" . base64_encode('Webový formulář') . "?="; // jméno odesílatele

/* =======================
   Anti-spam a validace
   ======================= */
// honeypot
if (!empty($_POST['company'] ?? '')) {
    // děláme, že je vše OK
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// vstupy
$nameRaw    = (string)($_POST['name'] ?? '');
$emailRaw   = (string)($_POST['email'] ?? '');
$messageRaw = (string)($_POST['message'] ?? '');

// očista
$stripInvis = static function (string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return trim($s);
};
$name    = $stripInvis($nameRaw);
$email   = $stripInvis($emailRaw);
$message = $stripInvis($messageRaw);

// základní validace
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Neplatný vstup'], JSON_UNESCAPED_UNICODE);
    exit;
}

// délky
if (mb_strlen($name) > 100 || mb_strlen($email) > 254 || mb_strlen($message) > 5000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'Vstup je příliš dlouhý'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ochrana proti header injection
if (preg_match('/\r|\n|%0a|%0d/i', $name) || preg_match('/\r|\n|%0a|%0d/i', $email)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Header injection'], JSON_UNESCAPED_UNICODE);
    exit;
}

// normalizace EOL
$message = preg_replace("/\r\n|\r|\n/u", "\r\n", $message) ?? '';
$message = trim($message);

// text e-mailu
$subject = "=?UTF-8?B?" . base64_encode('Nová zpráva z webu') . "?=";
$bodyTxt = "Jméno: {$name}\r\nE-mail: {$email}\r\n\r\nZpráva:\r\n{$message}\r\n";
$bodyHtml = nl2br(htmlspecialchars($bodyTxt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

/* =======================
   ODESLÁNÍ PŘES SMTP (PHPMailer)
   ======================= */

$mail = new PHPMailer(true);

try {
    // říct PHPMaileru, že chceme SMTP
    $mail->isSMTP();
    // TODO: tady dej údaje od hostitele:
    $mail->Host       = 'mail.webglobe.cz';     // např. mail.tvadomena.cz
    $mail->SMTPAuth   = true;
    $mail->Username   = 'kontakt@adamkostelenec.cz'; // login k SMTP
    $mail->Password   = 'Adam4002711';          // heslo k SMTP
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;    
    $mail->Port       = 465;
    // od koho
    $mail->setFrom($fromEmail, $fromName);
    // kam
    $mail->addAddress($toEmail);
    // aby sis mohl přímo odpovědět odesílateli z formuláře
    $mail->addReplyTo($email, $name);

    // obsah
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $bodyHtml;
    $mail->AltBody = $bodyTxt; // fallback pro klienty bez HTML

    // odeslat
    $mail->send();

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    // log na server
    error_log('PHPMailer error: ' . $mail->ErrorInfo);

    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Odeslání selhalo'
        // klidně si sem pro test dej i $mail->ErrorInfo
        // 'debug' => $mail->ErrorInfo,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
