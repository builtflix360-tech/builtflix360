<?php
// Receives the quote forms from index.html and emails them to info@builtflix360.co.uk.
header('Content-Type: application/json; charset=utf-8');

$to = 'info@builtflix360.co.uk';
$smtpPass = 'Automating098@'; // password of the info@builtflix360.co.uk mailbox (hPanel > Emails)

// Minimal authenticated SMTP send through Hostinger (plain mail() gets silently dropped or spam-filtered).
function smtp_send($user, $pass, $to, $subject, $headers, $body) {
    $f = @stream_socket_client('ssl://smtp.hostinger.com:465', $en, $es, 15);
    if (!$f) { error_log('send.php SMTP: cannot connect'); return false; }
    $rd  = function () use ($f) {
        $r = '';
        while (($l = fgets($f, 515)) !== false) { $r .= $l; if (substr($l, 3, 1) === ' ') break; }
        return $r;
    };
    $cmd = function ($c, $ok) use ($f, $rd) {
        fwrite($f, $c . "\r\n");
        $r = $rd();
        if (strpos($r, $ok) !== 0) error_log("send.php SMTP: $r");
        return strpos($r, $ok) === 0;
    };
    $rd(); // server greeting
    $ok = $cmd('EHLO builtflix360.co.uk', '250')
       && $cmd('AUTH LOGIN', '334')
       && $cmd(base64_encode($user), '334')
       && $cmd(base64_encode($pass), '235')
       && $cmd("MAIL FROM:<$user>", '250')
       && $cmd("RCPT TO:<$to>", '250')
       && $cmd('DATA', '354');
    if ($ok) {
        $body = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body));
        $body = preg_replace('/^\./m', '..', $body); // dot-stuffing
        fwrite($f, "To: $to\r\nSubject: $subject\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\n" . $headers . "\r\n" . $body . "\r\n.\r\n");
        $ok = strpos($rd(), '250') === 0;
    }
    $cmd('QUIT', '221');
    fclose($f);
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (!empty($_POST['website'])) { echo '{"ok":true}'; exit; } // honeypot: bots fill this, humans never see it

// single-line field: strip CR/LF so nothing can inject mail headers
function line($k, $max = 200) {
    $v = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
    return mb_substr(preg_replace('/\s+/', ' ', $v), 0, $max);
}

$name     = line('name', 100);
$phone    = line('phone', 40);
$email    = line('email', 150);
$service  = line('service');
$when     = line('when');
$postcode = line('postcode', 12);
$budget   = line('budget');
$source   = line('source', 20);
$message  = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 3000);

if ($name === '' || preg_match_all('/\d/', $phone) < 9 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
    http_response_code(422); echo '{"ok":false}'; exit;
}

$body = "New quote request from the website ($source form)\n\n"
      . "Name: $name\nPhone: $phone\nEmail: " . ($email ?: '-') . "\n"
      . "Service: " . ($service ?: '-') . "\nPostcode: " . ($postcode ?: '-') . "\n"
      . "Best time to call: " . ($when ?: '-') . "\nBudget: " . ($budget ?: '-') . "\n\n"
      . "Details:\n" . ($message ?: '-') . "\n";

$subject = '=?UTF-8?B?' . base64_encode("New quote request: " . ($service ?: 'website enquiry') . " - $name") . '?=';
$headers = "From: BuiltFlix 360 <$to>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
if ($email !== '') $headers .= "Reply-To: $email\r\n";

if (smtp_send($to, $smtpPass, $to, $subject, $headers, $body)) { echo '{"ok":true}'; }
else { http_response_code(500); echo '{"ok":false}'; }
