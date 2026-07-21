<?php
/**
 * Small authenticated CRM API for the static Sanctuary Shine site.
 * Data is stored in crm-data/leads.json and never returned without a session.
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

function crm_config(): array
{
    $configPath = __DIR__ . '/crm-config.php';
    if (!is_file($configPath)) return [];
    $config = include $configPath;
    return is_array($config) ? $config : [];
}

function crm_available_features(): array
{
    return ['view_leads', 'edit_leads', 'export_leads', 'manage_users'];
}

function crm_all_features(): array
{
    return crm_available_features();
}

function crm_data_directory(): string
{
    return __DIR__ . '/crm-data';
}

function crm_data_file(): string
{
    return crm_data_directory() . '/leads.json';
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
    $file = crm_data_file();
    if (!is_file($file)) return [];
    $contents = @file_get_contents($file);
    $leads = json_decode($contents ?: '[]', true);
    return is_array($leads) ? $leads : [];
}

function crm_write_leads(array $leads): bool
{
    if (!crm_prepare_data_directory()) return false;
    $file = crm_data_file();
    $handle = @fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        return false;
    }
    $json = json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    rewind($handle);
    ftruncate($handle, 0);
    $written = $json !== false && fwrite($handle, $json) !== false;
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    @chmod($file, 0600);
    return $written;
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
    return [
        'id' => (string) ($user['id'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? 'staff'),
        'active' => (bool) ($user['active'] ?? false),
        'features' => array_values(array_intersect((array) ($user['features'] ?? []), crm_available_features())),
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
    if ($feature !== '' && !in_array($feature, (array) ($user['features'] ?? []), true)) {
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
    if ($apiKey !== '' && function_exists('curl_init')) {
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
        curl_close($curl);
        if ($response !== false && $httpCode >= 200 && $httpCode < 300) return true;
    }

    if (function_exists('mail')) {
        $headers = ['From: ' . $fromName . ' <' . $fromEmail . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
        return @mail($to, $subject, $text, implode("\r\n", $headers));
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
                crm_write_users($users);
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

if ($action === 'list') {
    crm_require_auth('view_leads');
    $leads = crm_read_leads();
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
