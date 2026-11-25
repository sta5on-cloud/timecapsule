<?php
// Simulated e-mail.
//
// The app does not really send e-mails. mail_send() waits a few seconds (like a slow
// mail server) and then writes a row to the "notifications" table, which is shown on
// the Notifications page. The wait happens inside the web request, so "Seal capsule"
// takes about MAIL_DELAY_SECONDS.

// Write one notification row directly (no delay). Also used by open_due_capsules().
// $kind is "sealed" or "opened": at most one notification of each kind per capsule.
function notify_insert(int $capsuleId, int $userId, string $recipient, string $message, string $kind): void
{
    db_query(
        'INSERT INTO notifications (capsule_id, user_id, recipient, message, kind) VALUES (?, ?, ?, ?, ?)
         ON CONFLICT (capsule_id, kind) DO NOTHING',
        [$capsuleId, $userId, $recipient, $message, $kind]
    );
}

// "Send" an e-mail about a capsule to $recipient.
function mail_send(array $capsule, string $recipient, string $message): void
{
    $delaySeconds = (float) config('MAIL_DELAY_SECONDS', '3');
    if ($delaySeconds > 0) {
        usleep((int) ($delaySeconds * 1_000_000)); // pretend we talk to a slow mail server
    }

    notify_insert((int) $capsule['id'], (int) $capsule['user_id'], $recipient, $message, 'sealed');
}
