<?php
/**
 * Copy this file to config.php in the same folder, fill in the mailbox password,
 * and upload it directly to the server.
 *
 * NOTHING here may be committed to the site bundle or pasted into chat: config.php
 * is excluded from the build and denied by .htaccess, so it is never served.
 *
 * IONOS defaults: smtp.ionos.com, port 587, STARTTLS ('tls').
 * Use port 465 with 'ssl' if your plan blocks 587.
 * Leave smtp_user empty only if the host allows unauthenticated relay (it should not).
 */
return [
    'smtp_host'     => 'smtp.ionos.com',
    'smtp_port'     => 587,
    'smtp_secure'   => 'tls',                               // 'tls' = STARTTLS, 'ssl' = implicit, 'none'
    'smtp_user'     => 'jason@thejunglewhisperer.com',      // the full mailbox address
    'smtp_pass'     => 'REPLACE_WITH_THE_MAILBOX_PASSWORD', // IONOS email account password

    'from'          => 'jason@thejunglewhisperer.com',      // must be the authenticated mailbox
    'from_name'     => 'The Jungle Whisperer',
    'to'            => ['jason@thejunglewhisperer.com'],    // where enquiries land

    // Fall back to PHP mail() if SMTP fails. Leave false: on shared hosting it is
    // usually unauthenticated and lands in spam, which hides real failures.
    'fallback_mail' => false,
];
