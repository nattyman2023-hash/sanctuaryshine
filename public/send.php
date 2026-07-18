<?php
/**
 * Sanctuary Shine - Form Handler with EmailIt Integration
 * 
 * This script handles form submissions from the contact, quote, and chatbot forms.
 * It uses EmailIt API to send transactional emails to both the admin and the sender.
 * 
 * EmailIt API Key: secret_GT5hLDI6Tx0cczQk9bMRDsh3bQFho7q1
 * Admin Email: contact@sanctuaryshine.co.uk
 */

// EmailIt Configuration
$EMAILIT_API_KEY = "secret_GT5hLDI6Tx0cczQk9bMRDsh3bQFho7q1";
$EMAILIT_API_URL = "https://api.emailit.com/v1/emails";
$ADMIN_EMAIL = "contact@sanctuaryshine.co.uk";
$FROM_EMAIL = "noreply@sanctuaryshine.co.uk";
$FROM_NAME = "Sanctuary Shine Website";

// Get form data
$form_type = isset($_POST['form_type']) ? $_POST['form_type'] : 'contact';
$name = isset($_POST['name']) ? strip_tags(trim($_POST['name'])) : '';
$email = isset($_POST['email']) ? strip_tags(trim($_POST['email'])) : '';
$phone = isset($_POST['phone']) ? strip_tags(trim($_POST['phone'])) : '';
$postcode = isset($_POST['postcode']) ? strip_tags(trim($_POST['postcode'])) : '';
$message = isset($_POST['message']) ? strip_tags(trim($_POST['message'])) : '';

// Quote form specific fields
$cleaning_type = isset($_POST['cleaningType']) ? strip_tags(trim($_POST['cleaningType'])) : '';
$property_type = isset($_POST['propertyType']) ? strip_tags(trim($_POST['propertyType'])) : '';
$size = isset($_POST['size']) ? strip_tags(trim($_POST['size'])) : '';
$preferred_date = isset($_POST['preferredDate']) ? strip_tags(trim($_POST['preferredDate'])) : '';
$subject_field = isset($_POST['subject']) ? strip_tags(trim($_POST['subject'])) : '';

// Validation
$errors = [];
if (empty($name)) $errors[] = "Name is required";
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => implode(", ", $errors)]);
    exit;
}

// Determine form type label
$form_label = "Contact Form";
if ($form_type == 'quote') $form_label = "Quote Request";
if ($form_type == 'chatbot') $form_label = "Chatbot Enquiry";

// ============================================
// EMAIL 1: Admin Notification Email
// ============================================
$admin_subject = "New $form_label from $name";

$admin_body = "New enquiry from the Sanctuary Shine website.\n\n";
$admin_body .= "Form Type: $form_label\n";
$admin_body .= "Name: $name\n";
$admin_body .= "Email: $email\n";
$admin_body .= "Phone: $phone\n";
$admin_body .= "Postcode: $postcode\n";

if (!empty($cleaning_type)) $admin_body .= "Cleaning Type: $cleaning_type\n";
if (!empty($property_type)) $admin_body .= "Property Type: $property_type\n";
if (!empty($size)) $admin_body .= "Bedrooms/Offices: $size\n";
if (!empty($preferred_date)) $admin_body .= "Preferred Date: $preferred_date\n";
if (!empty($subject_field)) $admin_body .= "Subject: $subject_field\n";

$admin_body .= "\nMessage:\n$message\n";
$admin_body .= "\n---\n";
$admin_body .= "Submitted: " . date('Y-m-d H:i:s') . "\n";
$admin_body .= "IP: " . $_SERVER['REMOTE_ADDR'] . "\n";

$admin_email_data = [
    "from" => "$FROM_NAME <$FROM_EMAIL>",
    "to" => $ADMIN_EMAIL,
    "reply_to" => $email,
    "subject" => $admin_subject,
    "text" => $admin_body,
    "html" => nl2br(htmlspecialchars($admin_body))
];

// ============================================
// EMAIL 2: Sender Confirmation Email
// ============================================
$sender_subject = "Thank you for contacting Sanctuary Shine";

$sender_body = "Hi $name,\n\n";
$sender_body .= "Thank you for contacting Sanctuary Shine! We've received your enquiry and will get back to you within 2 hours during business hours.\n\n";
$sender_body .= "Here's a summary of your enquiry:\n";
$sender_body .= "- Name: $name\n";
$sender_body .= "- Email: $email\n";
if (!empty($phone)) $sender_body .= "- Phone: $phone\n";
if (!empty($postcode)) $sender_body .= "- Postcode: $postcode\n";
if (!empty($cleaning_type)) $sender_body .= "- Cleaning Type: $cleaning_type\n";
if (!empty($property_type)) $sender_body .= "- Property Type: $property_type\n";
if (!empty($preferred_date)) $sender_body .= "- Preferred Date: $preferred_date\n";
$sender_body .= "\nYour Message:\n$message\n\n";
$sender_body .= "Our team will be in touch shortly. If you need urgent assistance, please call us at 0161 123 4567.\n\n";
$sender_body .= "Best regards,\n";
$sender_body .= "The Sanctuary Shine Team\n";
$sender_body .= "contact@sanctuaryshine.co.uk\n";
$sender_body .= "0161 123 4567\n";
$sender_body .= "https://sanctuaryshine.co.uk\n";

$sender_email_data = [
    "from" => "$FROM_NAME <$FROM_EMAIL>",
    "to" => $email,
    "subject" => $sender_subject,
    "text" => $sender_body,
    "html" => nl2br(htmlspecialchars($sender_body))
];

// ============================================
// Send emails via EmailIt API
// ============================================
function sendEmailItEmail($api_key, $api_url, $email_data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $api_key"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($email_data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'success' => $http_code >= 200 && $http_code < 300,
        'http_code' => $http_code,
        'response' => $response,
        'error' => $error
    ];
}

// Send admin email
$admin_result = sendEmailItEmail($EMAILIT_API_KEY, $EMAILIT_API_URL, $admin_email_data);

// Send sender confirmation email (only if they provided an email)
$sender_result = null;
if (!empty($email)) {
    $sender_result = sendEmailItEmail($EMAILIT_API_KEY, $EMAILIT_API_URL, $sender_email_data);
}

// Response
if ($admin_result['success']) {
    echo json_encode([
        "status" => "success",
        "message" => "Thank you! We've received your enquiry and sent a confirmation email. We'll be in touch within 2 hours."
    ]);
} else {
    // Fallback to PHP mail if EmailIt fails
    $headers = "From: $FROM_NAME <$FROM_EMAIL>\r\n";
    $headers .= "Reply-To: $email\r\n";
    $mail_sent = mail($ADMIN_EMAIL, $admin_subject, $admin_body, $headers);
    
    if ($mail_sent) {
        echo json_encode([
            "status" => "success",
            "message" => "Thank you! We've received your enquiry. We'll be in touch within 2 hours."
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Sorry, there was an error sending your message. Please call us at 0161 123 4567."
        ]);
    }
}
?>