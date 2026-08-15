<?php
/**
 * Small authenticated CRM API for the static Sanctuary Shine site.
 * Data is stored in private crm-data JSON files and never returned without a session.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function crm_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function crm_persistent_root(): string
{
    // Auto-deploy (e.g. Hostinger's git-based deploy) replaces __DIR__'s contents from the
    // git repo on every push. crm-config.php and crm-data/ are gitignored and must never
    // live inside that deployed tree, or every push silently wipes secrets and customer data.
    // Store them in a sibling directory the deploy pipeline never touches, falling back to
    // __DIR__ for local/other environments where that sibling doesn't exist.
    $external = dirname(__DIR__) . '/crm-secure';
    return is_dir($external) ? $external : __DIR__;
}

function crm_config(): array
{
    $configPath = crm_persistent_root() . '/crm-config.php';
    if (!is_file($configPath)) return [];
    $config = include $configPath;
    return is_array($config) ? $config : [];
}

function crm_available_features(): array
{
    return ['view_leads', 'edit_leads', 'export_leads', 'manage_users', 'manage_invoices'];
}

function crm_all_features(): array
{
    return crm_available_features();
}

function crm_data_directory(): string
{
    return crm_persistent_root() . '/crm-data';
}

function crm_data_file(): string
{
    return crm_data_directory() . '/leads.json';
}

function crm_read_json_file(string $file): array
{
    $lock = @fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_SH)) {
        if ($lock) fclose($lock);
        return [];
    }
    $result = [];
    foreach ([$file, $file . '.bak'] as $candidate) {
        if (!is_file($candidate)) continue;
        $contents = @file_get_contents($candidate);
        $decoded = json_decode($contents ?: '', true);
        if (is_array($decoded)) {
            $result = $decoded;
            break;
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

function crm_write_json_file(string $file, array $value): bool
{
    if (!crm_prepare_data_directory()) return false;
    $lock = @fopen($file . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if ($lock) fclose($lock);
        return false;
    }
    $existingContents = is_file($file) ? @file_get_contents($file) : '';
    if ($existingContents !== '' && is_array(json_decode($existingContents, true))) {
        @copy($file, $file . '.bak');
        @chmod($file . '.bak', 0600);
    }
    $json = json_encode(array_values($value), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return false;
    }
    $temporaryFile = $file . '.tmp';
    $written = @file_put_contents($temporaryFile, $json, LOCK_EX);
    if ($written === false || $written !== strlen($json)) {
        @unlink($temporaryFile);
        flock($lock, LOCK_UN);
        fclose($lock);
        return false;
    }
    @chmod($temporaryFile, 0600);
    $renamed = @rename($temporaryFile, $file);
    if ($renamed) @chmod($file, 0600);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $renamed;
}

function crm_invoices_file(): string
{
    return crm_data_directory() . '/invoices.json';
}

function crm_users_file(): string
{
    return crm_data_directory() . '/users.json';
}

function crm_prepare_data_directory(): bool
{
    $directory = crm_data_directory();
    if (!is_dir($directory) && !mkdir($directory, 0750, true)) return false;
    $accessFile = $directory . '/.htaccess';
    if (!is_file($accessFile)) @file_put_contents($accessFile, "Require all denied\nDeny from all\n");
    return true;
}

function crm_read_leads(): array
{
    return crm_read_json_file(crm_data_file());
}

function crm_write_leads(array $leads): bool
{
    return crm_write_json_file(crm_data_file(), $leads);
}

function crm_read_invoices(): array
{
    return crm_read_json_file(crm_invoices_file());
}

function crm_write_invoices(array $invoices): bool
{
    return crm_write_json_file(crm_invoices_file(), $invoices);
}

function crm_read_users(): array
{
    $file = crm_users_file();
    if (is_file($file)) {
        $contents = @file_get_contents($file);
        $users = json_decode($contents ?: '[]', true);
        if (is_array($users)) return $users;
    }

    // Migrate the original single-user configuration into a hashed admin account.
    $config = crm_config();
    $email = strtolower(trim((string) ($config['crm_email'] ?? $config['admin_email'] ?? getenv('SANCTUARY_CRM_EMAIL'))));
    $password = (string) ($config['crm_password'] ?? getenv('SANCTUARY_CRM_PASSWORD'));
    if ($email === '' || $password === '') return [];
    $passwordHash = preg_match('/^\$(2y|2b|argon2)/', $password) ? $password : password_hash($password, PASSWORD_DEFAULT);
    $users = [[
        'id' => 'user_admin',
        'name' => 'Sanctuary Shine Admin',
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'admin',
        'active' => true,
        'features' => crm_all_features(),
        'created_at' => gmdate('c'),
        'last_login_at' => '',
    ]];
    crm_write_users($users);
    return $users;
}

function crm_write_users(array $users): bool
{
    if (!crm_prepare_data_directory()) return false;
    $file = crm_users_file();
    $handle = @fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        return false;
    }
    $json = json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    rewind($handle);
    ftruncate($handle, 0);
    $written = $json !== false && fwrite($handle, $json) !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0600);
    return $written;
}

function crm_public_user(array $user): array
{
    $features = ($user['role'] ?? '') === 'admin'
        ? crm_all_features()
        : array_values(array_intersect((array) ($user['features'] ?? []), crm_available_features()));
    return [
        'id' => (string) ($user['id'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? 'staff'),
        'active' => (bool) ($user['active'] ?? false),
        'features' => $features,
        'created_at' => (string) ($user['created_at'] ?? ''),
        'last_login_at' => (string) ($user['last_login_at'] ?? ''),
    ];
}

function crm_current_user(): ?array
{
    if (empty($_SESSION['sanctuary_crm_authenticated'])) return null;
    $users = crm_read_users();
    $userId = (string) ($_SESSION['sanctuary_crm_user_id'] ?? '');
    foreach ($users as $user) {
        if (($user['id'] ?? '') === $userId && !empty($user['active'])) return $user;
    }
    // Keep existing authenticated sessions usable after the multi-user upgrade.
    foreach ($users as $user) {
        if (!empty($user['active']) && (($user['role'] ?? '') === 'admin')) {
            $_SESSION['sanctuary_crm_user_id'] = $user['id'];
            return $user;
        }
    }
    return null;
}

function crm_require_auth(string $feature = ''): array
{
    $user = crm_current_user();
    if ($user === null) crm_response(['status' => 'error', 'message' => 'Authentication required.'], 401);
    if ($feature !== '' && ($user['role'] ?? '') !== 'admin' && !in_array($feature, (array) ($user['features'] ?? []), true)) {
        crm_response(['status' => 'error', 'message' => 'You do not have access to this feature.'], 403);
    }
    return $user;
}

function crm_requested_features(): array
{
    $features = $_POST['features'] ?? [];
    if (!is_array($features)) $features = $features === '' ? [] : explode(',', (string) $features);
    return array_values(array_intersect(array_map('strval', $features), crm_available_features()));
}

function crm_password_is_valid(string $password): bool
{
    return strlen($password) >= 10 && strlen($password) <= 200;
}

function crm_log_email_event(string $emailType, string $provider, array $fields = []): void
{
    $parts = ['time=' . gmdate('c'), 'type=' . $emailType, 'provider=' . $provider];
    foreach ($fields as $key => $value) {
        $safeValue = str_replace(["\r", "\n"], ' ', (string) $value);
        $parts[] = $key . '=' . $safeValue;
    }
    @file_put_contents(crm_data_directory() . '/email.log', implode(' ', $parts) . "\n", FILE_APPEND);
}

function crm_send_reset_email(array $user, string $token, array $config): bool
{
    $to = (string) ($user['email'] ?? '');
    if ($to === '') return false;

    $fromEmail = (string) ($config['transactional_from_email'] ?? $config['from_email'] ?? 'contact@sanctuaryshine.co.uk');
    $fromName = (string) ($config['from_name'] ?? 'Sanctuary Shine');
    $resetUrl = 'https://sanctuaryshine.co.uk/crm/?reset_token=' . urlencode($token);
    $subject = 'Reset your Sanctuary Shine CRM password';
    $text = "Hi " . (string) ($user['name'] ?? '') . ",\n\n"
        . "We received a request to reset your Sanctuary Shine CRM password. This link expires in 1 hour:\n\n"
        . "{$resetUrl}\n\n"
        . "If you did not request this, you can ignore this email and your password will stay the same.\n\n"
        . "Sanctuary Shine\n";

    $apiKey = trim((string) ($config['emailit_api_key'] ?? ''));
    if ($apiKey === '') {
        crm_log_email_event('password_reset', 'emailit', ['to' => $to, 'status' => 'skipped', 'error' => 'api_key_not_configured']);
    } elseif (!function_exists('curl_init')) {
        crm_log_email_event('password_reset', 'emailit', ['to' => $to, 'status' => 'skipped', 'error' => 'curl_unavailable']);
    } else {
        $curl = curl_init('https://api.emailit.com/v2/emails');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Idempotency-Key: reset-' . hash('sha256', $token),
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'from' => $fromName . ' <' . $fromEmail . '>',
                'to' => [$to],
                'subject' => $subject,
                'text' => $text,
            ]),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $decoded = $response !== false ? json_decode($response, true) : null;
        $messageId = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
        $providerStatus = is_array($decoded) ? (string) ($decoded['status'] ?? '') : '';

        if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
            crm_log_email_event('password_reset', 'emailit', [
                'to' => $to,
                'status' => 'sent',
                'http_code' => $httpCode,
                'message_id' => $messageId !== '' ? $messageId : 'unknown',
                'provider_status' => $providerStatus !== '' ? $providerStatus : 'unknown',
            ]);
            return true;
        }

        $safeError = $curlError !== '' ? $curlError : (is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'unrecognized_response') : 'unrecognized_response');
        crm_log_email_event('password_reset', 'emailit', [
            'to' => $to,
            'status' => 'failed',
            'http_code' => $httpCode,
            'error' => substr($safeError, 0, 200),
        ]);
    }

    if (function_exists('mail')) {
        $headers = ['From: ' . $fromName . ' <' . $fromEmail . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
        $sent = @mail($to, $subject, $text, implode("\r\n", $headers));
        crm_log_email_event('password_reset', 'php_mail', ['to' => $to, 'status' => $sent ? 'sent' : 'failed']);
        return $sent;
    }

    crm_log_email_event('password_reset', 'none', ['to' => $to, 'status' => 'failed', 'error' => 'no_transport_available']);
    return false;
}

function crm_normalize_invoice(array $invoice): array
{
    $customerSource = is_array($invoice['customer'] ?? null) ? $invoice['customer'] : [];
    $itemsSource = is_array($invoice['line_items'] ?? null) ? $invoice['line_items'] : [];
    $items = [];
    foreach ($itemsSource as $item) {
        if (!is_array($item)) continue;
        $description = crm_clean_value($item['description'] ?? '', 240);
        $quantity = is_numeric($item['quantity'] ?? null) ? max(0, min(999, (float) $item['quantity'])) : 0;
        $unitPrice = is_numeric($item['unit_price'] ?? null) ? max(0, min(1000000, (float) $item['unit_price'])) : 0;
        if ($description === '' || $quantity <= 0) continue;
        $items[] = [
            'description' => $description,
            'quantity' => round($quantity, 2),
            'unit_price' => round($unitPrice, 2),
            'amount' => round($quantity * $unitPrice, 2),
        ];
    }

    $subtotal = round(array_sum(array_column($items, 'amount')), 2);
    $paymentLink = crm_clean_value($invoice['payment_link'] ?? '', 1000);
    if ($paymentLink !== '' && !preg_match('/^https?:\/\//i', $paymentLink)) $paymentLink = '';
    $id = crm_clean_value($invoice['id'] ?? '', 100);
    return [
        'id' => $id,
        'invoice_number' => crm_clean_value($invoice['invoice_number'] ?? '', 80),
        'issue_date' => crm_clean_value($invoice['issue_date'] ?? '', 30),
        'due_date' => crm_clean_value($invoice['due_date'] ?? '', 30),
        'service_date' => crm_clean_value($invoice['service_date'] ?? '', 30),
        'customer' => [
            'name' => crm_clean_value($customerSource['name'] ?? '', 160),
            'email' => strtolower(crm_clean_value($customerSource['email'] ?? '', 240)),
            'phone' => crm_clean_value($customerSource['phone'] ?? '', 60),
            'address' => crm_clean_value($customerSource['address'] ?? '', 300),
            'postcode' => crm_clean_value($customerSource['postcode'] ?? '', 30),
        ],
        'line_items' => $items,
        'subtotal' => $subtotal,
        'payment_link' => $paymentLink,
        'notes' => crm_clean_value($invoice['notes'] ?? '', 3000),
        'status' => in_array(($invoice['status'] ?? ''), ['draft', 'sent', 'paid'], true) ? $invoice['status'] : 'draft',
        'created_at' => crm_clean_value($invoice['created_at'] ?? '', 40),
        'updated_at' => gmdate('c'),
        'sent_at' => crm_clean_value($invoice['sent_at'] ?? '', 40),
        'email_status' => crm_clean_value($invoice['email_status'] ?? '', 80),
    ];
}

function crm_clean_value($value, int $maxLength = 500): string
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    return substr(strip_tags($value), 0, $maxLength);
}

function crm_invoice_is_valid(array $invoice): bool
{
    return $invoice['invoice_number'] !== ''
        && $invoice['customer']['name'] !== ''
        && filter_var($invoice['customer']['email'], FILTER_VALIDATE_EMAIL)
        && count($invoice['line_items']) > 0;
}

function crm_pdf_escape(string $value): string
{
    $value = preg_replace('/[^\x20-\x7E\r\n]/', '', $value) ?? '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
}

function crm_pdf_text(string &$stream, float $x, float $y, string $text, float $size = 10, string $color = '0.10 0.11 0.11'): void
{
    $stream .= sprintf("BT /F1 %.2f Tf %s rg %.2f %.2f Td (%s) Tj ET\n", $size, $color, $x, $y, crm_pdf_escape($text));
}

function crm_pdf_lines(string &$stream, float $x, float &$y, string $text, int $limit = 58, float $size = 9, string $color = '0.24 0.29 0.30', float $leading = 13): void
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($text === '') return;
    foreach (explode("\n", wordwrap($text, $limit, "\n", true)) as $line) {
        crm_pdf_text($stream, $x, $y, $line, $size, $color);
        $y -= $leading;
    }
}

function crm_generate_invoice_pdf(array $invoice): string
{
    $stream = "q 0.00 0.76 0.82 rg 45 700 505 4 re f Q\n";
    $logoPath = __DIR__ . '/images/logo/logo-invoice.jpg';
    $imageData = is_file($logoPath) ? @file_get_contents($logoPath) : false;
    $imageSize = $imageData !== false ? @getimagesize($logoPath) : false;
    $hasLogo = is_string($imageData) && $imageData !== '' && is_array($imageSize);
    if ($hasLogo) $stream .= "q 72 0 0 72 45 730 cm /Im1 Do Q\n";

    crm_pdf_text($stream, 130, 778, 'SANCTUARY SHINE LTD', 19, '0.04 0.05 0.05');
    crm_pdf_text($stream, 130, 760, 'Professional cleaning services', 9, '0.42 0.47 0.48');
    crm_pdf_text($stream, 400, 778, 'INVOICE', 10, '0.00 0.55 0.60');
    crm_pdf_text($stream, 400, 755, (string) ($invoice['invoice_number'] ?? 'Draft invoice'), 18, '0.04 0.05 0.05');
    crm_pdf_text($stream, 400, 738, 'Issued ' . (string) ($invoice['issue_date'] ?? 'Not set'), 9, '0.42 0.47 0.48');
    crm_pdf_text($stream, 400, 724, 'Due ' . (string) ($invoice['due_date'] ?? 'Not set'), 9, '0.42 0.47 0.48');

    crm_pdf_text($stream, 45, 680, 'FROM', 8, '0.00 0.55 0.60');
    crm_pdf_text($stream, 45, 663, 'Sanctuary Shine Ltd', 11, '0.04 0.05 0.05');
    $fromY = 646;
    crm_pdf_lines($stream, 45, $fromY, '13 Moorsholme Ave, Manchester, M40 9BW', 42);
    crm_pdf_lines($stream, 45, $fromY, 'Company no. 08040169 | contact@sanctuaryshine.co.uk', 48);

    crm_pdf_text($stream, 310, 680, 'BILL TO', 8, '0.00 0.55 0.60');
    crm_pdf_text($stream, 310, 663, (string) ($invoice['customer']['name'] ?? 'Customer'), 11, '0.04 0.05 0.05');
    $billY = 646;
    crm_pdf_lines($stream, 310, $billY, (string) ($invoice['customer']['email'] ?? ''), 38);
    crm_pdf_lines($stream, 310, $billY, (string) ($invoice['customer']['phone'] ?? ''), 38);
    crm_pdf_lines($stream, 310, $billY, trim((string) ($invoice['customer']['address'] ?? '') . ' ' . (string) ($invoice['customer']['postcode'] ?? '')), 38);

    $tableTop = min($fromY, $billY) - 20;
    $stream .= "q 0.95 0.96 0.96 rg 45 " . ($tableTop - 18) . " 505 22 re f Q\n";
    crm_pdf_text($stream, 53, $tableTop - 11, 'DESCRIPTION', 8, '0.24 0.29 0.30');
    crm_pdf_text($stream, 380, $tableTop - 11, 'QTY', 8, '0.24 0.29 0.30');
    crm_pdf_text($stream, 425, $tableTop - 11, 'RATE', 8, '0.24 0.29 0.30');
    crm_pdf_text($stream, 500, $tableTop - 11, 'AMOUNT', 8, '0.24 0.29 0.30');
    $rowY = $tableTop - 40;
    foreach (($invoice['line_items'] ?? []) as $item) {
        $descriptionLines = explode("\n", wordwrap((string) ($item['description'] ?? ''), 43, "\n", true));
        foreach ($descriptionLines as $lineIndex => $line) {
            crm_pdf_text($stream, 53, $rowY, $line, 9);
            if ($lineIndex === 0) {
                crm_pdf_text($stream, 380, $rowY, rtrim(rtrim(number_format((float) ($item['quantity'] ?? 0), 2, '.', ''), '0'), '.'), 9);
                crm_pdf_text($stream, 425, $rowY, 'GBP ' . number_format((float) ($item['unit_price'] ?? 0), 2), 9);
                crm_pdf_text($stream, 500, $rowY, 'GBP ' . number_format((float) ($item['amount'] ?? 0), 2), 9);
            }
            $rowY -= 13;
        }
        $stream .= "0.90 0.91 0.91 RG 45 " . ($rowY + 5) . " m 550 " . ($rowY + 5) . " l S\n";
        $rowY -= 10;
    }
    $stream .= "0.00 0.76 0.82 RG 45 " . ($rowY + 4) . " m 550 " . ($rowY + 4) . " l S\n";
    crm_pdf_text($stream, 405, $rowY - 18, 'TOTAL DUE', 10, '0.04 0.05 0.05');
    crm_pdf_text($stream, 490, $rowY - 18, 'GBP ' . number_format((float) ($invoice['subtotal'] ?? 0), 2), 13, '0.00 0.55 0.60');

    $paymentTop = $rowY - 64;
    $stream .= "q 0.97 0.98 0.98 rg 45 " . ($paymentTop - 70) . " 505 70 re f Q\n";
    crm_pdf_text($stream, 57, $paymentTop - 18, 'PAYMENT DETAILS', 8, '0.00 0.55 0.60');
    crm_pdf_text($stream, 57, $paymentTop - 35, 'SANCTUARY SHINE LTD | Sort code 04-00-06', 9);
    crm_pdf_text($stream, 57, $paymentTop - 50, 'IBAN GB19 MONZ 0400 0608 0401 69 | Company no. 08040169', 9);
    if (($invoice['payment_link'] ?? '') !== '') crm_pdf_text($stream, 57, $paymentTop - 65, 'Online payment: ' . (string) $invoice['payment_link'], 8, '0.00 0.55 0.60');
    if (($invoice['notes'] ?? '') !== '') {
        $noteY = $paymentTop - 92;
        crm_pdf_lines($stream, 45, $noteY, 'Note: ' . (string) $invoice['notes'], 80, 9);
    }
    crm_pdf_text($stream, 195, 62, 'Thank you for choosing Sanctuary Shine.', 9, '0.42 0.47 0.48');

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[5] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    $xObject = '';
    if ($hasLogo) {
        $objects[6] = '<< /Type /XObject /Subtype /Image /Width ' . (int) $imageSize[0] . ' /Height ' . (int) $imageSize[1] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($imageData) . " >>\nstream\n" . $imageData . "\nendstream";
        $xObject = ' /XObject << /Im1 6 0 R >>';
    }
    $objects[3] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 5 0 R /Resources << /Font << /F1 4 0 R >>' . $xObject . ' >> >>';

    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $maxObject = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxObject + 1) . "\n0000000000 65535 f \n";
    for ($number = 1; $number <= $maxObject; $number++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
    $pdf .= "trailer\n<< /Size " . ($maxObject + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function crm_send_invoice_email(array $invoice, array $config): bool
{
    $customer = $invoice['customer'] ?? [];
    $to = (string) ($customer['email'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $fromEmail = (string) ($config['transactional_from_email'] ?? $config['from_email'] ?? 'contact@sanctuaryshine.co.uk');
    $fromName = (string) ($config['from_name'] ?? 'Sanctuary Shine');
    $lines = [];
    foreach (($invoice['line_items'] ?? []) as $item) {
        $lines[] = sprintf('%s x %s - £%s', $item['quantity'], $item['description'], number_format((float) $item['amount'], 2));
    }
    $text = "Hi " . (string) ($customer['name'] ?? 'there') . ",\n\n"
        . "Please find your Sanctuary Shine invoice " . (string) ($invoice['invoice_number'] ?? '') . ".\n\n"
        . implode("\n", $lines) . "\n\n"
        . "Total due: £" . number_format((float) ($invoice['subtotal'] ?? 0), 2) . "\n"
        . "Due date: " . (string) ($invoice['due_date'] ?? '') . "\n\n"
        . "Payment details:\n"
        . "Account name: SANCTUARY SHINE LTD\n"
        . "Company number: 08040169\n"
        . "Sort code: 04-00-06\n"
        . "IBAN: GB19 MONZ 0400 0608 0401 69\n";
    if (($invoice['payment_link'] ?? '') !== '') $text .= "\nPay online: " . $invoice['payment_link'] . "\n";
    if (($invoice['notes'] ?? '') !== '') $text .= "\nNotes: " . $invoice['notes'] . "\n";
    $text .= "\nThank you,\nSanctuary Shine\n";
    $subject = 'Invoice ' . (string) ($invoice['invoice_number'] ?? '') . ' from Sanctuary Shine';

    $pdf = crm_generate_invoice_pdf($invoice);
    $filename = 'sanctuary-shine-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($invoice['invoice_number'] ?? 'invoice')) . '.pdf';
    $attachment = ['filename' => $filename, 'content' => base64_encode($pdf), 'content_type' => 'application/pdf'];
    $apiKey = trim((string) ($config['emailit_api_key'] ?? ''));
    if ($apiKey === '') $apiKey = trim((string) (getenv('EMAILIT_API_KEY') ?: ''));
    if ($apiKey !== '' && function_exists('curl_init')) {
        $curl = curl_init('https://api.emailit.com/v2/emails');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $apiKey, 'Idempotency-Key: invoice-' . hash('sha256', ($invoice['id'] ?? '') . $to)],
            CURLOPT_POSTFIELDS => json_encode(['from' => $fromName . ' <' . $fromEmail . '>', 'to' => [$to], 'subject' => $subject, 'text' => $text, 'attachments' => [$attachment]]),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($response !== false && $httpCode >= 200 && $httpCode < 300) return true;
    }

    if (function_exists('mail')) {
        $boundary = '=_SanctuaryShine_' . bin2hex(random_bytes(12));
        $headers = ['From: ' . $fromName . ' <' . $fromEmail . '>', 'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="' . $boundary . '"'];
        $body = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $text . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\nContent-Type: application/pdf; name=\"" . $filename . "\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"" . $filename . "\"\r\n\r\n" . chunk_split(base64_encode($pdf)) . "\r\n--" . $boundary . "--\r\n";
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }
    return false;
}

function crm_post_value(string $key, int $maxLength = 500): string
{
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    $value = strip_tags($value);
    return substr($value, 0, $maxLength);
}

function crm_clean_field(string $key, int $maxLength = 500): string
{
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    $value = strip_tags($value);
    $value = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;
    return substr($value, 0, $maxLength);
}

$config = crm_config();
$action = isset($_GET['action']) ? (string) $_GET['action'] : (string) ($_POST['action'] ?? '');

if ($action === 'login') {
    $providedEmail = strtolower(crm_post_value('email', 240));
    $providedPassword = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $matchedUser = null;
    foreach (crm_read_users() as $user) {
        if (!empty($user['active']) && strtolower((string) ($user['email'] ?? '')) === $providedEmail && password_verify($providedPassword, (string) ($user['password_hash'] ?? ''))) {
            $matchedUser = $user;
            break;
        }
    }
    if ($matchedUser === null) {
        crm_response(['status' => 'error', 'message' => 'Incorrect email or password.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['sanctuary_crm_authenticated'] = true;
    $_SESSION['sanctuary_crm_user_id'] = $matchedUser['id'];
    $users = crm_read_users();
    foreach ($users as &$user) {
        if (($user['id'] ?? '') === $matchedUser['id']) $user['last_login_at'] = gmdate('c');
    }
    unset($user);
    crm_write_users($users);
    crm_response(['status' => 'success', 'user' => crm_public_user($matchedUser)]);
}

if ($action === 'session') {
    $sessionUser = crm_current_user();
    crm_response(['status' => 'success', 'authenticated' => $sessionUser !== null, 'user' => $sessionUser ? crm_public_user($sessionUser) : null]);
}

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    crm_response(['status' => 'success']);
}

if ($action === 'request_password_reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(crm_post_value('email', 240));
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $users = crm_read_users();
        foreach ($users as &$user) {
            if (!empty($user['active']) && strtolower((string) ($user['email'] ?? '')) === $email) {
                $token = bin2hex(random_bytes(32));
                $user['reset_token_hash'] = hash('sha256', $token);
                $user['reset_token_expires_at'] = gmdate('c', time() + 3600);
                if (!crm_write_users($users)) {
                    crm_response(['status' => 'error', 'message' => 'The reset request could not be saved. Please try again shortly.'], 500);
                }
                crm_send_reset_email($user, $token, $config);
                break;
            }
        }
        unset($user);
    }
    // Same response whether or not the email matched, so accounts can't be enumerated.
    crm_response(['status' => 'success', 'message' => 'If that email has a CRM account, a reset link has been sent.']);
}

if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
    $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    if ($token === '') crm_response(['status' => 'error', 'message' => 'Reset link is invalid or has expired.'], 400);
    if (!crm_password_is_valid($newPassword)) crm_response(['status' => 'error', 'message' => 'New passwords must be 10 to 200 characters.'], 400);

    $tokenHash = hash('sha256', $token);
    $users = crm_read_users();
    $found = false;
    foreach ($users as &$user) {
        $storedHash = (string) ($user['reset_token_hash'] ?? '');
        if ($storedHash === '' || !hash_equals($storedHash, $tokenHash)) continue;
        $expiresAt = (string) ($user['reset_token_expires_at'] ?? '');
        if ($expiresAt === '' || strtotime($expiresAt) < time()) break;
        $user['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        unset($user['reset_token_hash'], $user['reset_token_expires_at']);
        $found = true;
        break;
    }
    unset($user);
    if (!$found) crm_response(['status' => 'error', 'message' => 'Reset link is invalid or has expired.'], 400);
    if (!crm_write_users($users)) crm_response(['status' => 'error', 'message' => 'Password could not be saved.'], 500);
    crm_response(['status' => 'success', 'message' => 'Your password has been reset. You can now sign in.']);
}

$currentUser = crm_require_auth();

if ($action === 'me') {
    crm_response(['status' => 'success', 'user' => crm_public_user($currentUser)]);
}

if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    if (!password_verify($currentPassword, (string) ($currentUser['password_hash'] ?? ''))) {
        crm_response(['status' => 'error', 'message' => 'Current password is incorrect.'], 400);
    }
    if (!crm_password_is_valid($newPassword)) {
        crm_response(['status' => 'error', 'message' => 'New passwords must be 10 to 200 characters.'], 400);
    }
    $users = crm_read_users();
    foreach ($users as &$user) {
        if (($user['id'] ?? '') === $currentUser['id']) $user['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    unset($user);
    if (!crm_write_users($users)) crm_response(['status' => 'error', 'message' => 'Password could not be saved.'], 500);
    crm_response(['status' => 'success', 'message' => 'Your password has been changed.']);
}

if ($action === 'users') {
    crm_require_auth('manage_users');
    crm_response(['status' => 'success', 'users' => array_map('crm_public_user', crm_read_users()), 'features' => crm_available_features()]);
}

if ($action === 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_auth('manage_users');
    $name = crm_post_value('name', 120);
    $email = strtolower(crm_post_value('email', 240));
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $features = crm_requested_features();
    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) crm_response(['status' => 'error', 'message' => 'A name and valid email are required.'], 400);
    if (!crm_password_is_valid($password)) crm_response(['status' => 'error', 'message' => 'Passwords must be 10 to 200 characters.'], 400);
    if (!$features) $features = ['view_leads'];
    $users = crm_read_users();
    foreach ($users as $user) {
        if (strtolower((string) ($user['email'] ?? '')) === $email) crm_response(['status' => 'error', 'message' => 'That email is already a CRM user.'], 409);
    }
    $newUser = [
        'id' => 'user_' . bin2hex(random_bytes(8)),
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'staff',
        'active' => true,
        'features' => $features,
        'created_at' => gmdate('c'),
        'last_login_at' => '',
    ];
    $users[] = $newUser;
    if (!crm_write_users($users)) crm_response(['status' => 'error', 'message' => 'User could not be created.'], 500);
    crm_response(['status' => 'success', 'user' => crm_public_user($newUser)]);
}

if ($action === 'update_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_auth('manage_users');
    $id = crm_post_value('user_id', 100);
    $name = crm_post_value('name', 120);
    $email = strtolower(crm_post_value('email', 240));
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $features = crm_requested_features();
    $active = filter_var($_POST['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($id === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) crm_response(['status' => 'error', 'message' => 'User name and valid email are required.'], 400);
    if ($id === $currentUser['id'] && !$active) crm_response(['status' => 'error', 'message' => 'You cannot deactivate your own account.'], 400);
    if ($password !== '' && !crm_password_is_valid($password)) crm_response(['status' => 'error', 'message' => 'Passwords must be 10 to 200 characters.'], 400);
    if (!$features) $features = ['view_leads'];
    $users = crm_read_users();
    foreach ($users as $user) {
        if (($user['id'] ?? '') !== $id && strtolower((string) ($user['email'] ?? '')) === $email) crm_response(['status' => 'error', 'message' => 'That email is already a CRM user.'], 409);
    }
    $found = false;
    foreach ($users as &$user) {
        if (($user['id'] ?? '') !== $id) continue;
        $user['name'] = $name;
        $user['email'] = $email;
        $user['active'] = $active;
        $user['features'] = $features;
        if ($password !== '') $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $found = true;
        break;
    }
    unset($user);
    if (!$found || !crm_write_users($users)) crm_response(['status' => 'error', 'message' => 'User could not be updated.'], 404);
    crm_response(['status' => 'success']);
}

if ($action === 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_auth('manage_users');
    $id = crm_post_value('user_id', 100);
    if ($id === '' || $id === $currentUser['id']) crm_response(['status' => 'error', 'message' => 'You cannot remove your own account.'], 400);
    $users = crm_read_users();
    $filteredUsers = array_values(array_filter($users, static fn(array $user): bool => ($user['id'] ?? '') !== $id));
    if (count($filteredUsers) === count($users) || !crm_write_users($filteredUsers)) crm_response(['status' => 'error', 'message' => 'User could not be removed.'], 404);
    crm_response(['status' => 'success']);
}

if ($action === 'list_invoices') {
    crm_require_auth('manage_invoices');
    $invoices = crm_read_invoices();
    usort($invoices, static function (array $a, array $b): int {
        return strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? ''));
    });
    crm_response(['status' => 'success', 'invoices' => $invoices]);
}

if (($action === 'save_invoice' || $action === 'send_invoice') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentUser = crm_require_auth('manage_invoices');
    $invoiceJson = isset($_POST['invoice_json']) && is_string($_POST['invoice_json']) ? $_POST['invoice_json'] : '';
    $decodedInvoice = json_decode($invoiceJson, true);
    if (!is_array($decodedInvoice)) crm_response(['status' => 'error', 'message' => 'Invoice details could not be read.'], 400);

    $invoice = crm_normalize_invoice($decodedInvoice);
    if ($invoice['invoice_number'] === '' || $invoice['customer']['name'] === '' || !filter_var($invoice['customer']['email'], FILTER_VALIDATE_EMAIL) || count($invoice['line_items']) === 0) {
        crm_response(['status' => 'error', 'message' => 'Invoice number, customer name, valid email and at least one line item are required.'], 400);
    }

    $invoices = crm_read_invoices();
    $existingIndex = -1;
    foreach ($invoices as $index => $savedInvoice) {
        if (($savedInvoice['id'] ?? '') !== '' && ($savedInvoice['id'] ?? '') === $invoice['id']) {
            $existingIndex = $index;
            break;
        }
    }
    if ($invoice['id'] === '' || $existingIndex < 0) {
        $invoice['id'] = 'inv_' . bin2hex(random_bytes(8));
        $invoice['created_at'] = gmdate('c');
        $invoices[] = $invoice;
        $existingIndex = count($invoices) - 1;
    } else {
        $invoice['created_at'] = (string) ($invoices[$existingIndex]['created_at'] ?? gmdate('c'));
        $invoice['sent_at'] = (string) ($invoices[$existingIndex]['sent_at'] ?? $invoice['sent_at']);
        $invoices[$existingIndex] = $invoice;
    }

    if ($action === 'send_invoice') {
        if (!crm_send_invoice_email($invoice, $config)) {
            $invoice['email_status'] = 'failed';
            $invoice['status'] = 'draft';
            $invoices[$existingIndex] = $invoice;
            crm_write_invoices($invoices);
            crm_response(['status' => 'error', 'message' => 'The invoice was saved, but email delivery is not configured or failed. Use the email button on your device as a fallback.'], 502);
        }
        $invoice['email_status'] = 'sent';
        $invoice['status'] = 'sent';
        $invoice['sent_at'] = gmdate('c');
        $invoices[$existingIndex] = $invoice;
    }

    if (!crm_write_invoices($invoices)) crm_response(['status' => 'error', 'message' => 'Invoice could not be saved.'], 500);
    crm_response(['status' => 'success', 'invoice' => $invoice, 'message' => $action === 'send_invoice' ? 'Invoice sent to the customer.' : 'Invoice saved.']);
}

if ($action === 'list') {
    crm_require_auth('view_leads');
    $leads = crm_read_leads();
    if (is_file(crm_data_file() . '.bak')) {
        $backupLeads = crm_read_json_file(crm_data_file() . '.bak');
        $knownIds = array_fill_keys(array_map(static fn(array $lead): string => (string) ($lead['id'] ?? ''), $leads), true);
        foreach ($backupLeads as $backupLead) {
            $backupId = (string) ($backupLead['id'] ?? '');
            if ($backupId !== '' && !isset($knownIds[$backupId])) {
                $leads[] = $backupLead;
                $knownIds[$backupId] = true;
            }
        }
    }
    usort($leads, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });
    crm_response(['status' => 'success', 'leads' => $leads]);
}

if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_auth('edit_leads');
    $id = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    $status = crm_clean_field('status', 30);
    $allowedStatuses = ['new', 'contacted', 'quoted', 'booked', 'completed', 'closed'];
    if ($id === '' || !in_array($status, $allowedStatuses, true)) {
        crm_response(['status' => 'error', 'message' => 'Invalid lead update.'], 400);
    }

    $editableFields = [
        'name' => 120,
        'email' => 240,
        'phone' => 60,
        'postcode' => 30,
        'subject' => 120,
        'cleaning_type' => 120,
        'property_type' => 120,
        'size' => 30,
        'preferred_date' => 30,
        'message' => 10000,
        'notes' => 2000,
    ];
    $updates = [];
    foreach ($editableFields as $field => $maxLength) {
        if (array_key_exists($field, $_POST)) $updates[$field] = crm_clean_field($field, $maxLength);
    }
    if (isset($updates['name']) && $updates['name'] === '') {
        crm_response(['status' => 'error', 'message' => 'Name is required.'], 400);
    }
    if (isset($updates['email']) && ($updates['email'] === '' || !filter_var($updates['email'], FILTER_VALIDATE_EMAIL))) {
        crm_response(['status' => 'error', 'message' => 'A valid email is required.'], 400);
    }

    $leads = crm_read_leads();
    $found = false;
    foreach ($leads as &$lead) {
        if (($lead['id'] ?? '') === $id) {
            $lead['status'] = $status;
            foreach ($updates as $field => $value) $lead[$field] = $value;
            $lead['updated_at'] = gmdate('c');
            $found = true;
            break;
        }
    }
    unset($lead);
    if (!$found || !crm_write_leads($leads)) crm_response(['status' => 'error', 'message' => 'Lead could not be updated.'], 404);
    crm_response(['status' => 'success']);
}

if ($action === 'export') {
    crm_require_auth('export_leads');
    $leads = crm_read_leads();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sanctuary-shine-crm.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Type', 'Status', 'Created', 'Name', 'Email', 'Phone', 'Postcode', 'Subject', 'Cleaning type', 'Property type', 'Size', 'Preferred date', 'Message', 'Notes', 'Email status']);
    foreach ($leads as $lead) {
        fputcsv($output, [
            $lead['id'] ?? '', $lead['type'] ?? '', $lead['status'] ?? '', $lead['created_at'] ?? '',
            $lead['name'] ?? '', $lead['email'] ?? '', $lead['phone'] ?? '', $lead['postcode'] ?? '',
            $lead['subject'] ?? '', $lead['cleaning_type'] ?? '', $lead['property_type'] ?? '',
            $lead['size'] ?? '', $lead['preferred_date'] ?? '', $lead['message'] ?? '',
            $lead['notes'] ?? '', $lead['email_status'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}

crm_response(['status' => 'error', 'message' => 'Unknown CRM action.'], 400);
?>
