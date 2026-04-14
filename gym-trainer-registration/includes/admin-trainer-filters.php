<?php
/**
 * Admin Users listing filters for trainers.
 *
 * Adds From / To (user_registered) and Pending / Completed (registration_progress)
 * filters to the standard wp-admin users.php listing.
 *
 * Query params (chosen to avoid clashing with WP's own `status`):
 *   - gtr_from           (YYYY-MM-DD)
 *   - gtr_to             (YYYY-MM-DD)
 *   - gtr_reg_status     (pending | completed)
 *
 * Example:
 *   /wp-admin/users.php?role=trainer&gtr_from=2026-01-01&gtr_to=2026-01-31&gtr_reg_status=pending
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render filter UI above the Users table.
 */
add_action('restrict_manage_users', function ($which) {
    if ($which !== 'top') {
        return;
    }

    $from = isset($_GET['gtr_from']) ? sanitize_text_field($_GET['gtr_from']) : '';
    $to   = isset($_GET['gtr_to']) ? sanitize_text_field($_GET['gtr_to']) : '';
    $reg  = isset($_GET['gtr_reg_status']) ? sanitize_text_field($_GET['gtr_reg_status']) : '';
    ?>
    <span style="display:inline-flex;align-items:center;gap:8px;margin:0 10px;">
        <label>From
            <input type="date" name="gtr_from" value="<?php echo esc_attr($from); ?>">
        </label>
        <label>To
            <input type="date" name="gtr_to" value="<?php echo esc_attr($to); ?>">
        </label>
        <label>
            <select name="gtr_reg_status">
                <option value="">All Registration Status</option>
                <option value="pending" <?php selected($reg, 'pending'); ?>>Pending</option>
                <option value="completed" <?php selected($reg, 'completed'); ?>>Completed</option>
            </select>
        </label>
        <?php submit_button(__('Filter'), '', 'gtr_filter_submit', false); ?>
    </span>
    <?php
});

/**
 * Apply date-range + registration_progress filters to the Users query.
 */
add_action('pre_get_users', function ($query) {
    if (!is_admin()) {
        return;
    }

    global $pagenow;
    if ($pagenow !== 'users.php') {
        return;
    }

    $from = isset($_GET['gtr_from']) ? sanitize_text_field($_GET['gtr_from']) : '';
    $to   = isset($_GET['gtr_to']) ? sanitize_text_field($_GET['gtr_to']) : '';
    $reg  = isset($_GET['gtr_reg_status']) ? sanitize_text_field($_GET['gtr_reg_status']) : '';

    if (!$from && !$to && !$reg) {
        return;
    }

    if ($from || $to) {
        $date_query = ['inclusive' => true];
        if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $date_query['after'] = $from . ' 00:00:00';
        }
        if ($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $date_query['before'] = $to . ' 23:59:59';
        }
        $query->set('date_query', [$date_query]);
    }

    if ($reg === 'pending' || $reg === 'completed') {
        // Scope to trainer role only — pending/completed is a trainer-specific concept.
        $query->set('role', 'trainer');

        $existing = (array) $query->get('meta_query');

        if ($reg === 'completed') {
            $existing[] = [
                'key'     => 'registration_progress',
                'value'   => 'complete',
                'compare' => '=',
            ];
        } else { // pending
            $existing[] = [
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
            ];
        }

        $query->set('meta_query', $existing);
    }
});
