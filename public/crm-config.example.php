<?php
/**
 * Copy this file to crm-config.php and edit the values. crm-config.php is intentionally
 * excluded from source control, and must live in /crm-secure/crm-config.php on the server
 * (a sibling of public_html) rather than in the web root or anywhere under it. Anything in
 * public_html gets replaced on every git-deploy push, which would silently delete it and
 * break EmailIt sending. See crm_persistent_root() in crm-api.php / send.php.
 */
return [
    'crm_email' => 'contact@sanctuaryshine.co.uk',
    'crm_password' => 'replace-with-a-long-random-password',
    'admin_email' => 'contact@sanctuaryshine.co.uk',
    'from_email' => 'contact@sanctuaryshine.co.uk',
    'transactional_from_email' => 'contact@sanctuaryshine.co.uk',
    'from_name' => 'Sanctuary Shine Website',
    'emailit_api_key' => '',
    'deepseek_api_key' => '',
];
