<?php
if (!defined('ABSPATH')) exit;

class GTR_PayFast_Webhook
{
    private static function log_file_path(): string
    {
        // Same folder as this class file:
        return __DIR__ . '/payfast_response-webhook.log';
    }

    private static function log_line(string $message, array $context = []): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;

        if (!empty($context)) {
            // Keep it readable
            $line .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $line .= PHP_EOL;

        // Ensure directory exists (it does), append log
        @file_put_contents(self::log_file_path(), $line, FILE_APPEND);
    }

    public static function init()
    {
        add_action('init', [__CLASS__, 'maybe_handle_webhook']);
        // add_action('phpmailer_init', function ($phpmailer) {
        //     // Enable SMTP debug output capture (works best if SMTP configured)
        //     $phpmailer->SMTPDebug = 2;

        //     $phpmailer->Debugoutput = function ($str, $level) {
        //         GTR_PayFast_Webhook::log_line('SMTP DEBUG', [
        //             'level' => $level,
        //             'msg'   => $str,
        //         ]);
        //     };
        // });
    }



    public static function maybe_handle_webhook()
    {
        if (!isset($_GET['gtr_payfast']) || $_GET['gtr_payfast'] !== 'webhook') {
            return;
        }

        // ---- Log RAW incoming ----
        self::log_line('Webhook hit', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'query'  => $_GET,
            'post'   => $_POST,
        ]);

        header('Content-Type: application/json; charset=utf-8');

        // PayFast sends via GET per guide: transaction_id, err_code, err_msg, basket_id, validation_hash :contentReference[oaicite:0]{index=0}
        $basket_id = isset($_REQUEST['basket_id']) ? sanitize_text_field($_REQUEST['basket_id']) : '';
        $err_code  = isset($_REQUEST['err_code']) ? sanitize_text_field($_REQUEST['err_code']) : '';
        $err_msg   = isset($_REQUEST['err_msg']) ? sanitize_text_field($_REQUEST['err_msg']) : '';
        $txn_id    = isset($_REQUEST['transaction_id']) ? sanitize_text_field($_REQUEST['transaction_id']) : '';
        $hash      = isset($_REQUEST['validation_hash']) ? sanitize_text_field($_REQUEST['validation_hash']) : '';

        if (empty($basket_id) || empty($err_code) || empty($hash)) {
            self::log_line('Webhook missing required params', [
                'basket_id' => $basket_id,
                'err_code'  => $err_code,
                'hash'      => $hash,
            ]);

            status_header(400);
            echo wp_json_encode(['ok' => false, 'message' => 'Missing required parameters']);
            exit;
        }

        // ---- Validate hash ----
        // validation_hash = SHA256("basket_id|secured_key|merchant_id|err_code") :contentReference[oaicite:1]{index=1}
        $expected = hash('sha256', $basket_id . '|' . GOPAYFAST_SECURE_KEY . '|' . GOPAYFAST_MERCHANT_ID . '|' . $err_code);

        if (!hash_equals($expected, $hash)) {
            self::log_line('Validation failed', [
                'basket_id' => $basket_id,
                'err_code'  => $err_code,
                'expected'  => $expected,
                'received'  => $hash,
            ]);

            status_header(403);
            echo wp_json_encode(['ok' => false, 'message' => 'Hash validation failed']);
            exit;
        }

        self::log_line('Validation passed', [
            'basket_id' => $basket_id,
            'err_code'  => $err_code,
            'txn_id'    => $txn_id,
        ]);

        // ---- Find user by basket id ----
        $users = get_users([
            'meta_key'   => 'payfast_basket_id',
            'meta_value' => $basket_id,
            'number'     => 1,
            'fields'     => 'ID',
        ]);

        if (empty($users)) {
            self::log_line('No user found for basket_id', ['basket_id' => $basket_id]);

            status_header(404);
            echo wp_json_encode(['ok' => false, 'message' => 'No user found for basket_id']);
            exit;
        }

        $user_id = (int) $users[0];

        // ---- Idempotency (avoid double processing) ----
        if (get_user_meta($user_id, 'payment_status', true) === 'paid') {
            self::log_line('Already processed as PAID (skipping)', [
                'user_id'   => $user_id,
                'basket_id' => $basket_id,
            ]);

            status_header(200);
            echo wp_json_encode(['ok' => true, 'message' => 'Already processed', 'status' => 'paid']);
            exit;
        }

        // Store payment result
        update_user_meta($user_id, 'payfast_transaction_id', $txn_id);;

        // Success codes: '000' or '00' :contentReference[oaicite:2]{index=2}
        $is_success = ($err_code === '000' || $err_code === '00');

        if (!$is_success) {
            update_user_meta($user_id, 'payment_status', 'failed');
            update_user_meta($user_id, 'registration_progress', 'payment_failed');

            self::log_line('Payment FAILED', [
                'user_id'   => $user_id,
                'basket_id' => $basket_id,
                'err_code'  => $err_code,
                'err_msg'   => $err_msg,
                'txn_id'    => $txn_id,
            ]);

            status_header(200);
            echo wp_json_encode(['ok' => true, 'status' => 'failed']);
            exit;
        }

        // ---- Payment SUCCESS: finalize registration here ----
        update_user_meta($user_id, 'payment_status', 'paid');

        // Your requested actions:
        update_user_meta($user_id, 'registration_progress', 'complete');
        gtr_resume_clear();
        delete_user_meta($user_id, 'registration_resume_key');

        // Email details
        $user = get_user_by('id', $user_id);
        $email = $user ? $user->user_email : '';

        $firstName = $user ? $user->first_name : '';
        $lastName  = $user ? $user->last_name : '';
        $city      = get_user_meta($user_id, 'city', true);
        $mobile    = get_user_meta($user_id, 'mobile', true);

        // emails
        $user_subject = "Thank you for your registration!";
        $user_message = "Dear " . $firstName . ",\n\nThank you for registering with us. Your account is pending approval. We will notify you once it's approved.\n\nBest Regards,\nAssociation of Fitness Professionals";
        $user_mail_ok = false;

        if (!empty($email)) {
            $user_mail_ok = wp_mail($email, $user_subject, $user_message);
        }

        // $admin_email = 'info@afp-pk.org'; // Change to your admin email
        // $admin_subject = "New Trainer Registration Pending Approval";
        // $admin_message = "A new trainer has registered. You can review their profile here: " .
        //     admin_url("user-edit.php?user_id=" . $user_id) .
        //     "\n\nDetails:\n\nFirst Name: " . $firstName .
        //     "\nLast Name: " . $lastName .
        //     "\nEmail: " . $email .
        //     "\nCity: " . $city .
        //     "\nMobile: " . $mobile;

        // $admin_mail_ok = wp_mail($admin_email, $admin_subject, $admin_message);

        self::log_line('Payment SUCCESS + Registration finalized', [
            'user_id'        => $user_id,
            'basket_id'      => $basket_id,
            'txn_id'         => $txn_id,
            'user_email'     => $email,
            'user_mail_ok'   => $user_mail_ok
        ]);

        status_header(200);
        echo wp_json_encode(['ok' => true, 'status' => 'paid', 'finalized' => true]);
        exit;
    }
}
