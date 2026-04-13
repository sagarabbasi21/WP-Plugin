<?php
/*
Plugin Name: Gym Trainer Registration
Description: 3-step Trainer registration with pending approval.
Version: 1.6
Author: Shoaib
*/

if (!defined('ABSPATH'))
    exit;

define('GTR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GTR_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('GOPAYFAST_MERCHANT_NAME', 'Association of Fitness Professionals');



require_once GTR_PLUGIN_DIR . 'includes/helpers.php';
require_once GTR_PLUGIN_DIR . '/includes/payfast/class-gtr-payfast.php';
require_once GTR_PLUGIN_DIR . '/includes/payfast/class-gtr-payfast-webhook.php';

GTR_PayFast_Webhook::init();
GTR_PayFast::init();

/* ============================================================
   ENQUEUE STYLES
============================================================ */
add_action('wp_enqueue_scripts', 'gym_trainer_enqueue_styles');

function gym_trainer_enqueue_styles()
{
    $rel = 'assets/css/gtr-style.css';

    // optional: cache bust by filemtime
    $path = plugin_dir_path(__FILE__) . $rel;
    $url  = plugin_dir_url(__FILE__) . $rel;

    // If file missing, don't enqueue (prevents 404)
    if (!file_exists($path)) {
        return;
    }

    wp_enqueue_style(
        'gym-trainer-style',
        $url,
        [],
        filemtime($path)
    );
}



/* ============================================================
   ADD TRAINER ROLE
============================================================ */
add_action('init', 'add_trainer_role');
function add_trainer_role()
{
    if (!get_role('trainer')) {
        add_role('trainer', 'Trainer', ['read' => true, 'edit_posts' => false]);
    }
}

/* ============================================================
   CRON INTERVAL — EVERY 5 MINUTES (TESTING)
   MUST be registered BEFORE wp_schedule_event uses it
============================================================ */
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['every_5_minutes'])) {
        $schedules['every_5_minutes'] = [
            'interval' => 300,
            'display' => __('Every 5 Minutes')
        ];
    }
    return $schedules;
});

/* ============================================================
   REGISTRATION FORM SHORTCODE
============================================================ */
add_shortcode('gym_trainer_register', 'gym_trainer_registration_form');
function gym_trainer_registration_form()
{
    if (is_user_logged_in())
        return '<p>You are already logged in.</p>';

    ob_start();

    global $gym_trainer_errors;
    $field_errors = is_array($gym_trainer_errors) ? $gym_trainer_errors : [];

    // Handle "resume registration" link from email or from cookie
    $resume_user_id = gtr_resume_get();

    // If already completed, do not show form
    if ($resume_user_id) {

        $progress = get_user_meta($resume_user_id, 'registration_progress', true);

        if ($progress === 'complete') {

            ob_start();
        ?>
            <div style="padding:15px;background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;color:#155724;">
                <p style="margin-bottom:12px;">
                    Your registration is already complete. You don't need to fill this form again.
                </p>

                <form method="post">
                    <?php wp_nonce_field('gym_trainer_register', 'gym_trainer_register_nonce'); ?>
                    <input type="hidden" name="gtr_start_new_registration" value="1">

                    <button type="submit" style="
                    padding:8px 16px;
                    background:#0073aa;
                    color:#fff;
                    border:none;
                    border-radius:4px;
                    cursor:pointer;
                ">
                        Start New Registration
                    </button>
                </form>
            </div>
    <?php

            return ob_get_clean();
        }
    }


    if (isset($_GET['registered']) && $_GET['registered'] == '1') {
        echo '<p style="padding:15px;background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;color:#155724;">
        Thank you for registration! Your account is pending approval.</p>';
    }
    if (isset($_GET['payment']) && $_GET['payment'] == 'failed') {
        echo '  <div class="notice notice-error">
                ❌ Payment failed or was cancelled. Please try again.
            </div>';
    }

    // Decide which step to show
    if (
        !empty($_POST['gym_trainer_register_nonce']) &&
        wp_verify_nonce($_POST['gym_trainer_register_nonce'], 'gym_trainer_register') &&
        isset($_POST['start_step'])
    ) {
        $start_step = intval($_POST['start_step']);
    } elseif ($resume_user_id) {
        $progress = get_user_meta($resume_user_id, 'registration_progress', true);
        if ($progress === 'step2' || $progress === 'payment_pending' || $progress === 'payment_failed')
            $start_step = 2;
        elseif ($progress === 'step1')
            $start_step = 1;
        else
            $start_step = 0;
    } else {
        $start_step = 0;
    }

    // Prefill values
    if (!empty($_POST)) {
        $posted_firstName = isset($_POST['firstName']) ? esc_attr($_POST['firstName']) : '';
        $posted_lastName = isset($_POST['lastName']) ? esc_attr($_POST['lastName']) : '';
        $posted_city = isset($_POST['city']) ? esc_attr($_POST['city']) : '';
        $posted_mobile = isset($_POST['mobile']) ? esc_attr($_POST['mobile']) : '';
        $posted_dob = isset($_POST['dob']) ? esc_attr($_POST['dob']) : '';
        $posted_gender = isset($_POST['gender']) ? esc_attr($_POST['gender']) : '';
        $posted_nationality = isset($_POST['nationality']) ? esc_attr($_POST['nationality']) : '';
        $posted_nid = isset($_POST['nid']) ? esc_attr($_POST['nid']) : '';
        $posted_email = isset($_POST['email']) ? esc_attr($_POST['email']) : '';

        $posted_jobTitle = isset($_POST['jobTitle']) ? esc_attr($_POST['jobTitle']) : '';
        $posted_gymName = isset($_POST['gymName']) ? esc_attr($_POST['gymName']) : '';
        $posted_branchName = isset($_POST['branchName']) ? esc_attr($_POST['branchName']) : '';
        $posted_levels = isset($_POST['level']) && is_array($_POST['level'])
            ? array_map('sanitize_text_field', $_POST['level'])
            : [];
    } elseif (!empty($resume_user_id)) {
        $resume_user = get_user_by('id', $resume_user_id);

        $posted_firstName = $resume_user ? esc_attr($resume_user->first_name) : '';
        $posted_lastName = $resume_user ? esc_attr($resume_user->last_name) : '';
        $posted_email = $resume_user ? esc_attr($resume_user->user_email) : '';

        $posted_city = esc_attr(get_user_meta($resume_user_id, 'city', true));
        $posted_mobile = esc_attr(get_user_meta($resume_user_id, 'mobile', true));
        $posted_dob = esc_attr(get_user_meta($resume_user_id, 'dob', true));
        $posted_gender = esc_attr(get_user_meta($resume_user_id, 'gender', true));
        $posted_nationality = esc_attr(get_user_meta($resume_user_id, 'nationality', true));
        $posted_nid = esc_attr(get_user_meta($resume_user_id, 'nid', true));

        $posted_jobTitle = esc_attr(get_user_meta($resume_user_id, 'jobTitle', true));
        $posted_gymName = esc_attr(get_user_meta($resume_user_id, 'gymName', true));
        $posted_branchName = esc_attr(get_user_meta($resume_user_id, 'branchName', true));
        $posted_levels = (array) get_user_meta($resume_user_id, 'level', true);
    } else {
        $posted_firstName = $posted_lastName = $posted_city = $posted_mobile = $posted_dob = $posted_gender = $posted_nationality = $posted_nid = $posted_email = '';
        $posted_jobTitle = $posted_gymName = $posted_branchName = '';
        $posted_levels = [];
    }

    // Determine user id to persist across steps/resume
    $prefill_user_id = 0;
    if (!empty($resume_user_id)) {
        $prefill_user_id = (int) $resume_user_id;
    } elseif (!empty($posted_email)) {
        $u = get_user_by('email', sanitize_email($posted_email));
        if ($u)
            $prefill_user_id = (int) $u->ID;
    }

    ?>
    <form id="regForm" method="post" enctype="multipart/form-data" data-start-step="<?php echo esc_attr($start_step); ?>">
        <?php wp_nonce_field('gym_trainer_register', 'gym_trainer_register_nonce'); ?>
        <input type="hidden" name="start_step" value="<?php echo esc_attr($start_step); ?>">

        <?php if ($prefill_user_id > 0): ?>
            <input type="hidden" name="existing_user_id" value="<?php echo (int) $prefill_user_id; ?>">
        <?php endif; ?>

        <div class="step-progress">
            <div class="step-indicator active">
                <div class="circle">1</div>
                <div class="step-text">Personal Details</div>
            </div>
            <div class="step-indicator">
                <div class="circle">2</div>
                <div class="step-text">Your Workplace</div>
            </div>
            <div class="step-indicator">
                <div class="circle">3</div>
                <div class="step-text">Qualifications & Payment</div>
            </div>
        </div>

        <!-- STEP 1 -->
        <div class="step active">
            <h3>Step 1: Personal Details</h3>

            <div class="row">
                <div class="col">
                    <label class="required">First Name:</label>
                    <input type="text" name="firstName" maxlength="50" required value="<?php echo $posted_firstName; ?>"
                        class="<?php echo !empty($field_errors['firstName']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['firstName'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['firstName']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col">
                    <label class="required">Last Name:</label>
                    <input type="text" name="lastName" maxlength="50" required value="<?php echo $posted_lastName; ?>"
                        class="<?php echo !empty($field_errors['lastName']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['lastName'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['lastName']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <label class="required">City:</label>
                    <input type="text" maxlength="50" name="city" required value="<?php echo $posted_city; ?>"
                        class="<?php echo !empty($field_errors['city']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['city'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['city']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col">
                    <label class="required">Mobile:</label>
                    <input type="text" maxlength="15" name="mobile" id="phoneNumber" required
                        value="<?php echo $posted_mobile; ?>"
                        class="<?php echo !empty($field_errors['mobile']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['mobile'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['mobile']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row">
                <div class="col"><label class="required">DOB:</label>
                    <input type="date" name="dob" required value="<?php echo $posted_dob; ?>" max="<?php echo date('Y-m-d'); ?>">
                    <?php if (!empty($field_errors['dob'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['dob']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="col"><label class="required">Gender:</label>
                    <select name="gender" required>
                        <option value="">Select</option>
                        <option <?php selected($posted_gender, 'Male'); ?>>Male</option>
                        <option <?php selected($posted_gender, 'Female'); ?>>Female</option>
                    </select>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <label class="required">Nationality:</label>
                    <select name="nationality" required>
                        <option value="">Select</option>
                        <?php
                        $nations = [
                            "Afghan",
                            "Albanian",
                            "Algerian",
                            "American",
                            "Angolan",
                            "Argentinian",
                            "Armenian",
                            "Australian",
                            "Austrian",
                            "Azerbaijani",
                            "Bangladeshi",
                            "Belarusian",
                            "Belgian",
                            "Bolivian",
                            "Bosnian",
                            "Brazilian",
                            "British",
                            "Bulgarian",
                            "Cambodian",
                            "Cameroonian",
                            "Canadian",
                            "Chilean",
                            "Chinese",
                            "Colombian",
                            "Croatian",
                            "Cuban",
                            "Czech",
                            "Danish",
                            "Dutch",
                            "Egyptian",
                            "Emirati",
                            "Ethiopian",
                            "Finnish",
                            "French",
                            "German",
                            "Ghanaian",
                            "Greek",
                            "Hungarian",
                            "Icelandic",
                            "Indian",
                            "Indonesian",
                            "Iranian",
                            "Iraqi",
                            "Irish",
                            "Italian",
                            "Japanese",
                            "Jordanian",
                            "Kenyan",
                            "Kuwaiti",
                            "Lebanese",
                            "Libyan",
                            "Malaysian",
                            "Mexican",
                            "Moroccan",
                            "Nepalese",
                            "New Zealander",
                            "Nigerian",
                            "Norwegian",
                            "Omani",
                            "Pakistani",
                            "Palestinian",
                            "Peruvian",
                            "Filipino",
                            "Polish",
                            "Portuguese",
                            "Qatari",
                            "Romanian",
                            "Russian",
                            "Saudi",
                            "Serbian",
                            "Singaporean",
                            "Somali",
                            "South African",
                            "South Korean",
                            "Spanish",
                            "Sri Lankan",
                            "Sudanese",
                            "Swedish",
                            "Swiss",
                            "Syrian",
                            "Tunisian",
                            "Turkish",
                            "Ukrainian",
                            "Uruguayan",
                            "Uzbek",
                            "Venezuelan",
                            "Vietnamese",
                            "Yemeni"
                        ];
                        foreach ($nations as $n) {
                            printf(
                                "<option value='%s' %s>%s</option>",
                                esc_attr($n),
                                selected($posted_nationality, $n, false),
                                esc_html($n)
                            );
                        }
                        ?>
                    </select>
                </div>

                <div class="col">
                    <label class="required">National ID:</label>
                    <input type="text" maxlength="30" name="nid" required value="<?php echo $posted_nid; ?>">
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <label class="required">National ID Photo (Front):</label>
                    <input type="file" name="nidPhotoFront" accept="image/*"
                        class="<?php echo !empty($field_errors['nidPhotoFront']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['nidPhotoFront'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['nidPhotoFront']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col">
                    <label class="required">National ID Photo (Back):</label>
                    <input type="file" name="nidPhotoBack" accept="image/*"
                        class="<?php echo !empty($field_errors['nidPhotoBack']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['nidPhotoBack'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['nidPhotoBack']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <label class="required">Your Photo:</label>
            <input type="file" name="photo" accept="image/*"
                class="<?php echo !empty($field_errors['photo']) ? 'is-invalid' : ''; ?>">
            <?php if (!empty($field_errors['photo'])): ?>
                <div class="invalid-feedback"><?php echo esc_html($field_errors['photo']); ?></div>
            <?php endif; ?>

            <h3>Login Details</h3>
            <div class="row">
                <div class="col">
                    <label class="required">Email:</label>
                    <input type="email" name="email" required value="<?php echo $posted_email; ?>"
                        class="<?php echo !empty($field_errors['email']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['email'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['email']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col">
                    <label class="required">Password:</label>
                    <input type="password" name="password"
                        class="<?php echo !empty($field_errors['password']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['password'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['password']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="button-container">
                <button type="button" class="back" disabled style="visibility:hidden;">Back</button>
                <button type="submit" class="next" name="reg_step" value="1">Continue</button>
            </div>
        </div>

        <!-- STEP 2 -->
        <div class="step">
            <h3>Step 2: Workplace</h3>

            <label class="required">Job Title:</label>
            <input type="text" name="jobTitle" maxlength="100" required value="<?php echo $posted_jobTitle; ?>"
                class="<?php echo !empty($field_errors['jobTitle']) ? 'is-invalid' : ''; ?>">
            <?php if (!empty($field_errors['jobTitle'])): ?>
                <div class="invalid-feedback"><?php echo esc_html($field_errors['jobTitle']); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col">
                    <label class="required">Gym Name:</label>
                    <input type="text" name="gymName" maxlength="100" required value="<?php echo $posted_gymName; ?>"
                        class="<?php echo !empty($field_errors['gymName']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['gymName'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['gymName']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="col">
                    <label class="required">Branch:</label>
                    <input type="text" name="branchName" maxlength="50" required value="<?php echo $posted_branchName; ?>"
                        class="<?php echo !empty($field_errors['branchName']) ? 'is-invalid' : ''; ?>">
                    <?php if (!empty($field_errors['branchName'])): ?>
                        <div class="invalid-feedback"><?php echo esc_html($field_errors['branchName']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <label class="required">Upload CV:</label>
            <input type="file" name="cv" accept=".pdf,.doc,.docx"
                class="<?php echo !empty($field_errors['cv']) ? 'is-invalid' : ''; ?>">
            <?php if (!empty($field_errors['cv'])): ?>
                <div class="invalid-feedback"><?php echo esc_html($field_errors['cv']); ?></div>
            <?php endif; ?>

            <h3>Level of Registration:</h3>
            <table class="table-checkbox">
                <tr>
                    <th>Level</th>
                    <th style="text-align:center;">Select</th>
                </tr>
                <?php
                $levels = [
                    "A: Personal Trainer",
                    "B: Gym Instructor",
                    "C: Pre-Choreographed",
                    "D: Group Fitness Instructor (Freestyle)",
                    "E: Yoga Instructor",
                    "F: Pilates Instructor",
                    "G: Pilates Instructor (Comprehensive)"
                ];

                foreach ($levels as $level) {
                    $checked = (is_array($posted_levels) && in_array($level, $posted_levels, true)) ? 'checked' : '';
                    echo "<tr>
                        <td>" . esc_html($level) . "</td>
                        <td style='text-align:center;'>
                            <input type='checkbox' name='level[]' value='" . esc_attr($level) . "' $checked>
                        </td>
                    </tr>";
                }
                ?>
            </table>
            <?php if (!empty($field_errors['level'])): ?>
                <div class="invalid-feedback"><?php echo esc_html($field_errors['level']); ?></div>
            <?php endif; ?>

            <div class="button-container">
                <button type="button" class="back" onclick="prevStep()">Back</button>
                <button type="submit" class="next" name="reg_step" value="2">Continue</button>
            </div>
        </div>

        <!-- STEP 3 -->
        <?php
        $posted_qualifications = [];

        if (!empty($_POST['courseName']) && is_array($_POST['courseName'])) {

            // get saved qualifications if user exists (for certificates)
            $saved_qualifications = [];
            if (!empty($prefill_user_id)) {
                $saved_qualifications = get_user_meta($prefill_user_id, 'qualifications', true);
                if (!is_array($saved_qualifications)) {
                    $saved_qualifications = [];
                }
            }

            foreach ($_POST['courseName'] as $i => $c) {

                $qid = $_POST['qualification_id'][$i] ?? ($saved_qualifications[$i]['qual_id'] ?? uniqid('qual_', true));

                $posted_qualifications[] = [
                    'qual_id' => $qid,
                    'courseName' => sanitize_text_field($c),
                    'institutionName' => sanitize_text_field($_POST['institutionName'][$i] ?? ''),
                    'completionDate' => sanitize_text_field($_POST['completionDate'][$i] ?? ''),
                    // ✅ KEEP OLD CERTIFICATES
                    'certificate_ids' => $saved_qualifications[$i]['certificate_ids'] ?? [],
                ];
            }
        } elseif (!empty($resume_user_id)) {
            $q = get_user_meta($resume_user_id, 'qualifications', true);
            if (is_array($q) && !empty($q)) {
                $posted_qualifications = $q;
            } else {
                $legacy_course = get_user_meta($resume_user_id, 'courseName', true);
                $legacy_inst = get_user_meta($resume_user_id, 'institutionName', true);
                $legacy_date = get_user_meta($resume_user_id, 'completionDate', true);
                if ($legacy_course || $legacy_inst || $legacy_date) {
                    $posted_qualifications = [
                        [
                            'qual_id' => uniqid('qual_', true),
                            'courseName' => $legacy_course,
                            'institutionName' => $legacy_inst,
                            'completionDate' => $legacy_date,
                            'certificate_ids' => [],
                        ]
                    ];
                }
            }
        }

        if (empty($posted_qualifications)) {
            $posted_qualifications = [
                [
                    'qual_id' => uniqid('qual_', true),
                    'courseName' => '',
                    'institutionName' => '',
                    'completionDate' => '',
                    'certificate_ids' => [],
                ]
            ];
        }

        $posted_qualifications = array_slice($posted_qualifications, 0, 5);
        ?>

        <div class="step">
            <h3>Step 3: Qualifications & Payment</h3>

            <div id="qualifications-wrapper">
                <?php foreach ($posted_qualifications as $idx => $q): ?>
                    <div class="qualification-row" data-qid="<?= esc_attr($q['qual_id']) ?>">
                        <input type="hidden" name="qualification_id[]" value="<?= esc_attr($q['qual_id']) ?>">
                        <!-- ================= COURSE + INSTITUTION ================= -->
                        <div class="row">
                            <div class="col">
                                <label class="required">Course Name:</label>
                                <input type="text" name="courseName[]" maxlength="100" required
                                    value="<?php echo esc_attr($q['courseName'] ?? ''); ?>"
                                    class="<?php echo !empty($field_errors['courseName'][$idx]) ? 'is-invalid' : ''; ?>">

                                <?php if (!empty($field_errors['courseName'][$idx])): ?>
                                    <div class="invalid-feedback">
                                        <?php echo esc_html($field_errors['courseName'][$idx]); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col">
                                <label class="required">Institution:</label>
                                <input type="text" name="institutionName[]" maxlength="100" required
                                    value="<?php echo esc_attr($q['institutionName'] ?? ''); ?>"
                                    class="<?php echo !empty($field_errors['institutionName'][$idx]) ? 'is-invalid' : ''; ?>">

                                <?php if (!empty($field_errors['institutionName'][$idx])): ?>
                                    <div class="invalid-feedback">
                                        <?php echo esc_html($field_errors['institutionName'][$idx]); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ================= DATE + CERTIFICATES ================= -->
                        <div class="row">
                            <div class="col">
                                <label class="required">Completion Date:</label>
                                <input type="date" name="completionDate[]" required
                                    value="<?php echo esc_attr($q['completionDate'] ?? ''); ?>"
                                    max="<?php echo esc_attr(wp_date('Y-m-d')); ?>"
                                    class="<?php echo !empty($field_errors['completionDate'][$idx]) ? 'is-invalid' : ''; ?>">

                                <?php if (!empty($field_errors['completionDate'][$idx])): ?>
                                    <div class="invalid-feedback">
                                        <?php echo esc_html($field_errors['completionDate'][$idx]); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col">
                                <?php
                                $saved_cert_ids = [];
                                if (!empty($q['certificate_ids']) && is_array($q['certificate_ids'])) {
                                    $saved_cert_ids = array_filter(array_map('intval', $q['certificate_ids']));
                                }
                                ?>

                                <div class="qualification-cert-wrapper" style="margin-bottom:16px;">
                                    <label class="required">Certificates (for this qualification):</label>

                                    <div class="cert-upload-container">
                                        <input type="file" name="qualification_certificates[<?php echo (int) $idx; ?>][]"
                                            multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                            class="<?php echo !empty($field_errors['qualification_certificates'][$idx]) ? 'is-invalid' : ''; ?>">
                                        <p style="font-size:12px;margin-top:0;">
                                            max 5 files, allowed types: PDF, DOC, DOCX, JPG, PNG, max 10MB each </p>

                                        <?php if (!empty($field_errors['qualification_certificates'][$idx])): ?>
                                            <div class="invalid-feedback" style="display:block;">
                                                <?php echo esc_html($field_errors['qualification_certificates'][$idx]); ?>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Already Uploaded -->
                                        <?php if (!empty($saved_cert_ids)): ?>
                                            <div class="uploaded-certs" style="margin-top:6px; font-size:13px;">
                                                <strong>Already uploaded:</strong><br>

                                                <?php foreach ($saved_cert_ids as $cid):
                                                    $url = wp_get_attachment_url($cid);
                                                    $name = get_the_title($cid);
                                                ?>
                                                    <div class="cert-item" style="margin-bottom:4px;" data-cert-id="<?= (int) $cid ?>">
                                                        <a href="<?php echo esc_url($url); ?>" target="_blank">
                                                            <?php echo esc_html($name); ?>
                                                        </a>
                                                        &nbsp;|&nbsp;
                                                        <span class="remove-certificate" style="color:red; cursor:pointer;"
                                                            data-cert-id="<?= (int) $cid ?>">
                                                            X
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>

                                                <!-- 🔹 hidden input JUST for this qualification -->
                                                <input type="hidden" class="removed-certificates-input"
                                                    name="removed_certificate_ids[<?= esc_attr($q['qual_id'] ?? $idx) ?>]" value="">
                                            </div>
                                        <?php endif; ?>

                                        <!-- Remove -->
                                        <button type="button" class="remove-qualification" onclick="removeQualification(this)"
                                            style="<?php echo (count($posted_qualifications) > 1) ? '' : 'display:none;'; ?>
                                            margin-top:12px; padding:6px 12px; border-radius:4px;">
                                            &times; Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>


            <button type="button" id="add-qualification" class="next next-level" onclick="addQualificationRow()"
                style="margin-bottom:10px;">
                + Add another qualification
            </button>
            <p style="font-size:12px;margin-top:0;margin-bottom:18px;">
                You can add up to 5 qualifications.
            </p>

            <h3 style="margin-top:20px;">First Aid Certificate</h3>

            <label class="required">Upload First Aid Certificate:</label>
            <input type="file" name="first_aid_certificate" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                class="<?php echo !empty($field_errors['first_aid_certificate']) ? 'is-invalid' : ''; ?>">

            <?php if (!empty($field_errors['first_aid_certificate'])): ?>
                <div class="invalid-feedback"><?php echo esc_html($field_errors['first_aid_certificate']); ?></div>
            <?php endif; ?>

            <?php
            $saved_first_aid = 0;
            if (!empty($resume_user_id)) {
                $saved_first_aid = (int) get_user_meta($resume_user_id, 'first_aid_certificate', true);
            } elseif (!empty($prefill_user_id)) {
                $saved_first_aid = (int) get_user_meta($prefill_user_id, 'first_aid_certificate', true);
            }
            ?>
            <?php if (!empty($saved_first_aid)): ?>
                <div style="margin-top:6px; font-size:13px;">
                    <strong>Already uploaded:</strong><br>
                    <a href="<?php echo esc_url(wp_get_attachment_url($saved_first_aid)); ?>" target="_blank">View</a>
                </div>
            <?php endif; ?>


            <h3>Payment:</h3>
            <table class="table-payment">
                <tr>
                    <td>12-Month Certification  (inclusive GST)</td>
                    <td>20,000 PKR</td>
                </tr>
                <tr>
                    <td><strong>TOTAL</strong></td>
                    <td><strong>20,000 PKR</strong></td>
                </tr>
            </table>

            <div class="terms">
                <input type="checkbox" name="terms" value="1" <?php checked(isset($_POST['terms']), '1'); ?>
                    class="<?php echo !empty($field_errors['terms']) ? 'is-invalid' : ''; ?>">
                <label class="required">I agree with terms</label>
            </div>
            <?php if (!empty($field_errors['terms'])): ?>
                <div class="invalid-feedback" style="text-align:right;"><?php echo esc_html($field_errors['terms']); ?></div>
            <?php endif; ?>

            <div class="button-container">
                <button type="button" class="back" onclick="prevStep()">Back</button>
                <button type="submit" class="submit" name="reg_step" value="complete">Submit</button>
            </div>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const formEl = document.getElementById('regForm');
            if (!formEl) return;


            let currentStep = parseInt(formEl.dataset.startStep || '0', 10);
            const steps = document.querySelectorAll('.step');
            const indicators = document.querySelectorAll('.step-indicator');
            const maxQualifications = 5;

            function updateRequired(n) {
                steps.forEach((stepEl, idx) => {
                    const fields = stepEl.querySelectorAll('input, select, textarea');
                    fields.forEach(field => {
                        if (field.hasAttribute('required') && !field.dataset.originalRequired) {
                            field.dataset.originalRequired = '1';
                        }
                        if (idx === n) {
                            if (field.dataset.originalRequired === '1') field.setAttribute('required', 'required');
                        } else {
                            if (field.dataset.originalRequired === '1') field.removeAttribute('required');
                        }
                    });
                });
            }

            function showStep(n) {
                steps.forEach((s, i) => {
                    s.style.display = (i === n ? 'block' : 'none');
                });
                indicators.forEach((ind, i) => {
                    ind.classList.toggle('active', i === n);
                });
                updateRequired(n);
            }

            function toggleQualificationRemoveButtons() {
                const wrapper = document.getElementById('qualifications-wrapper');
                if (!wrapper) return;
                const rows = wrapper.querySelectorAll('.qualification-row');
                rows.forEach(row => {
                    const btn = row.querySelector('.remove-qualification');
                    if (!btn) return;
                    btn.style.display = (rows.length > 1) ? 'inline-block' : 'none';
                });
            }

            function renumberQualificationCertificateInputs() {
                const wrapper = document.getElementById('qualifications-wrapper');
                if (!wrapper) return;
                const rows = wrapper.querySelectorAll('.qualification-row');
                rows.forEach((row, idx) => {
                    row.querySelectorAll('input[name^="qualification_certificates["]').forEach(inp => {
                        inp.name = `qualification_certificates[${idx}][]`;
                    });
                });
            }

            window.addQualificationRow = function() {
                const wrapper = document.getElementById('qualifications-wrapper');
                if (!wrapper) return;

                const rows = wrapper.querySelectorAll('.qualification-row');
                if (rows.length >= maxQualifications) return;

                const firstRow = rows[0];
                const clone = firstRow.cloneNode(true);

                /* -----------------------------
                 * 1️⃣ Clear inputs
                 * ----------------------------- */
                clone.querySelectorAll('input').forEach(input => {
                    if (input.type === 'text' || input.type === 'date') input.value = '';
                    if (input.type === 'file') input.value = '';
                });

                /* -----------------------------
                 * 2️⃣ Remove uploaded cert UI
                 * ----------------------------- */
                const uploadedBox = clone.querySelector('.uploaded-certs');
                if (uploadedBox) uploadedBox.remove();

                /* -----------------------------
                 * 3️⃣ REMOVE old hidden inputs (🔥 IMPORTANT)
                 * ----------------------------- */
                clone.querySelectorAll(
                    'input[name="qualification_id[]"], .removed-certificates-input'
                ).forEach(el => el.remove());

                /* -----------------------------
                 * 4️⃣ Generate new qualification ID
                 * ----------------------------- */
                const newQualId =
                    'qual_' + Date.now() + '_' + Math.floor(Math.random() * 1000);

                clone.setAttribute('data-qid', newQualId);

                /* -----------------------------
                 * 5️⃣ Add qualification_id hidden input
                 * ----------------------------- */
                const qualIdInput = document.createElement('input');
                qualIdInput.type = 'hidden';
                qualIdInput.name = 'qualification_id[]';
                qualIdInput.value = newQualId;
                clone.appendChild(qualIdInput);

                /* -----------------------------
                 * 6️⃣ Add removed-certificates hidden input (empty)
                 * ----------------------------- */
                const removedCertInput = document.createElement('input');
                removedCertInput.type = 'hidden';
                removedCertInput.className = 'removed-certificates-input';
                removedCertInput.name = `removed_certificate_ids[${newQualId}]`;
                removedCertInput.value = '';
                clone.appendChild(removedCertInput);

                /* -----------------------------
                 * 7️⃣ Append & re-index
                 * ----------------------------- */
                wrapper.appendChild(clone);
                toggleQualificationRemoveButtons();
                renumberQualificationCertificateInputs();
            };

            let removedCerts = [];

            window.removeQualification = function(button) {
                const wrapper = document.getElementById('qualifications-wrapper');
                if (!wrapper) return;
                const row = button.closest('.qualification-row');
                if (!row) return;

                // get all uploaded cert IDs in this row
                row.querySelectorAll('[data-cert-id]').forEach(el => {
                    const cid = el.getAttribute('data-cert-id');
                    if (cid) {
                        removedCerts.push(cid);
                    }
                })
                row.remove();
                toggleQualificationRemoveButtons();
                renumberQualificationCertificateInputs();
            };

            // Attach click event to all current and future Remove buttons
            document.querySelectorAll('.uploaded-certs .remove-certificate').forEach(function(btn) {

                btn.addEventListener('click', function() {

                    const certId = this.dataset.certId;
                    if (!certId) return;

                    // 🔹 find the current qualification row
                    const row = this.closest('.qualification-row');
                    if (!row) return;

                    // 🔹 hidden input specific to this qualification
                    const hiddenInput = row.querySelector('.removed-certificates-input');
                    if (!hiddenInput) return;

                    // 🔹 read existing removed IDs for THIS row
                    let removedIds = hiddenInput.value ?
                        hiddenInput.value.split(',').map(id => id.trim()) : [];

                    // 🔹 append if not already present
                    if (!removedIds.includes(certId)) {
                        removedIds.push(certId);
                    }

                    // 🔹 update hidden input
                    hiddenInput.value = removedIds.join(',');

                    // 🔹 remove certificate from DOM
                    const certDiv = this.closest('.cert-item');
                    if (certDiv) certDiv.remove();
                });

            });


            window.prevStep = function() {
                if (currentStep > 0) {
                    currentStep--;
                    showStep(currentStep);
                }
            };

            toggleQualificationRemoveButtons();
            renumberQualificationCertificateInputs();
            showStep(currentStep);
        });

        const phoneInput = document.getElementById('phoneNumber');

        if (phoneInput) {
            phoneInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
        const nicInput = document.querySelector("input[name='nid']");
        if (nicInput) {
            nicInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9-]/g, '');
            });
        }
    </script>

    <?php
    return ob_get_clean();
}

/* ============================================================
   FILE UPLOAD HANDLER
============================================================ */
function gym_handle_file_upload($file)
{
    if ($file && !empty($file['name'])) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $overrides = ['test_form' => false];
        $upload = wp_handle_upload($file, $overrides);

        if (isset($upload['file'])) {
            $attachment = [
                'post_mime_type' => $upload['type'],
                'post_title' => sanitize_file_name($file['name']),
                'post_content' => '',
                'post_status' => 'inherit'
            ];
            $attach_id = wp_insert_attachment($attachment, $upload['file']);
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);
            return $attach_id;
        }
    }
    return false;
}

/* ============================================================
   HANDLE FORM (Step 1 + Step 2 + Final submit + New Registration)
============================================================ */
add_action('init', 'gym_trainer_handle_form');
function gym_trainer_handle_form()
{
    // Nonce check (already in your code)
    if (empty($_POST['gym_trainer_register_nonce'])) {
        return;
    }
    if (!wp_verify_nonce($_POST['gym_trainer_register_nonce'], 'gym_trainer_register')) {
        return;
    }

    /**
     * ----------------------------------------
     * 1) Start New Registration (button click)
     * ----------------------------------------
     */
    if (!empty($_POST['gtr_start_new_registration'])) {

        // Clear resume cookie
        gtr_resume_clear();

        wp_safe_redirect(site_url('/registration/'));
        // Optional: also unset resume GET vars safety
        // unset($_GET['trainer_resume'], $_GET['key']);
        return;
    }

    global $gym_trainer_errors;
    $gym_trainer_errors = [];

    $step = isset($_POST['reg_step']) ? sanitize_text_field($_POST['reg_step']) : 'complete';

    // Step 1 fields
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $pass = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';
    $firstName = isset($_POST['firstName']) ? sanitize_text_field($_POST['firstName']) : '';
    $lastName = isset($_POST['lastName']) ? sanitize_text_field($_POST['lastName']) : '';
    $city = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : '';
    $mobile = isset($_POST['mobile']) ? sanitize_text_field($_POST['mobile']) : '';
    $dob = isset($_POST['dob']) ? sanitize_text_field($_POST['dob']) : '';
    $gender = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : '';
    $nationality = isset($_POST['nationality']) ? sanitize_text_field($_POST['nationality']) : '';
    $nid = isset($_POST['nid']) ? sanitize_text_field($_POST['nid']) : '';

    // Find existing user by forced id (resume) OR by email
    $existing_trainer_id = 0;
    $existing_progress = '';
    $existing_status = '';

    $forced_user_id = isset($_POST['existing_user_id']) ? (int) $_POST['existing_user_id'] : 0;
    if ($forced_user_id > 0) {
        $existing_trainer_id = $forced_user_id;
        $u = get_user_by('id', $existing_trainer_id);
        if ($u)
            $email = $u->user_email;
        $existing_progress = get_user_meta($existing_trainer_id, 'registration_progress', true);
        $existing_status = get_user_meta($existing_trainer_id, 'approval_status', true);
    } else {
        $existing_user = $email ? get_user_by('email', $email) : null;
        if ($existing_user) {

            $existing_trainer_id = $existing_user->ID;
            $existing_progress = get_user_meta($existing_trainer_id, 'registration_progress', true);
            $existing_status = get_user_meta($existing_trainer_id, 'approval_status', true);
        }
    }

    /* -----------------------------------------
       STEP 1
    ------------------------------------------*/
    if ($step === '1') {
        $errors = [];

        /* =========================
         * BASIC REQUIRED + LENGTH
         * ========================= */
        if ($firstName === '') {
            $errors['firstName'] = 'First Name is required.';
        } elseif (strlen($firstName) > 50) {
            $errors['firstName'] = 'First Name must not exceed 50 characters.';
        }

        if ($lastName === '') {
            $errors['lastName'] = 'Last Name is required.';
        } elseif (strlen($lastName) > 50) {
            $errors['lastName'] = 'Last Name must not exceed 50 characters.';
        }

        if ($city === '') {
            $errors['city'] = 'City is required.';
        } elseif (strlen($city) > 50) {
            $errors['city'] = 'City must not exceed 50 characters.';
        }

        /* =========================
         * EMAIL
         * ========================= */
        if (empty($email)) {
            $errors['email'] = 'Email is required.';
        } elseif (strlen($email) > 100 || !is_email($email)) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        /* =========================
         * MOBILE
         * ========================= */
        if ($mobile === '') {
            $errors['mobile'] = 'Mobile number is required.';
        } elseif (!preg_match('/^[0-9]{7,15}$/', $mobile)) {
            $errors['mobile'] = 'Mobile number must contain only numbers (7–15 digits).';
        }

        /* =========================
         * DOB
         * ========================= */
        if ($dob === '') {
            $errors['dob'] = 'Date of Birth is required.';
        } else {
            $date = DateTime::createFromFormat('Y-m-d', $dob);
            $today = date('Y-m-d');

            // ❌ Invalid format
            if (!$date || $date->format('Y-m-d') !== $dob) {
                $errors['dob'] = 'Invalid Date of Birth.';
            }
            // ❌ Future date not allowed
            elseif ($dob > $today) {
                $errors['dob'] = 'Date of Birth cannot be in the future.';
            }
        }

        /* =========================
         * GENDER
         * ========================= */
        if ($gender === '') {
            $errors['gender'] = 'Gender is required.';
        }

        /* =========================
         * NATIONALITY
         * ========================= */
        if ($nationality === '') {
            $errors['nationality'] = 'Nationality is required.';
        } elseif (strlen($nationality) > 50) {
            $errors['nationality'] = 'Nationality must not exceed 50 characters.';
        }

        /* =========================
         * NATIONAL ID
         * ========================= */
        if ($nid === '') {
            $errors['nid'] = 'National ID is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9\-]{5,30}$/', $nid)) {
            $errors['nid'] = 'National ID must be valid and up to 30 characters.';
        }

        /* =========================
         * PASSWORD (NEW USER ONLY)
         * ========================= */
        if (!$existing_trainer_id) {
            if (empty($pass)) {
                $errors['password'] = 'Password is required.';
            } elseif (
                strlen($pass) < 8 ||
                !preg_match('/[A-Z]/', $pass) ||
                !preg_match('/[a-z]/', $pass) ||
                !preg_match('/[0-9]/', $pass) ||
                !preg_match('/[\W_]/', $pass)
            ) {
                $errors['password'] =
                    'Password must be at least 8 characters long and include uppercase, lowercase, number and special character.';
            }
        }

        /* =========================
         * REQUIRED FILES
         * ========================= */
        $need_front = !$existing_trainer_id || !get_user_meta($existing_trainer_id, 'nidPhotoFront', true);
        $need_back = !$existing_trainer_id || !get_user_meta($existing_trainer_id, 'nidPhotoBack', true);
        $need_photo = !$existing_trainer_id || !get_user_meta($existing_trainer_id, 'photo', true);

        /* =========================
        * FILE VALIDATION (SIZE + MIME)
        * ========================= */
        $imageMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/jpg',
            'image/webp'
        ];

        // National ID Front (required)
        $err = validate_uploaded_file('nidPhotoFront',  $need_front, $imageMimeTypes, 'National ID Photo (Front)');
        if ($err) {
            $errors['nidPhotoFront'] = $err;
        }

        // National ID Back (required)
        $err = validate_uploaded_file('nidPhotoBack',  $need_back, $imageMimeTypes, 'National ID Photo (Back)');
        if ($err) {
            $errors['nidPhotoBack'] = $err;
        }

        // Profile Photo (optional OR required — change true/false as needed)
        $err = validate_uploaded_file('photo', $need_photo, $imageMimeTypes, 'Your Profile Photo');
        if ($err) {
            $errors['photo'] = $err;
        }

        if (!empty($errors)) {
            $gym_trainer_errors = $errors;
            $_POST['start_step'] = 0;
            return;
        }

        // Block re-registration if complete/Active
        // if ($existing_trainer_id && ($existing_progress === 'complete' || $existing_status === 'Active')) {
        if ($existing_trainer_id && ($existing_progress === 'complete' || in_array($existing_status, ['Active', 'Provisional'], true))) {

            $gym_trainer_errors['email'] = 'An account with this email has already completed registration.';
            $_POST['start_step'] = 0;
            return;
        }

        if ($existing_trainer_id) {
            $userdata = [
                'ID' => $existing_trainer_id,
                'user_email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            if (!empty($pass))
                $userdata['user_pass'] = $pass;
            $user_id = wp_update_user($userdata);
        } else {
            $userdata = [
                'user_login' => 'AFPPK' . rand(1000, 9999),
                'user_email' => $email,
                'user_pass' => $pass,
                'role' => 'trainer',
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            $user_id = wp_insert_user($userdata);
        }

        if (is_wp_error($user_id))
            return;

        update_user_meta($user_id, 'city', $city);
        update_user_meta($user_id, 'mobile', $mobile);
        update_user_meta($user_id, 'dob', $dob);
        update_user_meta($user_id, 'gender', $gender);
        update_user_meta($user_id, 'nationality', $nationality);
        update_user_meta($user_id, 'nid', $nid);

        if (!empty($_FILES['nidPhotoFront']['name'])) {
            $front_id = gym_handle_file_upload($_FILES['nidPhotoFront']);
            if ($front_id)
                update_user_meta($user_id, 'nidPhotoFront', $front_id);
        }
        if (!empty($_FILES['nidPhotoBack']['name'])) {
            $back_id = gym_handle_file_upload($_FILES['nidPhotoBack']);
            if ($back_id)
                update_user_meta($user_id, 'nidPhotoBack', $back_id);
        }
        if (!empty($_FILES['photo']['name'])) {
            $photo_id = gym_handle_file_upload($_FILES['photo']);
            if ($photo_id)
                update_user_meta($user_id, 'photo', $photo_id);
        }

        // $current_status = get_user_meta($user_id, 'approval_status', true);
        // if ($current_status !== 'Active') update_user_meta($user_id, 'approval_status', 'Inactive');

        $current_status = get_user_meta($user_id, 'approval_status', true);
        if (!in_array($current_status, ['Active', 'Provisional'], true)) {
            update_user_meta($user_id, 'approval_status', 'Inactive');
        }


        update_user_meta($user_id, 'registration_progress', 'step1');
        gtr_resume_set($user_id);

        $_POST['start_step'] = 1;
        return;
    }

    /* -----------------------------------------
       STEP 2
    ------------------------------------------*/
    if ($step === '2') {
        $jobTitle = isset($_POST['jobTitle']) ? sanitize_text_field($_POST['jobTitle']) : '';
        $gymName = isset($_POST['gymName']) ? sanitize_text_field($_POST['gymName']) : '';
        $branchName = isset($_POST['branchName']) ? sanitize_text_field($_POST['branchName']) : '';
        $levels = isset($_POST['level']) && is_array($_POST['level']) ? $_POST['level'] : [];

        $errors = [];

        /* =========================
         * TEXT FIELD VALIDATIONS
         * ========================= */
        if ($jobTitle === '') {
            $errors['jobTitle'] = 'Job Title is required.';
        } elseif (strlen($jobTitle) > 100) {
            $errors['jobTitle'] = 'Job Title must not exceed 100 characters.';
        }

        if ($gymName === '') {
            $errors['gymName'] = 'Gym Name is required.';
        } elseif (strlen($gymName) > 100) {
            $errors['gymName'] = 'Gym Name must not exceed 100 characters.';
        }

        if ($branchName === '') {
            $errors['branchName'] = 'Branch is required.';
        } elseif (strlen($branchName) > 50) {
            $errors['branchName'] = 'Branch must not exceed 50 characters.';
        }

        /* =========================
         * LEVEL VALIDATION
         * ========================= */
        if (empty($levels)) {
            $errors['level'] = 'Please select at least one Level of Registration.';
        }

        $need_cv = !$existing_trainer_id || !get_user_meta($existing_trainer_id, 'cv', true);
        if ($need_cv) {
            $cvError = validate_uploaded_file('cv', true, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
            if ($cvError)
                $errors['cv'] = $cvError;
        }

        if (!empty($errors)) {
            $gym_trainer_errors = $errors;
            $_POST['start_step'] = 1;
            return;
        }

        if ($existing_trainer_id) {
            $user_id = $existing_trainer_id;
        } else {
            // fallback create (rare)
            $userdata = [
                'user_login' => 'AFPPK' . rand(1000, 9999),
                'user_email' => $email,
                'user_pass' => $pass,
                'role' => 'trainer',
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            $user_id = wp_insert_user($userdata);
            if (is_wp_error($user_id))
                return;
        }

        update_user_meta($user_id, 'jobTitle', $jobTitle);
        update_user_meta($user_id, 'gymName', $gymName);
        update_user_meta($user_id, 'branchName', $branchName);
        update_user_meta($user_id, 'level', array_map('sanitize_text_field', $levels));

        if (!empty($_FILES['cv']['name'])) {
            $cv_id = gym_handle_file_upload($_FILES['cv']);
            if ($cv_id)
                update_user_meta($user_id, 'cv', $cv_id);
        }

        // $current_status = get_user_meta($user_id, 'approval_status', true);
        // if ($current_status !== 'Active') update_user_meta($user_id, 'approval_status', 'Inactive');
        $current_status = get_user_meta($user_id, 'approval_status', true);
        if (!in_array($current_status, ['Active', 'Provisional'], true)) {
            update_user_meta($user_id, 'approval_status', 'Inactive');
        }


        update_user_meta($user_id, 'registration_progress', 'step2');
        gtr_resume_set($user_id);

        $_POST['start_step'] = 2;
        return;
    }

    /* -----------------------------------------
       FINAL SUBMIT
    ------------------------------------------*/
    if ($step === 'complete') {
        $jobTitle = isset($_POST['jobTitle']) ? sanitize_text_field($_POST['jobTitle']) : '';
        $gymName = isset($_POST['gymName']) ? sanitize_text_field($_POST['gymName']) : '';
        $branchName = isset($_POST['branchName']) ? sanitize_text_field($_POST['branchName']) : '';

        $levels = isset($_POST['level']) && is_array($_POST['level']) ? $_POST['level'] : [];
        $terms = isset($_POST['terms']) ? $_POST['terms'] : '';

        $courseNames = isset($_POST['courseName']) && is_array($_POST['courseName']) ? array_map('sanitize_text_field', $_POST['courseName']) : [];
        $institutionNames = isset($_POST['institutionName']) && is_array($_POST['institutionName']) ? array_map('sanitize_text_field', $_POST['institutionName']) : [];
        $completionDates = isset($_POST['completionDate']) && is_array($_POST['completionDate']) ? array_map('sanitize_text_field', $_POST['completionDate']) : [];

        $errors = [];
        $qualifications = [];
        $seenCourses = [];

        $existingQualifications = [];
        if ($existing_trainer_id) {
            $existingQualifications = get_user_meta($existing_trainer_id, 'qualifications', true);
            if (!is_array($existingQualifications))
                $existingQualifications = [];
        }

        /**
         * 1️⃣ Handle removed certificates (qualification-wise)
         * Input format:
         * removed_certificate_ids = [
         *   qual_xxx => "12,15",
         *   qual_yyy => "22"
         * ]
         */

        $removedCertificates = $_POST['removed_certificate_ids'] ?? [];
        if (
            !empty($existing_trainer_id) &&
            !empty($existingQualifications) &&
            is_array($removedCertificates)
        ) {

            foreach ($removedCertificates as $qualId => $idsCsv) {

                if (!$qualId || !$idsCsv) {
                    continue;
                }

                // 🔹 Convert CSV → int array
                $ids = array_values(array_filter(
                    array_map('intval', explode(',', $idsCsv))
                ));

                if (empty($ids)) {
                    continue;
                }

                /**
                 * 🔹 Remove cert IDs from THIS qualification only
                 */
                foreach ($existingQualifications as &$q) {

                    if (
                        empty($q['qual_id']) ||
                        $q['qual_id'] !== $qualId ||
                        empty($q['certificate_ids']) ||
                        !is_array($q['certificate_ids'])
                    ) {
                        continue;
                    }

                    $q['certificate_ids'] = array_values(
                        array_diff(
                            array_map('intval', $q['certificate_ids']),
                            $ids
                        )
                    );
                }
                unset($q);

                /**
                 * 🔥 Permanently delete attachments (AFTER unlinking from meta)
                 */
                foreach ($ids as $aid) {
                    if ($aid > 0) {
                        wp_delete_attachment($aid, true);
                    }
                }
            }
        }


        $qualifications = [];
        $seenCourses = [];

        foreach ($courseNames as $idx => $course) {
            $course = trim($course);
            $inst = trim($institutionNames[$idx] ?? '');
            $date = trim($completionDates[$idx] ?? '');

            if ($course === '' && $inst === '' && $date === '')
                continue;

            $errorsRow = false;

            // REQUIRED fields
            if ($course === '') {
                $errors['courseName'][$idx] = 'Course name is required.';
                $errorsRow = true;
            }
            if ($inst === '') {
                $errors['institutionName'][$idx] = 'Institution is required.';
                $errorsRow = true;
            }
            if ($date === '') {
                $errors['completionDate'][$idx] = 'Completion date is required.';
                $errorsRow = true;
            }
            if ($errorsRow)
                continue;

            // LENGTH validation
            if (strlen($course) > 100)
                $errors['courseName'][$idx] = 'Course name must not exceed 100 characters.';
            if (strlen($inst) > 100)
                $errors['institutionName'][$idx] = 'Institution name must not exceed 100 characters.';

            // DATE validation
            $dt = DateTime::createFromFormat('Y-m-d', $date);
            if (!$dt || $dt->format('Y-m-d') !== $date)
                $errors['completionDate'][$idx] = 'Invalid completion date.';

            // DUPLICATE COURSE
            $courseKey = strtolower($course);
            if (in_array($courseKey, $seenCourses, true)) {
                $errors['courseName'][$idx] = 'Duplicate course names are not allowed.';
            } else {
                $seenCourses[] = $courseKey;
            }

            // =========================
            // CERTIFICATES
            // =========================
            // Get existing certs for this row by unique_id
            $uniqueId = $_POST['qualification_id'][$idx] ?? uniqid('qual_');
            $certIds = [];

            // Try to find in existingQualifications by qual_id
            foreach ($existingQualifications as $q) {
                if (!empty($q['qual_id']) && $q['qual_id'] == $uniqueId) {
                    $certIds = $q['certificate_ids'] ?? [];
                    break;
                }
            }

            $existingFileHashes = [];
            foreach ($certIds as $cid) {
                $path = get_attached_file($cid);
                if ($path && file_exists($path))
                    $existingFileHashes[] = md5_file($path);
            }

            // Handle new uploads
            if (!empty($_FILES['qualification_certificates']['name'][$idx])) {
                foreach ($_FILES['qualification_certificates']['name'][$idx] as $k => $name) {
                    if ($name === '')
                        continue;
                    if (count($certIds) >= 5) {
                        $errors['qualification_certificates'][$idx] = 'You can upload a maximum of 5 certificates per qualification.';
                        break;
                    }

                    if (($_FILES['qualification_certificates']['size'][$idx][$k] ?? 0) > GTR_MAX_FILE_SIZE) {
                        $errors['qualification_certificates'][$idx] = 'Each certificate must not exceed 10MB.';
                        continue;
                    }

                    $tmp = $_FILES['qualification_certificates']['tmp_name'][$idx][$k] ?? '';
                    if ($tmp && file_exists($tmp)) {
                        $mime = mime_content_type($tmp);
                        $allowed = [
                            'image/jpeg',
                            'image/png',
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        ];
                        if (!in_array($mime, $allowed, true)) {
                            $errors['qualification_certificates'][$idx] =
                                'Invalid certificate file type. Allowed types: PDF, DOC, DOCX, JPG, PNG';
                            continue;
                        }

                        $hash = md5_file($tmp);
                        if (in_array($hash, $existingFileHashes, true))
                            continue;
                    }

                    // Upload
                    $file = [
                        'name' => $name,
                        'type' => $_FILES['qualification_certificates']['type'][$idx][$k],
                        'tmp_name' => $tmp,
                        'error' => $_FILES['qualification_certificates']['error'][$idx][$k],
                        'size' => $_FILES['qualification_certificates']['size'][$idx][$k],
                    ];

                    $file_id = gym_handle_file_upload($file);
                    if ($file_id) {
                        $certIds[] = $file_id;
                        $uploadedPath = get_attached_file($file_id);
                        if ($uploadedPath && file_exists($uploadedPath))
                            $existingFileHashes[] = md5_file($uploadedPath);
                    }
                }
            }

            $certIds = array_values(array_unique(array_map('intval', $certIds)));

            if (empty($certIds)) {
                $errors['qualification_certificates'][$idx] = 'Please upload at least one certificate for this qualification.';
                continue;
            }

            $qualifications[] = [
                'qual_id' => $uniqueId, // important for JS-server mapping
                'courseName' => $course,
                'institutionName' => $inst,
                'completionDate' => $date,
                'certificate_ids' => $certIds,
            ];

            if (count($qualifications) >= 5)
                break;
        }

        // Save
        if (!empty($qualifications)) {
            update_user_meta($existing_trainer_id, 'qualifications', $qualifications);
        }


        // First Aid certificate required (single file) unless already uploaded
        $existing_first_aid = 0;
        if ($existing_trainer_id) {
            $existing_first_aid = (int) get_user_meta($existing_trainer_id, 'first_aid_certificate', true);
        }

        if (!$existing_first_aid && empty($_FILES['first_aid_certificate']['name'])) {
            $errors['first_aid_certificate'] = 'Please upload your First Aid certificate.';
        }
        // Upload First Aid certificate (single file)
        if (!empty($_FILES['first_aid_certificate']['name'])) {

            // 1️⃣ Size validation (1MB)
            if (($_FILES['first_aid_certificate']['size'] ?? 0) > GTR_MAX_FILE_SIZE) {
                $errors['first_aid_certificate'] = 'First Aid certificate must not exceed 10MB.';
            }
            // 2️⃣ MIME validation
            elseif (!empty($_FILES['first_aid_certificate']['tmp_name'])) {

                $mime = mime_content_type($_FILES['first_aid_certificate']['tmp_name']);
                $allowed = [
                    'image/jpeg',
                    'image/png',
                    'application/pdf',
                    'application/msword', // .doc
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ];

                if (!in_array($mime, $allowed, true)) {
                    $errors['first_aid_certificate'] =
                        'Invalid certificate file type. Allowed types: PDF, DOC, DOCX, JPG, PNG';
                }
            }

            // 3️⃣ Upload only if no error
            if (empty($errors['first_aid_certificate'])) {
                $fa_id = gym_handle_file_upload($_FILES['first_aid_certificate']);
                if ($fa_id) {
                    update_user_meta($existing_trainer_id, 'first_aid_certificate', $fa_id);
                }
            }
        }


        if (empty($levels) && $existing_trainer_id) {
            $saved_levels = get_user_meta($existing_trainer_id, 'level', true);
            if (is_array($saved_levels))
                $levels = $saved_levels;
        }
        if (empty($levels))
            $errors['level'] = 'Please select at least one Level of Registration.';
        if ($terms != '1')
            $errors['terms'] = 'You must agree with the terms to submit the registration.';

        if (!empty($errors)) {
            $gym_trainer_errors = $errors;
            $_POST['start_step'] = 2;
            return;
        }

        // Ensure user exists
        if ($existing_trainer_id) {
            $user_id = $existing_trainer_id;
            $userdata = [
                'ID' => $user_id,
                'user_email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            if (!empty($pass))
                $userdata['user_pass'] = $pass;
            wp_update_user($userdata);
        } else {
            $userdata = [
                'user_login' => 'AFPPK' . rand(1000, 9999),
                'user_email' => $email,
                'user_pass' => $pass,
                'role' => 'trainer',
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            $user_id = wp_insert_user($userdata);
            if (is_wp_error($user_id))
                return;
        }

        $fields = [
            'city' => $city,
            'mobile' => $mobile,
            'dob' => $dob,
            'nid' => $nid,
            'gender' => $gender,
            'nationality' => $nationality,
            'jobTitle' => $jobTitle,
            'gymName' => $gymName,
            'branchName' => $branchName,
        ];

        foreach ($fields as $key => $val) {
            if ($val !== '')
                update_user_meta($user_id, $key, $val);
        }

        update_user_meta($user_id, '  ', $qualifications);


        $attachments = [
            'nidPhotoFront' => 'nidPhotoFront',
            'nidPhotoBack' => 'nidPhotoBack',
            'photo' => 'photo',
            'cv' => 'cv'
        ];
        foreach ($attachments as $input_name => $meta_key) {
            if (!empty($_FILES[$input_name]['name'])) {
                $file_id = gym_handle_file_upload($_FILES[$input_name]);
                if ($file_id)
                    update_user_meta($user_id, $meta_key, $file_id);
            }
        }

        $expiry_date = date('Y-m-d', strtotime('+1 year'));
        update_user_meta($user_id, 'expiry_date', $expiry_date);

        update_user_meta($user_id, 'level', array_map('sanitize_text_field', $levels));

        // $current_status = get_user_meta($user_id, 'approval_status', true);
        // if ($current_status !== 'Active') update_user_meta($user_id, 'approval_status', 'Inactive');
        $current_status = get_user_meta($user_id, 'approval_status', true);
        if (!in_array($current_status, ['Active', 'Provisional'], true)) {
            update_user_meta($user_id, 'approval_status', 'Inactive');
        }


        // 1) Create a unique basket/transaction id (must be unique)
        $basket_id = 'REG-' . $user_id . '-' . time();

        // 2) Store pending registration info (so you can finalize later)
        update_user_meta($user_id, 'registration_progress', 'payment_pending');
        gtr_resume_set($user_id);
        update_user_meta($user_id, 'payfast_basket_id', $basket_id);

        // // (Optional but recommended: store everything needed to email & finalize)
        // update_user_meta($user_id, 'reg_pending_payload', [
        //     'firstName' => $firstName,
        //     'lastName'  => $lastName,
        //     'email'     => $email,
        //     'city'      => $city,
        //     'mobile'    => $mobile,
        //     'referer'   => !empty($_POST['_wp_http_referer']) ? esc_url_raw($_POST['_wp_http_referer']) : home_url('/'),
        // ]);

        // 3) Redirect to your payment start endpoint (your plugin will generate PayFast redirect)
        $start_payment_url = add_query_arg([
            'action'   => 'gtr_start_payfast_payment', // admin_post hook
            'user_id'  => $user_id,
            'basket_id' => $basket_id,
        ], admin_url('admin-post.php'));

        wp_safe_redirect($start_payment_url);
        exit;
    }
}

/* ============================================================
   CUSTOM EMAIL FROM NAME & ADDRESS
============================================================ */
add_filter('wp_mail_from_name', function ($name) {
    return 'Association of Fitness Professionals Registration';
});
add_filter('wp_mail_from', function ($email) {
    return 'info@repspakistan.com';
});

/* ============================================================
   APPROVAL EMAIL RELIABLE FIX (capture old before save)
============================================================ */
add_action('personal_options_update', 'td_capture_old_status_before_save', 5);
add_action('edit_user_profile_update', 'td_capture_old_status_before_save', 5);
function td_capture_old_status_before_save($user_id)
{
    if (!current_user_can('edit_user', $user_id))
        return;
    $old = get_user_meta($user_id, 'approval_status', true);
    update_user_meta($user_id, '_approval_status_old', $old);
}

/* ============================================================
   SEND EMAIL WHEN TRAINER IS APPROVED
============================================================ */
add_action('edit_user_profile_update', 'notify_trainer_on_approval', 20);
add_action('personal_options_update', 'notify_trainer_on_approval', 20);

function notify_trainer_on_approval($user_id)
{
    if (!current_user_can('edit_user', $user_id))
        return;
    if (!isset($_POST['approval_status']))
        return;

    $old_status = get_user_meta($user_id, '_approval_status_old', true);
    $new_status = sanitize_text_field($_POST['approval_status']);

    // if ($old_status !== 'Active' && $new_status === 'Active') {
    if (!in_array($old_status, ['Active', 'Provisional'], true) && in_array($new_status, ['Active', 'Provisional'], true)) {

        $user = get_user_by('id', $user_id);
        if (!$user)
            return;

        $to = $user->user_email;
        $subject = "Your Trainer Account Has Been Approved";

        $message = "Hello " . $user->first_name . ",\n\n"
            . "Good news! Your trainer account has been approved.\n\n"
            . "You are now active in the member directory and can log in at any time.\n\n"
            . "Thank you,\nAssociation of Fitness Professionals\n";

        wp_mail($to, $subject, $message);

        update_user_meta($user_id, 'is_directory_visible', 'yes');
    }

    delete_user_meta($user_id, '_approval_status_old');
}

/* ============================================================
   ADMIN PROFILE VIEW
============================================================ */
add_action('show_user_profile', 'show_trainer_fields');
add_action('edit_user_profile', 'show_trainer_fields');

function show_trainer_fields($user)
{
    if (!in_array('trainer', (array) $user->roles, true)) {
        return;
    }

    $fields = [
        'city'        => 'City',
        'mobile'      => 'Mobile',
        'dob'         => 'DOB',
        'nid'         => 'National ID',
        'gender'      => 'Gender',
        'nationality' => 'Nationality',
        'jobTitle'    => 'Job Title',
        'gymName'     => 'Gym Name',
        'branchName'  => 'Branch Name',
    ];

    $flash = gtr_admin_flash_get($user->ID);

    $vals_override = is_array($flash['values'] ?? null) ? $flash['values'] : [];
    $qualifications_override = is_array($flash['qualifications'] ?? null) ? $flash['qualifications'] : null;
    $errors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];


    // Multiple Qualifications (ADMIN) — show only saved rows
    $qualifications = is_array($qualifications_override)
        ? $qualifications_override
        : get_user_meta($user->ID, 'qualifications', true);

    // Backwards compatibility: if no array yet, try single fields
    if (!is_array($qualifications) || empty($qualifications)) {
        $single_course = get_user_meta($user->ID, 'courseName', true);
        $single_inst   = get_user_meta($user->ID, 'institutionName', true);
        $single_date   = get_user_meta($user->ID, 'completionDate', true);

        if ($single_course || $single_inst || $single_date) {
            $qualifications = [
                [
                    'qual_id'         => gym_generate_qualification_uid(),
                    'courseName'      => $single_course,
                    'institutionName' => $single_inst,
                    'completionDate'  => $single_date,
                    'certificate_ids' => (array) get_user_meta($user->ID, 'certificates', true), // optional legacy
                ]
            ];
        } else {
            $qualifications = [];
        }
    }

    // Hard cap at 5 (in case old data has more)
    $qualifications = array_slice((array) $qualifications, 0, 5);

    $first_aid = (int) get_user_meta($user->ID, 'first_aid_certificate', true);
    $front     = get_user_meta($user->ID, 'nidPhotoFront', true);
    $back      = get_user_meta($user->ID, 'nidPhotoBack', true);
    $photo     = get_user_meta($user->ID, 'photo', true);
    $cv        = get_user_meta($user->ID, 'cv', true);

    $levels = [
        "A: Personal Trainer",
        "B: Gym Instructor",
        "C: Pre-Choreographed",
        "D: Group Fitness Instructor (Freestyle)",
        "E: Yoga Instructor",
        "F: Pilates Instructor",
        "G: Pilates Instructor (Comprehensive)"
    ];
    $user_levels = get_user_meta($user->ID, 'level', true);

    $expiry = get_user_meta($user->ID, 'expiry_date', true);
?>

    <h3>Trainer Details</h3>

    <?php if (!empty($flash['notice']['msg'])): ?>
        <?php
        $type = ($flash['notice']['type'] === 'error') ? 'notice-error' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo esc_html($flash['notice']['msg']); ?></p>
        </div>
    <?php endif; ?>

    <table class="form-table">

        <?php foreach ($fields as $key => $label): ?>
            <?php
            $value = isset($vals_override[$key])
                ? $vals_override[$key]
                : get_user_meta($user->ID, $key, true);

            $hasErr = !empty($errors[$key]);
            ?>
            <tr>
                <th>
                    <label><?php echo esc_html($label); ?></label>
                </th>
                <td>
                    <input
                        type="<?php echo ($key === 'dob') ? 'date' : 'text'; ?>"
                        <?php if ($key === 'dob'): ?>
                        max="<?php echo esc_attr(wp_date('Y-m-d')); ?>"
                        <?php endif; ?>
                        name="<?php echo esc_attr($key); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        class="regular-text <?php echo $hasErr ? 'is-invalid' : ''; ?>">

                    <?php gtr_field_error($errors, $key); ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <!-- Qualifications -->
        <tr>
            <th><label>Qualifications</label></th>
            <td>

                <?php gtr_field_error($errors, 'qualifications'); ?>
                <?php gtr_field_error($errors, 'general'); ?>

                <?php if (empty($qualifications)): ?>
                    <em>No qualifications saved.</em>
                <?php else: ?>

                    <?php foreach ($qualifications as $idx => $q): ?>
                        <?php
                        $qualId = !empty($q['qual_id']) ? (string)$q['qual_id'] : (string)gym_generate_qualification_uid();
                        $course = !empty($q['courseName']) ? (string)$q['courseName'] : '';
                        $inst   = !empty($q['institutionName']) ? (string)$q['institutionName'] : '';
                        $date   = !empty($q['completionDate']) ? (string)$q['completionDate'] : '';

                        $cert_ids = (!empty($q['certificate_ids']) && is_array($q['certificate_ids']))
                            ? array_values(array_filter(array_map('intval', $q['certificate_ids'])))
                            : [];

                        // Error keys for this row
                        $courseKey = "courseName_$idx";
                        $instKey   = "institutionName_$idx";
                        $dateKey   = "completionDate_$idx";
                        $filesKey  = "files_$idx";
                        ?>

                        <div class="gtr-qual-block"
                            data-qual="<?php echo esc_attr($qualId); ?>"
                            style="border:1px solid #ddd; padding:10px; border-radius:6px; margin:0 0 12px 0; background:#fff;">

                            <!-- Hidden qual id -->
                            <input type="hidden" name="qualification_id[]" value="<?php echo esc_attr($qualId); ?>">

                            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-start;">

                                <div style="min-width:220px;">
                                    <input
                                        type="text"
                                        name="courseName[]"
                                        placeholder="Course Name"
                                        value="<?php echo esc_attr($course); ?>"
                                        class="regular-text <?php echo !empty($errors[$courseKey]) ? 'is-invalid' : ''; ?>"
                                        style="max-width:220px;">
                                    <?php gtr_field_error($errors, $courseKey); ?>
                                </div>

                                <div style="min-width:220px;">
                                    <input
                                        type="text"
                                        name="institutionName[]"
                                        placeholder="Institution"
                                        value="<?php echo esc_attr($inst); ?>"
                                        class="regular-text <?php echo !empty($errors[$instKey]) ? 'is-invalid' : ''; ?>"
                                        style="max-width:220px;">
                                    <?php gtr_field_error($errors, $instKey); ?>
                                </div>

                                <div>
                                    <input
                                        type="date"
                                        name="completionDate[]"
                                        max="<?php echo esc_attr(wp_date('Y-m-d')); ?>"
                                        value="<?php echo esc_attr($date); ?>"
                                        class="<?php echo !empty($errors[$dateKey]) ? 'is-invalid' : ''; ?>">
                                    <?php gtr_field_error($errors, $dateKey); ?>
                                </div>

                                <!-- Remove Qualification -->
                                <?php

                                if (count($qualifications) > 1) {
                                ?>
                                    <div>
                                        <button type="button" class="button gtr-remove-qual-btn">
                                            Remove Qualification
                                        </button>
                                    </div>

                                <?php
                                }
                                ?>

                            </div>

                            <!-- Existing cert ids (JSON) - keep for backend logic for now -->
                            <input
                                type="hidden"
                                name="existing_cert_ids[]"
                                value="<?php echo esc_attr(wp_json_encode($cert_ids)); ?>">

                            <!-- Upload new certificates for this qualification -->
                            <div style="margin-top:10px;">
                                <strong>Upload Certificates (max 5 total)</strong><br>
                                <input
                                    type="file"
                                    name="qualification_new_certificates[<?php echo esc_attr($qualId); ?>][]"
                                    multiple
                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">

                                <?php gtr_field_error($errors, $filesKey); ?>

                                <p class="description" style="margin:4px 0 0 0;">
                                    New uploads will be added to this qualification.
                                </p>
                            </div>

                            <!-- Certificates list with remove -->
                            <?php if (!empty($cert_ids)): ?>
                                <div style="margin-top:10px; font-size:12px;">
                                    <strong>Certificates:</strong><br>
                                    <?php foreach ($cert_ids as $cid): ?>
                                        <?php
                                        $url  = wp_get_attachment_url($cid);
                                        $name = basename(get_attached_file($cid));
                                        $name = $name ? $name : ('Certificate #' . (int)$cid);
                                        $name = function_exists('gtr_truncate_filename') ? gtr_truncate_filename($name) : $name;
                                        ?>
                                        <?php if ($url): ?>
                                            <div class="gtr-cert-item"
                                                data-cert="<?php echo esc_attr($cid); ?>"
                                                style="display:flex; align-items:center; gap:10px; margin:4px 0;">

                                                <a href="<?php echo esc_url($url); ?>"
                                                    target="_blank"
                                                    style="
                                                        display:inline-block;
                                                        width:40ch;
                                                        overflow:hidden;
                                                        text-overflow:ellipsis;
                                                        white-space:nowrap;
                                                        ">
                                                    <?php echo esc_html($name); ?>
                                                </a>

                                                <button type="button" class="button button-small gtr-remove-cert-btn">
                                                    Remove
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>


                                </div>
                            <?php endif; ?>

                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>

            </td>
        </tr>
        <!-- END Qualifications -->

        <!-- First Aid Certificate (view + upload optional) -->
        <tr>
            <th>First Aid Certificate</th>
            <td>
                <?php if ($first_aid): ?>
                    <a href="<?php echo esc_url(wp_get_attachment_url($first_aid)); ?>" target="_blank">View Certificate</a>
                <?php else: ?>
                    —
                <?php endif; ?>

                <div style="margin-top:8px;">
                    <input type="file" name="first_aid_certificate_upload" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    <?php gtr_field_error($errors, 'first_aid_certificate_upload'); ?>
                </div>
            </td>
        </tr>

        <!-- CNIC Front -->
        <tr>
            <th>National ID (Front)</th>
            <td>
                <?php if ($front): ?>
                    <a href="<?php echo esc_url(wp_get_attachment_url($front)); ?>" target="_blank">View Front</a>
                <?php else: ?>
                    —
                <?php endif; ?>

                <div style="margin-top:8px;">
                    <input type="file" name="nidPhotoFront_upload" accept=".jpg,.jpeg,.png,.pdf">
                    <?php gtr_field_error($errors, 'nidPhotoFront_upload'); ?>
                </div>
            </td>
        </tr>

        <!-- CNIC Back -->
        <tr>
            <th>National ID (Back)</th>
            <td>
                <?php if ($back): ?>
                    <a href="<?php echo esc_url(wp_get_attachment_url($back)); ?>" target="_blank">View Back</a>
                <?php else: ?>
                    —
                <?php endif; ?>

                <div style="margin-top:8px;">
                    <input type="file" name="nidPhotoBack_upload" accept=".jpg,.jpeg,.png,.pdf">
                    <?php gtr_field_error($errors, 'nidPhotoBack_upload'); ?>
                </div>
            </td>
        </tr>

        <!-- CV (optional uploader too) -->
        <tr>
            <th>CV</th>
            <td>
                <?php if ($cv): ?>
                    <a href="<?php echo esc_url(wp_get_attachment_url($cv)); ?>" target="_blank">View CV</a>
                <?php else: ?>
                    —
                <?php endif; ?>

                <?php gtr_field_error($errors, 'cv_upload'); ?>
            </td>
        </tr>

        <!-- Levels -->
        <tr>
            <th>Level of Registration</th>
            <td>
                <?php foreach ($levels as $level): ?>
                    <?php $checked = (is_array($user_levels) && in_array($level, $user_levels, true)) ? 'checked' : ''; ?>
                    <label>
                        <input type="checkbox" name="level[]" value="<?php echo esc_attr($level); ?>" <?php echo $checked; ?>>
                        <?php echo esc_html($level); ?>
                    </label>
                    <br>
                <?php endforeach; ?>

                <?php gtr_field_error($errors, 'level'); ?>
            </td>
        </tr>

        <!-- Expiry -->
        <tr>
            <th><label>Expiry Date</label></th>
            <td>
                <input type="date" name="expiry_date" value="<?php echo esc_attr($expiry); ?>" class="regular-text <?php echo !empty($errors['expiry_date']) ? 'is-invalid' : ''; ?>">
                <?php gtr_field_error($errors, 'expiry_date'); ?>
            </td>
        </tr>

    </table>

    <!-- Hidden removal bucket for qualifications -->
    <div id="gtr-remove-qual-holder"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            function checkQualificationToShowRemove() {
                const blocks = document.querySelectorAll('.gtr-qual-block');
                let visibleCount = 0;

                blocks.forEach(function(block) {
                    if (block.style.display !== 'none') {
                        visibleCount++;
                    }
                });

                document.querySelectorAll('.gtr-remove-qual-btn').forEach(function(btn) {
                    btn.style.display = visibleCount > 1 ? 'inline-block' : 'none';
                });
            }

            // Initial run (page load)
            checkQualificationToShowRemove();

            // Remove qualification: hide block + add hidden input remove_qualification_ids[]
            document.querySelectorAll('.gtr-remove-qual-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {

                    if (!confirm('Are you sure you want to remove this qualification? This action will take effect after saving.')) {
                        return;
                    }

                    const block = this.closest('.gtr-qual-block');
                    if (!block) return;

                    const qualId = block.getAttribute('data-qual');
                    if (!qualId) return;

                    // // Add hidden input
                    const holder = document.getElementById('gtr-remove-qual-holder');
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'remove_qualification_ids[]';
                    input.value = qualId;
                    holder.appendChild(input);

                    // Hide block
                    block.style.display = 'none';

                    checkQualificationToShowRemove();
                });
            });

            // Remove certificate: add hidden input remove_cert_ids[QUAL_ID][]
            document.querySelectorAll('.gtr-remove-cert-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {

                    if (!confirm('Are you sure you want to remove this certificate? This action will take effect after saving.')) {
                        return;
                    }

                    const certRow = this.closest('.gtr-cert-item');
                    const qualBlock = this.closest('.gtr-qual-block');
                    if (!certRow || !qualBlock) return;

                    const certId = certRow.getAttribute('data-cert');
                    const qualId = qualBlock.getAttribute('data-qual');
                    console.log("qualId", qualId);
                    console.log("certId", certId);
                    if (!certId || !qualId) return;

                    /* ---------------------------------------
                       1) Add hidden remove_cert_ids[qualId][]
                    ---------------------------------------- */
                    const removeInput = document.createElement('input');
                    removeInput.type = 'hidden';
                    removeInput.name = 'remove_cert_ids[' + qualId + '][]';
                    removeInput.value = certId;
                    qualBlock.appendChild(removeInput);

                    /* ---------------------------------------
                       2) Update existing_cert_ids[] JSON
                    ---------------------------------------- */
                    const existingInput = qualBlock.querySelector('input[name="existing_cert_ids[]"]');
                    if (existingInput && existingInput.value) {
                        try {
                            let certs = JSON.parse(existingInput.value);
                            if (Array.isArray(certs)) {
                                certs = certs.filter(function(id) {
                                    return String(id) !== String(certId);
                                });
                                existingInput.value = JSON.stringify(certs);
                            }
                        } catch (e) {
                            // silent fail (do nothing)
                        }
                    }

                    /* ---------------------------------------
                       3) Hide row in UI
                    ---------------------------------------- */
                    certRow.style.display = 'none';
                });
            });

        });
    </script>

<?php
}


/* ============================================================
   SAVE PROFILE
============================================================ */
add_action('personal_options_update', 'save_trainer_fields');
add_action('edit_user_profile_update', 'save_trainer_fields');

add_action('user_edit_form_tag', function () {
    echo ' enctype="multipart/form-data"';
});

add_action('admin_notices', function () {
    global $pagenow;

    // Sirf profile & user edit pages
    if (!in_array($pagenow, ['profile.php', 'user-edit.php'], true)) {
        return;
    }

    // 🔑 Edited user ID nikaalo
    if ($pagenow === 'user-edit.php' && !empty($_GET['user_id'])) {
        $user_id = (int) $_GET['user_id'];
    } else {
        // profile.php case
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return;
    }

    $edited_user = get_userdata($user_id);

    if (!$edited_user) {
        return;
    }

    // ✅ Agar edited user ADMIN hai → CSS NA lagao
    if (in_array('administrator', (array) $edited_user->roles, true)) {
        return;
    }

    // ✅ Agar edited user TRAINER hai → CSS lagao
    if (in_array('trainer', (array) $edited_user->roles, true)) {
        echo '<style>
            .notice.updated.is-dismissible {
                display: none !important;
            }
        </style>';
    }
}, 0);




function save_trainer_fields($user_id)
{
    if (!current_user_can('edit_user', $user_id)) return;

    // -----------------------------
    // Allowed mimes
    // -----------------------------
    $allowed_docs_images = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    $allowed_cnic = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    // -----------------------------
    // Collect + sanitize values
    // -----------------------------
    $fields = ['city', 'mobile', 'dob', 'nid', 'gender', 'nationality', 'jobTitle', 'gymName', 'branchName'];
    $vals = [];
    foreach ($fields as $f) {
        $vals[$f] = isset($_POST[$f]) ? sanitize_text_field($_POST[$f]) : '';
    }

    // -----------------------------
    // Incoming remove instructions
    // -----------------------------
    $removeQualIds = (isset($_POST['remove_qualification_ids']) && is_array($_POST['remove_qualification_ids']))
        ? array_values(array_unique(array_map('sanitize_text_field', $_POST['remove_qualification_ids'])))
        : [];

    // remove_cert_ids[qualId][] = [certId, certId]
    $removeCertIds = (isset($_POST['remove_cert_ids']) && is_array($_POST['remove_cert_ids']))
        ? $_POST['remove_cert_ids']
        : [];

    $removed_attachment_ids = [];
    // Normalize removeCertIds -> qualId => [int,int]
    $removeCertIdsNorm = [];
    foreach ($removeCertIds as $qid => $arr) {
        if (!is_array($arr)) continue;
        $qid = sanitize_text_field($qid);
        // cert IDs are inside $arr
        $ids = array_values(array_unique(array_map('intval', $arr)));

        // add to flat list
        foreach ($ids as $cid) {
            if ($cid > 0) $removed_attachment_ids[] = $cid;
        }
        $removeCertIdsNorm[$qid] = array_values(array_unique(array_map('intval', $arr)));
    }

    // 2) If a qualification is removed, also remove ALL its certificates (from current saved meta)
    if (!empty($removeQualIds)) {

        $saved_quals = get_user_meta($user_id, 'qualifications', true);
        $saved_quals = is_array($saved_quals) ? $saved_quals : [];

        foreach ($saved_quals as $sq) {
            $qid = !empty($sq['qual_id']) ? (string)$sq['qual_id'] : '';
            if (!$qid) continue;

            if (in_array($qid, $removeQualIds, true)) {
                $cids = (!empty($sq['certificate_ids']) && is_array($sq['certificate_ids']))
                    ? array_values(array_unique(array_map('intval', $sq['certificate_ids'])))
                    : [];

                foreach ($cids as $cid) {
                    $cid = (int)$cid;
                    if ($cid > 0) $removed_attachment_ids[] = $cid;
                }
            }
        }
    }

    // -----------------------------
    // Build qualifications from POST
    // -----------------------------
    $qualIds           = (isset($_POST['qualification_id']) && is_array($_POST['qualification_id'])) ? $_POST['qualification_id'] : [];
    $courseNames       = (isset($_POST['courseName']) && is_array($_POST['courseName'])) ? $_POST['courseName'] : [];
    $institutionNames  = (isset($_POST['institutionName']) && is_array($_POST['institutionName'])) ? $_POST['institutionName'] : [];
    $completionDates   = (isset($_POST['completionDate']) && is_array($_POST['completionDate'])) ? $_POST['completionDate'] : [];
    $certificateIds    = (isset($_POST['existing_cert_ids']) && is_array($_POST['existing_cert_ids'])) ? $_POST['existing_cert_ids'] : [];

    $qualifications = [];
    $errors = [];

    foreach ($courseNames as $idx => $course) {
        $qualId = isset($qualIds[$idx]) ? gtr_clean_qual_id($qualIds[$idx]) : gym_generate_qualification_uid();
        $qualId = sanitize_text_field($qualId);

        // If this qualification was "removed" via UI -> skip entirely
        if (in_array($qualId, $removeQualIds, true)) {
            continue;
        }

        $course = trim(sanitize_text_field($course));
        $inst   = isset($institutionNames[$idx]) ? trim(sanitize_text_field($institutionNames[$idx])) : '';
        $date   = isset($completionDates[$idx]) ? trim(sanitize_text_field($completionDates[$idx])) : '';

        $certIds = isset($certificateIds[$idx]) ? json_decode(stripslashes($certificateIds[$idx]), true) : [];
        $certIds = is_array($certIds) ? array_values(array_unique(array_map('intval', $certIds))) : [];

        // Apply certificate removals for this qual
        if (!empty($removeCertIdsNorm[$qualId])) {
            $toRemove = $removeCertIdsNorm[$qualId];
            $certIds = array_values(array_diff($certIds, $toRemove));
        }

        // If row is totally blank, ignore
        if ($course === '' && $inst === '' && $date === '') {
            continue;
        }

        // ---- Validations (you can add more) ----
        if ($course === '') $errors["courseName_$idx"] = 'Course name is required';
        if ($inst === '')   $errors["institutionName_$idx"] = 'Institution name is required';

        // completionDate: valid + not future
        if ($date === '') {
            $errors["completionDate_$idx"] = 'Completion date is required';
        } else {
            $dt = DateTime::createFromFormat('!Y-m-d', $date); // ! = midnight (date-only)
            if (!$dt || $dt->format('Y-m-d') !== $date) {
                $errors["completionDate_$idx"] = 'Invalid completion date';
            } else {
                $tz = wp_timezone(); // WP site timezone
                $today = new DateTimeImmutable('today', $tz);

                // Compare as strings to avoid time component issues
                if ($dt->format('Y-m-d') > $today->format('Y-m-d')) {
                    $errors["completionDate_$idx"] = 'Completion date cannot be in the future';
                }
            }
        }

        $newUploads = gtrUploadedCountForQual($qualId);

        // If nothing will remain, error
        if ((count($certIds) + $newUploads) <= 0) {
            // show under same qualification block
            $errors["files_$idx"] = 'At least one certificate is required for this qualification.';
        }

        // Save row (uploads handled after validation)
        $qualifications[] = [
            'qual_id'         => $qualId,
            'courseName'      => $course,
            'institutionName' => $inst,
            'completionDate'  => $date,
            'certificate_ids' => $certIds,
        ];

        if (count($qualifications) >= 5) break;
    }

    // -----------------------------
    // levels + expiry (keep for re-show)
    // -----------------------------
    $vals['level'] = isset($_POST['level']) && is_array($_POST['level'])
        ? array_map('sanitize_text_field', $_POST['level'])
        : [];
        
    $vals['expiry_date'] = isset($_POST['expiry_date'])
        ? sanitize_text_field($_POST['expiry_date'])
        : '';

    // ❌ Required check
    if (empty($vals['expiry_date'])) {
        $errors['expiry_date'] = 'Expiry date is required.';
    } else {

        // ✅ Format validation (YYYY-MM-DD)
        $date = DateTime::createFromFormat('Y-m-d', $vals['expiry_date']);
        $errorsInDate = DateTime::getLastErrors();

        if (
            !$date ||
            $errorsInDate['warning_count'] > 0 ||
            $errorsInDate['error_count'] > 0
        ) {
            $errors['expiry_date'] = 'Invalid expiry date format.';
        }
    }

    // -----------------------------
    // Validate single uploads (CNIC/first aid)
    // -----------------------------
    // First Aid (optional)
    $e = validate_uploaded_file('first_aid_certificate_upload', false, $allowed_docs_images, 'First Aid Certificate');
    if ($e) $errors['first_aid_certificate_upload'] = $e;

    // CNIC front/back (optional)
    $e = validate_uploaded_file('nidPhotoFront_upload', false, $allowed_cnic, 'National ID (Front)');
    if ($e) $errors['nidPhotoFront_upload'] = $e;

    $e = validate_uploaded_file('nidPhotoBack_upload', false, $allowed_cnic, 'National ID (Back)');
    if ($e) $errors['nidPhotoBack_upload'] = $e;

    // -----------------------------
    // Validate nested qualification cert uploads
    // qualification_new_certificates[qualId][]
    // -----------------------------
    if (!empty($_FILES['qualification_new_certificates']) && !empty($_FILES['qualification_new_certificates']['name'])) {

        // quick access
        $names = $_FILES['qualification_new_certificates']['name'];
        $types = $_FILES['qualification_new_certificates']['type'];
        $tmp   = $_FILES['qualification_new_certificates']['tmp_name'];
        $errs  = $_FILES['qualification_new_certificates']['error'];
        $sizes = $_FILES['qualification_new_certificates']['size'];

        // index qualifications by qual_id for count checks
        $qualIndex = [];
        foreach ($qualifications as $qi => $q) {
            $qualIndex[(string)$q['qual_id']] = $qi;
        }

        foreach ($names as $qid => $fileList) {
            $qid = sanitize_text_field($qid);
            if (!isset($qualIndex[$qid]) || !is_array($fileList)) continue;

            // Find the original row idx for error key (best-effort)
            // We'll use files_{rowIndexInQualifications} to display under the same block.
            $rowIdx = $qualIndex[$qid];
            $filesKey = "files_$rowIdx";

            // existing count (after removals already applied)
            $existingCount = count($qualifications[$rowIdx]['certificate_ids']);

            // cap: existing + new <= 5
            $remaining = max(0, 5 - $existingCount);
            if ($remaining <= 0) {
                // If user tried uploading but already full
                // only show error if any file name present
                if (!empty(array_filter($fileList))) {
                    $errors[$filesKey] = 'Maximum 5 certificates allowed per qualification (including existing).';
                }
                continue;
            }

            // Validate each file (size/mime)
            $attempted = 0;
            foreach ($fileList as $fIndex => $filename) {
                if (!$filename) continue;
                $attempted++;

                // We'll validate max count too
                if ($attempted > 5) {
                    $errors[$filesKey] = 'Maximum 5 certificates can be uploaded at a time.';
                    break;
                }

                $size  = (int)($sizes[$qid][$fIndex] ?? 0);
                $errNo = (int)($errs[$qid][$fIndex] ?? 0);
                $tmpf  = (string)($tmp[$qid][$fIndex] ?? '');
                $type  = (string)($types[$qid][$fIndex] ?? '');

                if ($errNo !== UPLOAD_ERR_OK) {
                    $errors[$filesKey] = 'File upload error.';
                    break;
                }

                if ($size > GTR_MAX_FILE_SIZE) {
                    $errors[$filesKey] = 'Each file must not exceed 10MB.';
                    break;
                }

                // Strong mime check
                $mimeType = '';
                if ($tmpf && file_exists($tmpf)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mimeType = finfo_file($finfo, $tmpf);
                    finfo_close($finfo);
                }

                if ($mimeType && !in_array($mimeType, $allowed_docs_images, true)) {
                    $errors[$filesKey] = 'Invalid file type. Allowed: ' . mime_types_to_labels($allowed_docs_images) . '.';
                    break;
                }
            }
        }
    }

    // -----------------------------
    // If errors: flash everything and STOP
    // -----------------------------
    if (!empty($errors)) {
        gtr_admin_flash_set($user_id, [
            'values' => $vals,
            'qualifications' => $qualifications,
            'errors' => $errors,
            'notice' => ['type' => 'error', 'msg' => 'Please fix the highlighted errors and save again.'],
        ]);
        return;
    }

    // -----------------------------
    // No errors -> Update DB basic fields
    // -----------------------------
    foreach ($fields as $f) {
        update_user_meta($user_id, $f, $vals[$f]);
    }

    // -----------------------------
    // Handle single uploads (CNIC / First Aid)
    // -----------------------------

    // First Aid Certificate
    if (!empty($_FILES['first_aid_certificate_upload']['name'])) {

        // delete previous
        $old = (int) get_user_meta($user_id, 'first_aid_certificate', true);
        if ($old) {
            wp_delete_attachment($old, true); // true = force delete
        }

        // upload new
        $id = gym_handle_file_upload($_FILES['first_aid_certificate_upload']);
        if ($id) {
            update_user_meta($user_id, 'first_aid_certificate', (int) $id);
        }
    }

    // CNIC Front
    if (!empty($_FILES['nidPhotoFront_upload']['name'])) {

        $old = (int) get_user_meta($user_id, 'nidPhotoFront', true);
        if ($old) {
            wp_delete_attachment($old, true);
        }

        $id = gym_handle_file_upload($_FILES['nidPhotoFront_upload']);
        if ($id) {
            update_user_meta($user_id, 'nidPhotoFront', (int) $id);
        }
    }

    // CNIC Back
    if (!empty($_FILES['nidPhotoBack_upload']['name'])) {

        $old = (int) get_user_meta($user_id, 'nidPhotoBack', true);
        if ($old) {
            wp_delete_attachment($old, true);
        }

        $id = gym_handle_file_upload($_FILES['nidPhotoBack_upload']);
        if ($id) {
            update_user_meta($user_id, 'nidPhotoBack', (int) $id);
        }
    }

    // -----------------------------
    // Handle nested uploads (new certs per qualification)
    // -----------------------------
    if (!empty($_FILES['qualification_new_certificates']) && !empty($_FILES['qualification_new_certificates']['name'])) {

        $names = $_FILES['qualification_new_certificates']['name'];
        $types = $_FILES['qualification_new_certificates']['type'];
        $tmp   = $_FILES['qualification_new_certificates']['tmp_name'];
        $errs  = $_FILES['qualification_new_certificates']['error'];
        $sizes = $_FILES['qualification_new_certificates']['size'];

        foreach ($qualifications as $qi => $q) {
            $qid = (string)$q['qual_id'];

            if (empty($names[$qid]) || !is_array($names[$qid])) {
                continue;
            }

            $existingCount = count($qualifications[$qi]['certificate_ids']);
            $remaining = max(0, 5 - $existingCount);
            if ($remaining <= 0) continue;

            foreach ($names[$qid] as $fIndex => $filename) {
                if (!$filename) continue;
                if ($remaining <= 0) break;

                $single = [
                    'name'     => $names[$qid][$fIndex],
                    'type'     => $types[$qid][$fIndex] ?? '',
                    'tmp_name' => $tmp[$qid][$fIndex] ?? '',
                    'error'    => $errs[$qid][$fIndex] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $sizes[$qid][$fIndex] ?? 0,
                ];

                if ((int)$single['error'] !== UPLOAD_ERR_OK) continue;

                $newId = gym_handle_file_upload($single);
                if ($newId && !in_array((int)$newId, $qualifications[$qi]['certificate_ids'], true)) {
                    $qualifications[$qi]['certificate_ids'][] = (int)$newId;
                    $remaining--;
                }
            }
        }
    }


    // -----------------------------
    // Save qualifications (after removals + uploads)
    // -----------------------------
    $removed_attachment_ids = array_values(array_unique(array_map('intval', $removed_attachment_ids)));
    // Delete removed attachments at the very end (just before saving meta)
    foreach ($removed_attachment_ids as $cid) {
        $cid = (int)$cid;
        if ($cid > 0 && get_post_type($cid) === 'attachment') {
            wp_delete_attachment($cid, true);
        }
    }

    update_user_meta($user_id, 'qualifications', $qualifications);

    // Levels + expiry
    if (!empty($vals['level'])) update_user_meta($user_id, 'level', $vals['level']);
    else delete_user_meta($user_id, 'level');
    $progress = get_user_meta($user_id, 'registration_progress', true);
    if ($progress === 'payment_pending') {

        update_user_meta($user_id, 'registration_progress', 'complete');
    }

    update_user_meta($user_id, 'registration_progress', 'complete');
    update_user_meta($user_id, 'expiry_date', $vals['expiry_date']);

    // Flash success
    gtr_admin_flash_set($user_id, [
        'values' => $vals,
        'qualifications' => $qualifications,
        'errors' => [],
        'notice' => ['type' => 'success', 'msg' => 'Trainer details updated successfully.'],
    ]);
}


/* ============================================================
   CUSTOM AVATAR FOR TRAINER
============================================================ */
add_filter('get_avatar', function ($avatar, $id_or_email, $size, $default, $alt) {
    $user = false;
    if (is_numeric($id_or_email))
        $user = get_user_by('id', $id_or_email);
    elseif (is_object($id_or_email) && !empty($id_or_email->user_id))
        $user = get_user_by('id', $id_or_email->user_id);

    if ($user && in_array('trainer', (array) $user->roles, true)) {
        $photo = get_user_meta($user->ID, 'photo', true);
        if ($photo) {
            $avatar = '<img alt="' . esc_attr($alt) . '" src="' . esc_url(wp_get_attachment_url($photo)) .
                '" class="avatar avatar-' . (int) $size . ' photo" width="' . (int) $size . '" height="' . (int) $size . '">';
        }
    }
    return $avatar;
}, 10, 5);

/* ============================================================
   ADMIN USERS TABLE COLUMNS
============================================================ */
add_filter('manage_users_columns', function ($columns) {
    $columns['approval_status'] = 'Status';
    $columns['registration_progress'] = 'Registration Progress';
    $columns['expiry_date'] = 'Expiry Date';
    $columns['incomplete_reminder'] = 'Reminder';
    return $columns;
});

add_action('manage_users_custom_column', function ($value, $column_name, $user_id) {
    if ($column_name === 'approval_status') {
        $status = get_user_meta($user_id, 'approval_status', true);
        if (!$status)
            $status = 'Inactive';
        return ucfirst($status);
    }


    if ($column_name === 'registration_progress') {
        $progress = get_user_meta($user_id, 'registration_progress', true);
        if (!$progress)
            return '—';

        switch ($progress) {
            case 'step1':
                return 'Step 1 (Personal Details)';
            case 'step2':
                return 'Step 2 (Workplace)';
            case 'complete':
                return 'Completed';
            case 'payment_pending':
                return 'Payment Pending';
            default:
                return ucfirst($progress);
        }
    }

    if ($column_name === 'expiry_date') {
        $exp = get_user_meta($user_id, 'expiry_date', true);
        if (!$exp)
            return '—';

        $exp_time = strtotime($exp);
        $today = strtotime(date('Y-m-d'));
        $diff = floor(($exp_time - $today) / 86400);

        if ($diff > 0) {
            return date('d M, Y', strtotime($exp)) . " <strong>($diff days left)</strong>";
        } elseif ($diff == 0) {
            return date('d M, Y', strtotime($exp)) . " <strong>(Expires Today)</strong>";
        } else {
            return date('d M, Y', strtotime($exp)) . " <strong style='color:red'>(Expired " . abs($diff) . " days ago)</strong>";
        }
    }

    if ($column_name === 'incomplete_reminder') {
        $user = get_user_by('id', $user_id);
        if (!$user)
            return $value;

        if (!in_array('trainer', (array) $user->roles, true))
            return '—';

        $progress = get_user_meta($user_id, 'registration_progress', true);
        if ($progress === 'complete')
            return '<span style="color: #46b450;">Completed</span>';

        $last_sent = get_user_meta($user_id, 'incomplete_reminder_sent', true);
        $last_sent_text = $last_sent ? 'Last sent: ' . esc_html(date_i18n('d M Y H:i', strtotime($last_sent))) : 'No reminder sent yet';

        $url = wp_nonce_url(
            add_query_arg(['trainer_reminder' => 'send', 'user_id' => $user_id], admin_url('users.php')),
            'send_trainer_reminder_' . $user_id
        );

        $button = '<a href="' . esc_url($url) . '" class="button button-small">Send Reminder</a>';
        return $button . '<br><small>' . $last_sent_text . '</small>';
    }

    return $value;
}, 10, 3);

/* ============================================================
   Admin Edit User Page Active/Inactive dropdown
============================================================ */
add_action('show_user_profile', 'td_add_status_field');
add_action('edit_user_profile', 'td_add_status_field');
function td_add_status_field($user)
{
    $status = get_user_meta($user->ID, 'approval_status', true) ?: 'Inactive';
?>
    <h3>Trainer Status</h3>
    <table class="form-table">
        <tr>
            <th><label for="approval_status">Status</label></th>
            <td>
                <select name="approval_status" id="approval_status">
                    <option value="Active" <?php selected($status, 'Active'); ?>>Active</option>
                    <option value="Provisional" <?php selected($status, 'Provisional'); ?>>Provisional</option>
                    <option value="Inactive" <?php selected($status, 'Inactive'); ?>>Inactive</option>
                </select>
            </td>
        </tr>
    </table>
<?php
}

add_action('personal_options_update', 'td_save_status_field');
add_action('edit_user_profile_update', 'td_save_status_field');
function td_save_status_field($user_id)
{
    if (!current_user_can('edit_user', $user_id))
        return false;
    if (isset($_POST['approval_status'])) {
        update_user_meta($user_id, 'approval_status', sanitize_text_field($_POST['approval_status']));
    }
}

/* ============================================================
   CRON JOB — AUTO INACTIVATE EXPIRED USERS (DAILY)
============================================================ */
if (!wp_next_scheduled('check_trainer_expiry_daily')) {
    wp_schedule_event(time(), 'daily', 'check_trainer_expiry_daily');
}
add_action('check_trainer_expiry_daily', 'auto_inactivate_expired_trainers');

function auto_inactivate_expired_trainers()
{
    $users = get_users([
        'role' => 'trainer',
        'meta_key' => 'expiry_date',
        'meta_compare' => 'EXISTS'
    ]);

    $today = date('Y-m-d');

    foreach ($users as $user) {
        $expiry = get_user_meta($user->ID, 'expiry_date', true);
        if (!$expiry)
            continue;

        if ($expiry < $today) {
            update_user_meta($user->ID, 'approval_status', 'Inactive');
            update_user_meta($user->ID, 'is_directory_visible', 'no');

            $subject = "Your Trainer Account Has Expired";
            $msg = "Hello " . $user->first_name . ",\n\nYour trainer membership has expired.\n\nPlease renew to reactivate your account.\n\nRegards,\nAssociation of Fitness Professionals\n";
            wp_mail($user->user_email, $subject, $msg);
        }
    }
}

/* ============================================================
   Manual reminder action from Users list
============================================================ */
add_action('admin_init', 'gym_trainer_handle_manual_incomplete_reminder');
function gym_trainer_handle_manual_incomplete_reminder()
{
    if (!is_admin())
        return;
    if (!isset($_GET['trainer_reminder'], $_GET['user_id']))
        return;
    if ($_GET['trainer_reminder'] !== 'send')
        return;

    $user_id = (int) $_GET['user_id'];
    if (!current_user_can('edit_user', $user_id))
        return;

    check_admin_referer('send_trainer_reminder_' . $user_id);

    $sent = gym_trainer_send_incomplete_reminder($user_id);
    $status = $sent ? 'success' : 'error';

    wp_safe_redirect(add_query_arg([
        'trainer_reminder_sent' => $status,
        'user_id' => $user_id,
    ], admin_url('users.php')));
    exit;
}

add_action('admin_notices', function () {
    if (!is_admin())
        return;
    if (!isset($_GET['trainer_reminder_sent']))
        return;

    $status = sanitize_text_field($_GET['trainer_reminder_sent']);
    $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

    if ($status === 'success') {
        echo '<div class="notice notice-success is-dismissible"><p>Reminder email sent to trainer (User ID: ' . esc_html($user_id) . ').</p></div>';
    } elseif ($status === 'error') {
        echo '<div class="notice notice-error is-dismissible"><p>Unable to send reminder email. Please check mail settings.</p></div>';
    }
});

/* ============================================================
   PRE-EXPIRY REMINDER EMAIL SYSTEM (TESTING every 5 mins)
   (ONLY 7 day + 3 day reminders; expiry handled by daily job)
============================================================ */
if (!wp_next_scheduled('trainer_expiry_reminder_daily')) {
    wp_schedule_event(time(), 'every_5_minutes', 'trainer_expiry_reminder_daily');
}
add_action('trainer_expiry_reminder_daily', 'send_trainer_expiry_reminders');

function send_trainer_expiry_reminders()
{
    $users = get_users([
        'role' => 'trainer',
        'meta_key' => 'expiry_date',
        'meta_compare' => 'EXISTS'
    ]);

    $today = date('Y-m-d');

    foreach ($users as $user) {
        $expiry = get_user_meta($user->ID, 'expiry_date', true);
        if (!$expiry)
            continue;

        $diff = floor((strtotime($expiry) - strtotime($today)) / 86400);

        if ($diff == 7) {
            wp_mail(
                $user->user_email,
                "Your Trainer Membership Expires in 7 Days",
                "Hello " . $user->first_name . ",\n\nYour trainer membership will expire in 7 days.\nPlease renew to avoid expiration.\n\nRegards,\nAssociation of Fitness Professionals"
            );
        }

        if ($diff == 3) {
            wp_mail(
                $user->user_email,
                "Your Trainer Membership Expires in 3 Days",
                "Hello " . $user->first_name . ",\n\nThis is a reminder that your membership expires in 3 days.\nRenew soon.\n\nRegards,\nAssociation of Fitness Professionals"
            );
        }

        // NOTE: expiry-day inactivation/email removed to avoid duplicate emails.
        // Daily expiry job handles actual expiry.
    }
}

/* ============================================================
   Incomplete registration reminder email with resume link
============================================================ */
function gym_trainer_send_incomplete_reminder($user_id)
{
    $user = get_user_by('id', $user_id);
    if (!$user)
        return false;

    if (!in_array('trainer', (array) $user->roles, true))
        return false;

    $progress = get_user_meta($user_id, 'registration_progress', true);
    if ($progress === 'complete')
        return false;

    $token = wp_generate_password(32, false);
    update_user_meta($user_id, 'registration_resume_key', $token);

    $base_url = site_url('/registration/');
    $registration_url = add_query_arg([
        'trainer_resume' => $user_id,
        'key' => $token,
    ], $base_url);

    $first_name = $user->first_name ?: $user->display_name;

    $subject = 'Complete Your Trainer Registration with Association of Fitness Professionals';
    $message = "Hey {$first_name},\n\n"
        . "We noticed you started your trainer registration with Association of Fitness Professionals but didn’t get a chance to finish it yet.\n\n"
        . "No worries — you can continue anytime using the link below:\n"
        . "{$registration_url}\n\n"
        . "Once completed, our team will review your details and activate your account.\n\n"
        . "If you need help at any stage, feel free to reach out.\n\n"
        . "Regards,\n"
        . "Association of Fitness Professionals Support Team";

    $sent = wp_mail($user->user_email, $subject, $message);
    if ($sent)
        update_user_meta($user_id, 'incomplete_reminder_sent', current_time('mysql'));

    return $sent;
}
