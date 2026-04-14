<?php
/**
 * Daily cron jobs for pending-trainer notifications.
 *
 * 1. Admin digest  — sends info@afp-pk.org a single URL filtered to yesterday's
 *                    pending trainers. Body contains the link only (no list).
 * 2. User reminder — sends reminder emails to trainers who haven't finished
 *                    registration, reusing gym_trainer_send_incomplete_reminder().
 *                    Throttled to once per 24h per user to avoid spam.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ------------------------------------------------------------------
 * 1) Admin digest — one link covering yesterday + pending
 * ------------------------------------------------------------------ */

if (!wp_next_scheduled('gtr_admin_pending_digest_daily')) {
    wp_schedule_event(time(), 'daily', 'gtr_admin_pending_digest_daily');
}
add_action('gtr_admin_pending_digest_daily', 'gtr_send_admin_pending_digest');

function gtr_send_admin_pending_digest()
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // Count-only query (optimization: skip mail if nothing is pending).
    $pending_ids = get_users([
        'role'       => 'trainer',
        'fields'     => 'ID',
        'number'     => -1,
        'date_query' => [[
            'after'     => $yesterday . ' 00:00:00',
            'before'    => $yesterday . ' 23:59:59',
            'inclusive' => true,
        ]],
        'meta_query' => [[
            'relation' => 'OR',
            [
                'key'     => 'registration_progress',
                'value'   => 'complete',
                'compare' => '!=',
            ],
            [
                'key'     => 'registration_progress',
                'compare' => 'NOT EXISTS',
            ],
        ]],
    ]);

    $count = is_array($pending_ids) ? count($pending_ids) : 0;
    if ($count === 0) {
        return;
    }

    $url = add_query_arg(
        [
            'role'           => 'trainer',
            'gtr_from'       => $yesterday,
            'gtr_to'         => $yesterday,
            'gtr_reg_status' => 'pending',
        ],
        admin_url('users.php')
    );

    $to      = 'info@afp-pk.org';
    $subject = 'Pending Trainer Registrations — ' . $yesterday;
    $message = "Hello,\n\n"
        . "There are {$count} pending trainer registration(s) for {$yesterday}.\n\n"
        . "View the list here:\n{$url}\n\n"
        . "Best Regards,\nAssociation of Fitness Professionals";

    wp_mail($to, $subject, $message);
}

/* ------------------------------------------------------------------
 * 2) User reminder — resume-link emails to pending trainers
 * ------------------------------------------------------------------ */

if (!wp_next_scheduled('gtr_pending_user_reminder_daily')) {
    wp_schedule_event(time(), 'daily', 'gtr_pending_user_reminder_daily');
}
add_action('gtr_pending_user_reminder_daily', 'gtr_send_pending_user_reminders');

function gtr_send_pending_user_reminders()
{
    if (!function_exists('gym_trainer_send_incomplete_reminder')) {
        return;
    }

    $users = get_users([
        'role'       => 'trainer',
        'fields'     => ['ID'],
        'meta_query' => [[
            'relation' => 'OR',
            [
                'key'     => 'registration_progress',
                'value'   => 'complete',
                'compare' => '!=',
            ],
            [
                'key'     => 'registration_progress',
                'compare' => 'NOT EXISTS',
            ],
        ]],
    ]);

    $throttle = DAY_IN_SECONDS;
    $now      = time();

    foreach ($users as $row) {
        $uid  = (int) $row->ID;
        $last = get_user_meta($uid, 'incomplete_reminder_sent', true);

        if ($last && ($now - strtotime($last)) < $throttle) {
            continue;
        }

        gym_trainer_send_incomplete_reminder($uid);
    }
}
