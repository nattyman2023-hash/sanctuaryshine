<?php
/**
 * Sanctuary Shine - Form Handler for Hostinger
 * 
 * This script handles form submissions from the contact and quote forms.
 * It sends email notifications to the business owner.
 * 
 * HOW TO USE:
 * 1. Update the $to_email address below
 * 2. Update the $to_name if needed
 * 3. Upload this file to your Hostinger server (e.g., public_html/send.php)
 * 4. Update the form action in your HTML files to point to this file
 *    e.g., <form action="/send.php" method="POST">
 * 
 * ALTERNATIVES:
 * - Formspree: Replace action with https://formspree.io/f/YOUR_FORM_ID
 * - EmailJS: Use their JavaScript library
 * - Google Forms: Use their form action URL
 */

// Configuration
$to_email = "hello@sanctuaryshine.co.uk"; // CHANGE THIS to your email
$to_name = "Sanctuary Shine";
$subject_prefix = "Sanctuary Shine Website Enquiry";

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
$subject = isset($_POST['subject']) ? strip_tags(trim($_POST['subject'])) : '';

// Validation
$errors = [];
if (empty($name)) $errors[] = "Name is required";
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required";
if (empty($message)) $errors[] = "Message is required";

if (!empty($errors)) {
    $response = "Error: " . implode(", ", $errors);
    echo json_encode(["status" => "error", "message" => $response]);
    exit;
}

// Build email content
$email_content = "New enquiry from Sanctuary Shine website\n\n";
$email_content .= "Form Type: " . ($form_type == 'quote' ? 'Quote Request' : 'Contact Form') . "\n";
$email_content .= "Name: $name\n";
$email_content .= "Email: $email\n";
$email_content .= "Phone: $phone\n";
$email_content .= "Postcode: $postcode\n";

if (!empty($cleaning_type)) $email_content .= "Cleaning Type: $cleaning_type\n";
if (!empty($property_type)) $email_content .= "Property Type: $property_type\n";
if (!empty($size)) $email_content .= "Bedrooms/Offices: $size\n";
if (!empty($preferred_date)) $email_content .= "Preferred Date: $preferred_date\n";
if (!empty($subject)) $email_content .= "Subject: $subject\n";

$email_content .= "\nMessage:\n$message\n";

// Email headers
$headers = "From: $name <$email>\r\n";
$headers .= "Reply-To: $email\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

// Send email
$subject_line = "$subject_prefix - $form_type from $name";
$mail_sent = mail($to_email, $subject_line, $email_content, $headers);

// Response
if ($mail_sent) {
    echo json_encode(["status" => "success", "message" => "Thank you! We'll be in touch within 2 hours."]);
} else {
    echo json_encode(["status" => "error", "message" => "Sorry, there was an error sending your message. Please try again or call us directly."]);
}
?>