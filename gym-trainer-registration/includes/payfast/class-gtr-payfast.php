<?php
if (!defined('ABSPATH')) exit;

class GTR_PayFast
{
    public static function init()
    {
        // Start payment (your redirect you already made points here)
        add_action('admin_post_gtr_start_payfast_payment', [__CLASS__, 'start_payment']);
        add_action('admin_post_nopriv_gtr_start_payfast_payment', [__CLASS__, 'start_payment']);

        // ✅ SAME handler for success + failure
        add_action('admin_post_gtr_payfast_return', [__CLASS__, 'handle_return']);
        add_action('admin_post_nopriv_gtr_payfast_return', [__CLASS__, 'handle_return']);
    }

    private static function log_line(string $message, array $context = []): void
    {
        $file = __DIR__ . '/payfast_response.log'; // same folder as class-gtr-payfast.php

        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $line .= PHP_EOL;

        @file_put_contents($file, $line, FILE_APPEND);
    }

    /**
     * STEP: Get token -> auto-submit form to PostTransaction
     */
    public static function start_payment()
    {
        $user_id   = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $basket_id = isset($_GET['basket_id']) ? sanitize_text_field($_GET['basket_id']) : '';

        if (!$user_id || empty($basket_id)) {
            wp_die('Missing user_id or basket_id');
        }

        // Make sure basket_id belongs to this user (basic integrity)
        $saved_basket = get_user_meta($user_id, 'payfast_basket_id', true);
        if (empty($saved_basket) || $saved_basket !== $basket_id) {
            wp_die('Invalid basket_id for user');
        }

        // --- Amount handling ---
        // You can hardcode your registration fee here OR pass it in query later.
        // For now, set a fixed fee:
        $amount = 20000; // TODO: change this to your actual registration fee

        // Get user details (email/mobile)
        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_die('User not found');
        }

        $email  = $user->user_email;

        // If you stored mobile in user meta earlier (common):
        $mobile = get_user_meta($user_id, 'mobile', true);
        $mobile = is_string($mobile) ? preg_replace('/\D+/', '', $mobile) : '';
        if (empty($mobile)) {
            $mobile = '00000000000'; // fallback (gateway requires it)
        }

        // 1) Get access token
        $token = self::get_access_token($basket_id, $amount);

        if (empty($token)) {
            wp_die('Could not get access token from PayFast');
        }

        // 2) Build redirect (PostTransaction) form fields (required)
        $merchant_id   = GOPAYFAST_MERCHANT_ID;
        $merchant_name = defined('GOPAYFAST_MERCHANT_NAME') ? GOPAYFAST_MERCHANT_NAME : 'RepsPakistan';
        $currency      = 'PKR';

        $return_url = add_query_arg([
            'action' => 'gtr_payfast_return',
        ], admin_url('admin-post.php'));

        // CHECKOUT_URL is server-to-server notification endpoint (we’ll wire it later)
        // For now put a placeholder that you will implement in webhook class
        $checkout_url = add_query_arg([
            'gtr_payfast' => 'webhook',
        ], home_url('/'));

        // Per guide: SIGNATURE + VERSION are random strings :contentReference[oaicite:3]{index=3}
        $signature = wp_generate_password(24, false, false);
        $version   = 'GTR-' . wp_generate_password(10, false, false);

        $order_date = gmdate('Y-m-d'); // guide says YYYY-MM-DD :contentReference[oaicite:4]{index=4}

         $firstName = $user ? $user->first_name : '';
        $lastName  = $user ? $user->last_name : '';

        $city      = get_user_meta($user_id, 'city', true);

        $admin_email = 'info@afp-pk.org'; // Change to your admin email
        $admin_subject = "New Trainer Registration Pending Approval";
        $admin_message = "A new trainer has registered. You can review their profile here: " .
            admin_url("user-edit.php?user_id=" . $user_id) .
            "\n\nDetails:\n\nFirst Name: " . $firstName .
            "\nLast Name: " . $lastName .
            "\nEmail: " . $email .
            "\nCity: " . $city .
            "\nMobile: " . $mobile;

          $admin_headers = array(
            'Bcc: info@repspakistan.org',
            'Bcc: finance@repspakistan.org',
            'Bcc: membership@repspakistan.org',
        );
        $admin_mail_ok = wp_mail($admin_email, $admin_subject, $admin_message,$admin_headers);
        
        self::log_line('RETURN failed redirect', ['admin_mail_ok' => $admin_mail_ok]);

        // Output an auto-submit HTML form to redirect user to gateway
        self::render_auto_post_form(GOPAYFAST_POST_TRANSACTION_URL, [
            'CURRENCY_CODE'          => $currency,
            'MERCHANT_ID'            => $merchant_id,
            'MERCHANT_NAME'          => $merchant_name,
            'TOKEN'                  => $token,
            'PROCCODE'               => '00',
            'TXNAMT'                 => $amount,
            'CUSTOMER_MOBILE_NO'     => $mobile,
            'CUSTOMER_EMAIL_ADDRESS' => $email,
            'SIGNATURE'              => $signature,
            'VERSION'                => $version,
            'TXNDESC'                => 'Trainer Registration Fee',
            'SUCCESS_URL'            => $return_url,
            'FAILURE_URL'            => $return_url,
            'BASKET_ID'              => $basket_id,
            'ORDER_DATE'             => $order_date,

            // Optional but shown in guide example as a hidden param:
            'MERCHANT_USERAGENT'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 250) : 'WordPress',
            'CHECKOUT_URL'           => $checkout_url,
        ]);
        

    }

    private static function get_access_token(string $basket_id, $amount): string
    {
        $merchant_id = GOPAYFAST_MERCHANT_ID;
        $secure_key  = GOPAYFAST_SECURE_KEY;

        if (empty($merchant_id) || empty($secure_key)) {
            wp_die('PayFast merchant config missing');
        }

        // Token API per guide: MERCHANT_ID, SECURED_KEY, TXNAMT, BASKET_ID :contentReference[oaicite:5]{index=5}
        $body = [
            'MERCHANT_ID'  => $merchant_id,
            'SECURED_KEY'  => $secure_key,
            'TXNAMT'       => $amount,
            'BASKET_ID'    => $basket_id,
            // CURRENCY_CODE is mentioned as required in text, but sample uses only these fields.
            // If your merchant setup needs it, uncomment next line:
            'CURRENCY_CODE' => 'PKR',
        ];

        $response = wp_remote_post(GOPAYFAST_GET_TOKEN_URL, [
            'timeout' => 30,
            'headers' => [
                // guide sample sets a user-agent; keep one to avoid UA-related rejection :contentReference[oaicite:6]{index=6}
                'User-Agent' => 'WordPress/GTR PayFast',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300 || empty($raw)) {
            return '';
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return '';
        }

        // Guide sample reads ACCESS_TOKEN :contentReference[oaicite:7]{index=7}
        return isset($json['ACCESS_TOKEN']) ? (string) $json['ACCESS_TOKEN'] : '';
    }

    private static function render_auto_post_form(string $action_url, array $fields): void
    {
        // Minimal “redirecting…” page + auto submit
        header('Content-Type: text/html; charset=utf-8');
?>
        <!doctype html>
        <html>

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Redirecting to Payment...</title>
        </head>

        <body>
            <p style="font-family: Arial, sans-serif;">Redirecting to payment gateway…</p>

            <form id="gtr_payfast_redirect" method="post" action="<?php echo esc_url($action_url); ?>">
                <?php foreach ($fields as $name => $value): ?>
                    <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>">
                <?php endforeach; ?>
                <noscript>
                    <p>JavaScript is disabled. Click the button below to continue.</p>
                    <button type="submit">Continue</button>
                </noscript>
            </form>

            <script>
                document.getElementById('gtr_payfast_redirect').submit();
            </script>
        </body>

        </html>
<?php
        exit;
    }

    public static function handle_return()
    {
        // Read PayFast return params
        $basket_id = isset($_REQUEST['basket_id']) ? sanitize_text_field($_REQUEST['basket_id']) : '';
        $err_code  = isset($_REQUEST['err_code']) ? sanitize_text_field($_REQUEST['err_code']) : '';
        $err_msg   = isset($_REQUEST['err_msg']) ? sanitize_text_field($_REQUEST['err_msg']) : '';
        $hash      = isset($_REQUEST['validation_hash']) ? sanitize_text_field($_REQUEST['validation_hash']) : '';

        self::log_line('RETURN hit', [
            'basket_id' => $basket_id,
            'err_code'  => $err_code,
            'err_msg'   => $err_msg,
            'hash'      => $hash,
            'query'     => $_GET,
            'post'      => $_POST,
        ]);

        // If missing, treat as failed
        if (empty($basket_id) || empty($err_code)) {
            self::log_line('RETURN missing params');
            wp_safe_redirect(home_url('/registration?payment=failed'));
            exit;
        }

        // Success codes
        $is_success = ($err_code === '000' || $err_code === '00');

        // (Optional) Validate hash here too (not mandatory if webhook is final authority)
        $merchant_id = trim((string) GOPAYFAST_MERCHANT_ID);
        $secured_key = trim((string) GOPAYFAST_SECURE_KEY);

        $expected = hash('sha256', $basket_id . '|' . $secured_key . '|' . $merchant_id . '|' . $err_code);
        self::log_line('HASH debug', [
            'basket_id' => $basket_id,
            'err_code'  => $err_code,
            'merchant_id_used' => $merchant_id,
            'secured_key_len'  => strlen($secured_key),
            'secured_key_last4' => substr($secured_key, -4),
            'expected' => $expected,
            'received' => $hash,
        ]);

        if (!empty($hash) && !hash_equals($expected, $hash)) {
            self::log_line('RETURN hash validation failed', [
                'basket_id' => $basket_id,
                'err_code'  => $err_code,
                'expected'  => $expected,
                'received'  => $hash,
            ]);
            $is_success = false; // treat as failed
        }

        // Redirect back to registration page
        $registration_page_success = home_url('/registration-thank-you/'); // ✅ change if your registration URL is different
        $registration_page_failure = home_url('/registration-failed/'); // ✅ change if your registration URL is different

        if ($is_success) {
            self::log_line('RETURN success redirect', ['to' => $registration_page_success, 'param' => 'registered=1']);
            wp_safe_redirect(add_query_arg('registered', '1', $registration_page_success));
        } else {
            self::log_line('RETURN failed redirect', ['to' => $registration_page_failure, 'param' => 'payment=failed']);
            wp_safe_redirect(add_query_arg('payment', 'failed', $registration_page_failure));
        }

        exit;
    }
}
