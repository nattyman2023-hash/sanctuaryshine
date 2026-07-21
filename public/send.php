<?php
/**
 * Sanctuary Shine form handler.
 *
 * Leads are written to the private CRM data file first, then delivered by
 * Emailit v2 when configured. Hostinger's PHP mail transport is kept as a
 * fallback so a missing or temporarily unavailable API key does not lose a
 * booking or enquiry.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST requests only.']);
    exit;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function clean_field(string $key, int $maxLength = 500): string
{
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    $value = strip_tags($value);
    $value = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    return substr($value, 0, $maxLength);
}

function load_site_config(): array
{
    $configPath = __DIR__ . '/crm-config.php';
    if (!is_file($configPath)) return [];

    $config = include $configPath;
    return is_array($config) ? $config : [];
}

function save_lead(array $lead): bool
{
    $dataDirectory = __DIR__ . '/crm-data';
    if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0750, true)) return false;

    // Prevent direct web access if Apache is used.
    $accessFile = $dataDirectory . '/.htaccess';
    if (!is_file($accessFile)) {
        @file_put_contents($accessFile, "Require all denied\nDeny from all\n");
    }

    $dataFile = $dataDirectory . '/leads.json';
    $handle = @fopen($dataFile, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        return false;
    }

    rewind($handle);
    $contents = stream_get_contents($handle);
    $leads = json_decode($contents ?: '[]', true);
    if (!is_array($leads)) $leads = [];

    $leads[] = $lead;
    $json = json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    rewind($handle);
    ftruncate($handle, 0);
    $written = $json !== false && fwrite($handle, $json) !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($dataFile, 0600);

    return $written;
}

function send_emailit_email(string $apiKey, string $apiUrl, array $emailData, string $idempotencyKey): bool
{
    if ($apiKey === '' || !function_exists('curl_init')) return false;

    $curl = curl_init($apiUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Idempotency-Key: ' . $idempotencyKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($emailData),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    return $response !== false && $httpCode >= 200 && $httpCode < 300;
}

function send_php_mail(string $to, string $subject, string $text, string $from, string $fromName, string $replyTo = ''): bool
{
    if (!function_exists('mail')) return false;

    $headers = [
        'From: ' . $fromName . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ($replyTo !== '') $headers[] = 'Reply-To: ' . $replyTo;

    return @mail($to, $subject, $text, implode("\r\n", $headers));
}

function email_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function email_detail_table(array $rows): string
{
    $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:20px 0;background:#f8fafb;border:1px solid #e6edef;border-radius:12px;overflow:hidden;">';
    foreach ($rows as $label => $value) {
        $displayValue = (string) $value;
        if ($displayValue === '') $displayValue = 'Not provided';
        $html .= '<tr>'
            . '<td style="padding:12px 14px;border-bottom:1px solid #e6edef;color:#6c797c;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;width:38%;">' . email_escape((string) $label) . '</td>'
            . '<td style="padding:12px 14px;border-bottom:1px solid #e6edef;color:#0b0b0b;font-size:14px;">' . nl2br(email_escape($displayValue)) . '</td>'
            . '</tr>';
    }
    return $html . '</table>';
}

function branded_email_html(string $eyebrow, string $title, string $intro, string $contentHtml, string $preheader = ''): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>' . email_escape($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f3f7f8;color:#0b0b0b;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . email_escape($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;background:#f3f7f8;">'
        . '<tr><td align="center" style="padding:28px 12px;">'
        . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 8px 30px rgba(11,11,11,.08);">'
        . '<tr><td style="padding:26px 30px;background:#0b0b0b;color:#ffffff;">'
        . '<div style="font-size:22px;font-weight:800;letter-spacing:-.02em;"><span style="display:inline-block;margin-right:9px;padding:7px 8px;border-radius:9px;background:#ffd200;color:#0b0b0b;font-size:14px;vertical-align:2px;">SS</span>Sanctuary Shine</div>'
        . '<div style="margin-top:8px;color:#d9fbff;font-size:11px;font-weight:700;letter-spacing:.16em;">PROFESSIONAL CLEANING</div>'
        . '</td></tr>'
        . '<tr><td style="padding:34px 30px 26px;">'
        . '<p style="margin:0 0 10px;color:#008c99;font-size:12px;font-weight:800;letter-spacing:.16em;">' . email_escape($eyebrow) . '</p>'
        . '<h1 style="margin:0 0 14px;color:#0b0b0b;font-size:30px;line-height:1.2;letter-spacing:-.02em;">' . email_escape($title) . '</h1>'
        . '<p style="margin:0;color:#3c494c;font-size:16px;line-height:1.65;">' . nl2br(email_escape($intro)) . '</p>'
        . $contentHtml
        . '</td></tr>'
        . '<tr><td style="padding:24px 30px;background:#f8fafb;border-top:1px solid #e6edef;text-align:center;">'
        . '<p style="margin:0 0 7px;color:#0b0b0b;font-size:14px;font-weight:800;">Sanctuary Shine</p>'
        . '<p style="margin:0 0 7px;color:#3c494c;font-size:13px;line-height:1.5;">13 Moorsholme Ave, Manchester, M40 9BW</p>'
        . '<p style="margin:0;color:#3c494c;font-size:13px;"><a href="tel:01611234567" style="color:#008c99;text-decoration:none;">0161 123 4567</a> &nbsp;·&nbsp; <a href="mailto:contact@sanctuaryshine.co.uk" style="color:#008c99;text-decoration:none;">contact@sanctuaryshine.co.uk</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function deliver_email(array $emailData, string $idempotencyKey, array $config): string
{
    $apiKey = trim((string) ($config['emailit_api_key'] ?? ''));
    if ($apiKey === '') $apiKey = trim((string) (getenv('EMAILIT_API_KEY') ?: ''));
    $apiUrl = 'https://api.emailit.com/v2/emails';
    $from = (string) ($config['transactional_from_email'] ?? $config['from_email'] ?? '');
    if ($from === '') $from = (string) (getenv('EMAILIT_FROM_EMAIL') ?: 'contact@sanctuaryshine.co.uk');
    $fromName = (string) ($config['from_name'] ?? 'Sanctuary Shine Website');
    $replyTo = '';
    if (isset($emailData['reply_to'])) {
        $replyTo = is_array($emailData['reply_to']) ? (string) ($emailData['reply_to'][0] ?? '') : (string) $emailData['reply_to'];
    }
    $to = is_array($emailData['to']) ? (string) ($emailData['to'][0] ?? '') : (string) $emailData['to'];
    $text = (string) ($emailData['text'] ?? '');

    if (send_emailit_email($apiKey, $apiUrl, $emailData, $idempotencyKey)) return 'emailit';
    if (send_php_mail($to, (string) $emailData['subject'], $text, $from, $fromName, $replyTo)) return 'php-mail';

    return 'failed';
}

$config = load_site_config();
$formType = clean_field('form_type', 30);
$name = clean_field('name', 120);
$email = clean_field('email', 240);
$phone = clean_field('phone', 60);
$postcode = clean_field('postcode', 30);
$message = clean_field('message', 10000);
$cleaningType = clean_field('cleaningType', 120);
$propertyType = clean_field('propertyType', 120);
$size = clean_field('size', 30);
$preferredDate = clean_field('preferredDate', 30);
$subject = clean_field('subject', 120);

// Honeypot: bots get a harmless success response without creating a lead.
if (clean_field('website', 200) !== '') {
    json_response(['status' => 'success', 'message' => 'Thank you.']);
}

$errors = [];
if ($name === '') $errors[] = 'Name is required.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
if ($message === '' && $formType !== 'quote') $errors[] = 'Message is required.';

if ($formType !== 'quote' && $formType !== 'contact') $formType = 'contact';
if ($formType === 'contact') {
    if ($phone === '') $errors[] = 'Phone number is required.';
    if ($postcode === '') $errors[] = 'Postcode is required.';
    if ($subject === '') $errors[] = 'Please select a subject.';
    if ($cleaningType === '') $errors[] = 'Cleaning service is required.';
    if ($propertyType === '') $errors[] = 'Property type is required.';
}

if ($errors) json_response(['status' => 'error', 'message' => implode(' ', $errors)], 400);
$leadType = $formType === 'quote' || $subject === 'quote'
    ? 'quote'
    : ($subject === 'booking' ? 'booking' : 'inquiry');
$leadId = 'lead_' . bin2hex(random_bytes(8));
$submittedAt = gmdate('c');

$lead = [
    'id' => $leadId,
    'type' => $leadType,
    'status' => 'new',
    'created_at' => $submittedAt,
    'updated_at' => $submittedAt,
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'postcode' => $postcode,
    'subject' => $subject,
    'cleaning_type' => $cleaningType,
    'property_type' => $propertyType,
    'size' => $size,
    'preferred_date' => $preferredDate,
    'message' => $message,
    'email_status' => 'pending',
    'notes' => '',
];

if (!save_lead($lead)) {
    json_response(['status' => 'error', 'message' => 'We could not save your request. Please call us directly.'], 500);
}

$formLabel = $leadType === 'booking' ? 'Booking Request' : ($leadType === 'quote' ? 'Quote Request' : 'Website Enquiry');
$adminEmail = (string) ($config['admin_email'] ?? 'contact@sanctuaryshine.co.uk');
$fromEmail = (string) ($config['transactional_from_email'] ?? $config['from_email'] ?? 'contact@sanctuaryshine.co.uk');
$fromName = (string) ($config['from_name'] ?? 'Sanctuary Shine Website');
$businessAddress = '13 Moorsholme Ave, Manchester, M40 9BW';
$adminSubject = 'New ' . $formLabel . ' from ' . $name;
$adminBody = "New request from the Sanctuary Shine website.\n\n";
$adminBody .= "Type: {$formLabel}\nName: {$name}\nEmail: {$email}\nPhone: {$phone}\nPostcode: {$postcode}\n";
if ($subject !== '') $adminBody .= "Subject: {$subject}\n";
if ($cleaningType !== '') $adminBody .= "Cleaning Type: {$cleaningType}\n";
if ($propertyType !== '') $adminBody .= "Property Type: {$propertyType}\n";
if ($size !== '') $adminBody .= "Bedrooms/Offices: {$size}\n";
if ($preferredDate !== '') $adminBody .= "Preferred Date: {$preferredDate}\n";
$adminBody .= "\nMessage:\n{$message}\n\nSubmitted: {$submittedAt}\nLead ID: {$leadId}\n\nSanctuary Shine address: {$businessAddress}\n";

$adminContentHtml = '<p style="margin:20px 0 8px;color:#3c494c;font-size:15px;line-height:1.6;">A new request has arrived through your website. It has also been saved in the CRM.</p>'
    . email_detail_table([
        'Request type' => $formLabel,
        'Name' => $name,
        'Email' => $email,
        'Phone' => $phone,
        'Postcode' => $postcode,
        'Subject' => $subject,
        'Cleaning service' => $cleaningType,
        'Property type' => $propertyType,
        'Bedrooms / offices' => $size,
        'Preferred date' => $preferredDate,
        'Submitted' => $submittedAt,
        'Lead ID' => $leadId,
    ])
    . '<div style="margin:20px 0;padding:18px 20px;border-left:4px solid #00c2d1;background:#f8fafb;border-radius:0 10px 10px 0;">'
    . '<p style="margin:0 0 8px;color:#008c99;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Customer message</p>'
    . '<p style="margin:0;color:#3c494c;font-size:15px;line-height:1.65;">' . nl2br(email_escape($message)) . '</p></div>'
    . '<p style="margin:24px 0 0;"><a href="https://sanctuaryshine.co.uk/crm/" style="display:inline-block;padding:13px 20px;border-radius:9px;background:#00c2d1;color:#ffffff;font-size:14px;font-weight:800;text-decoration:none;">Open CRM dashboard</a></p>';

$adminEmailData = [
    'from' => $fromName . ' <' . $fromEmail . '>',
    'to' => [$adminEmail],
    'reply_to' => [$email],
    'subject' => $adminSubject,
    'text' => $adminBody,
    'html' => branded_email_html('NEW ' . strtoupper($formLabel), 'New ' . $formLabel . ' from ' . $name, 'A new customer request has been received and is ready for follow-up.', $adminContentHtml, 'New ' . $formLabel . ' from ' . $name),
];

$confirmationBody = "Hi {$name},\n\nThank you for contacting Sanctuary Shine. We've received your {$formLabel} and will be in touch within 2 hours during business hours.\n\n";
$confirmationBody .= "Request details:\nType: {$formLabel}\nCleaning service: {$cleaningType}\nProperty type: {$propertyType}\nPreferred date: {$preferredDate}\nPostcode: {$postcode}\n\nLead ID: {$leadId}\n\nIf you need urgent assistance, call 0161 123 4567.\n\nOur address: {$businessAddress}\n\nBest regards,\nThe Sanctuary Shine Team\ncontact@sanctuaryshine.co.uk\n";
$confirmationContentHtml = '<div style="margin:22px 0 0;padding:18px 20px;background:#d9fbff;border-radius:12px;">'
    . '<p style="margin:0;color:#008c99;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">What happens next</p>'
    . '<p style="margin:8px 0 0;color:#0b0b0b;font-size:15px;line-height:1.6;">Our team will review your request and get back to you within 2 hours during business hours.</p></div>'
    . '<h2 style="margin:28px 0 10px;color:#0b0b0b;font-size:19px;">Your request summary</h2>'
    . email_detail_table([
        'Request type' => $formLabel,
        'Cleaning service' => $cleaningType,
        'Property type' => $propertyType,
        'Preferred date' => $preferredDate,
        'Postcode' => $postcode,
        'Lead reference' => $leadId,
    ])
    . '<div style="margin:20px 0;padding:18px 20px;border-left:4px solid #ffd200;background:#fffaf0;border-radius:0 10px 10px 0;">'
    . '<p style="margin:0 0 8px;color:#8a6900;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">Your message</p>'
    . '<p style="margin:0;color:#3c494c;font-size:15px;line-height:1.65;">' . nl2br(email_escape($message)) . '</p></div>'
    . '<p style="margin:24px 0 0;color:#3c494c;font-size:14px;line-height:1.6;">We look forward to helping you. Our team is based at <strong>13 Moorsholme Ave, Manchester, M40 9BW</strong>.</p>'
    . '<p style="margin:22px 0 0;"><a href="https://sanctuaryshine.co.uk/" style="display:inline-block;padding:13px 20px;border-radius:9px;background:#ffd200;color:#0b0b0b;font-size:14px;font-weight:800;text-decoration:none;">Visit Sanctuary Shine</a></p>';
$confirmationData = [
    'from' => $fromName . ' <' . $fromEmail . '>',
    'to' => [$email],
    'subject' => 'We received your Sanctuary Shine ' . strtolower($formLabel),
    'text' => $confirmationBody,
    'html' => branded_email_html('REQUEST RECEIVED', 'Thanks, ' . $name . ' — we have your request', 'Thank you for contacting Sanctuary Shine. Your request has reached our team.', $confirmationContentHtml, 'Your Sanctuary Shine request has been received'),
];

$adminTransport = deliver_email($adminEmailData, $leadId . '-admin', $config);
// A customer confirmation is helpful, but should not make a valid lead fail.
deliver_email($confirmationData, $leadId . '-customer', $config);

if ($adminTransport === 'failed') {
    json_response(['status' => 'error', 'message' => 'We saved your request, but email delivery is temporarily unavailable. Please call us if you need an immediate reply.'], 502);
}

// Update the stored record with the actual delivery transport.
$dataFile = __DIR__ . '/crm-data/leads.json';
if (is_file($dataFile)) {
    $handle = @fopen($dataFile, 'c+');
    if ($handle && flock($handle, LOCK_EX)) {
        rewind($handle);
        $leads = json_decode(stream_get_contents($handle) ?: '[]', true);
        if (is_array($leads)) {
            foreach ($leads as &$storedLead) {
                if (($storedLead['id'] ?? '') === $leadId) {
                    $storedLead['email_status'] = $adminTransport;
                    $storedLead['updated_at'] = gmdate('c');
                    break;
                }
            }
            unset($storedLead);
            $json = json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            rewind($handle);
            ftruncate($handle, 0);
            if ($json !== false) fwrite($handle, $json);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

json_response([
    'status' => 'success',
    'message' => "Thank you. We've received your {$formLabel} and will be in touch within 2 hours.",
]);
?>
