<?php


/**
 * Validate uploaded file
 *
 * @param string $fileKey      The $_FILES key
 * @param bool   $required     Whether the file is required
 * @param array  $allowedTypes Array of allowed MIME types
 * @return string|bool         Returns error message if invalid, or false if valid
 */
function validate_uploaded_file($fileKey, $required = false, $allowedTypes = [], $label = null)
{
    // Check if file is uploaded
    $label = $label ?: ucfirst($fileKey);

    if ($required && empty($_FILES[$fileKey]['name'])) {
        return $label . ' is required.';
    }

    // Skip further checks if file is not uploaded
    if (empty($_FILES[$fileKey]['name'])) {
        return false;
    }

    $file = $_FILES[$fileKey];

    // Check file size
    if ($file['size'] > GTR_MAX_FILE_SIZE) {
        return  $label  . ' must not exceed 10MB.';
    }

    // Check MIME type if allowed types are provided
    if (!empty($allowedTypes)) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes, true)) {
            $friendlyTypes = mime_types_to_labels($allowedTypes);
            return $label . ' must be a valid file type: ' . $friendlyTypes . '.';
        }
    }

    return false; // valid
}


function gym_generate_qualification_uid()
{
    return 'q_' . wp_generate_uuid4();
}

function mime_types_to_labels(array $mimeTypes): string
{
    $map = [
        'application/pdf' => 'PDF',
        'application/msword' => 'DOC',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
        'image/jpeg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
    ];

    $labels = [];

    foreach ($mimeTypes as $mime) {
        if (isset($map[$mime])) {
            $labels[] = $map[$mime];
        }
    }

    return implode(', ', array_unique($labels));
}

function gtr_clean_qual_id($value)
{
    if (!is_string($value)) {
        return $value;
    }

    // trim spaces
    $value = trim($value);

    // 🔥 remove ALL leading \ and "
    $value = preg_replace('/^[\\\\"]+/', '', $value);

    return $value;
}


function gtr_admin_flash_key($user_id)
{
    return 'gtr_admin_flash_' . get_current_user_id() . '_' . (int)$user_id;
}

function gtr_admin_flash_set($user_id, array $data, $ttl = 120)
{
    // Same request
    $GLOBALS['gtr_admin_flash'][$user_id] = $data;

    // Next request (after redirect)
    set_transient(gtr_admin_flash_key($user_id), $data, $ttl);
}

function gtr_admin_flash_get($user_id)
{
    // Prefer transient (covers redirect)
    $data = get_transient(gtr_admin_flash_key($user_id));
    if (is_array($data)) {
        delete_transient(gtr_admin_flash_key($user_id));
        return $data;
    }

    // Same request fallback
    if (!empty($GLOBALS['gtr_admin_flash'][$user_id]) && is_array($GLOBALS['gtr_admin_flash'][$user_id])) {
        return $GLOBALS['gtr_admin_flash'][$user_id];
    }

    return null;
}

function gtr_field_error($errors, $key)
{
    if (!empty($errors[$key])) {
        echo '<p class="description" style="color:#b32d2e; margin:6px 0 0 0;">' . esc_html($errors[$key]) . '</p>';
    }
}

/**
 * Truncate filename to $maxLength, preserving extension
 *
 * @param string $filename Full filename with extension
 * @param int $maxLength Max length of the name part (default 40)
 * @return string Truncated filename with extension
 */
function gtr_truncate_filename($filename, $maxLength = 40)
{
    if (!$filename) {
        return '';
    }

    $basename = basename($filename); // just the file name
    $lastDotPos = strrpos($basename, '.');

    if ($lastDotPos === false) {
        // No extension
        return mb_strlen($basename) > $maxLength ? mb_substr($basename, 0, $maxLength) : $basename;
    }

    $namePart = mb_substr($basename, 0, $lastDotPos);
    $extension = mb_substr($basename, $lastDotPos); // includes dot

    if (mb_strlen($namePart) > $maxLength) {
        $namePart = mb_substr($namePart, 0, $maxLength);
    }

    return $namePart . $extension;
}

// helper: count uploaded files for a given qualId
function gtrUploadedCountForQual(string $qid): int
{
    if (
        empty($_FILES['qualification_new_certificates']) ||
        empty($_FILES['qualification_new_certificates']['name']) ||
        empty($_FILES['qualification_new_certificates']['name'][$qid]) ||
        !is_array($_FILES['qualification_new_certificates']['name'][$qid])
    ) {
        return 0;
    }

    // Count non-empty file names
    return count(array_filter($_FILES['qualification_new_certificates']['name'][$qid]));
};

/**
 * Returns resume user id if valid token found (URL params OR cookie), otherwise 0.
 * URL params take priority: ?trainer_resume=ID&key=TOKEN
 * Cookie fallback: gtr_reg_resume (base64 json {uid,key})
 */
function gtr_resume_get(): int
{
    $resume_user_id = 0;
    $resume_key = '';

    // 1) URL params (admin reminder)
    if (isset($_GET['trainer_resume'], $_GET['key'])) {
        $resume_user_id = (int) $_GET['trainer_resume'];
        $resume_key = sanitize_text_field((string) $_GET['key']);
    }
    // 2) Cookie fallback (user comes back normally)
    elseif (!empty($_COOKIE['gtr_reg_resume'])) {
        $decoded = base64_decode((string) $_COOKIE['gtr_reg_resume'], true);
        $arr = json_decode($decoded, true);

        if (is_array($arr) && !empty($arr['uid']) && !empty($arr['key'])) {
            $resume_user_id = (int) $arr['uid'];
            $resume_key = sanitize_text_field((string) $arr['key']);
        }
    }

    if (!$resume_user_id || !$resume_key) {
        return 0;
    }

    // Optional safety: ensure this is a draft registration user
    // if (get_user_meta($resume_user_id, 'is_registration_draft', true) !== '1') return 0;

    $saved_key = (string) get_user_meta($resume_user_id, 'registration_resume_key', true);

    if (!$saved_key || !hash_equals($saved_key, $resume_key)) {
        return 0;
    }

    // ✅ If valid and came from URL, refresh cookie so next visit works without params
    gtr_resume_set($resume_user_id);

    return $resume_user_id;
}


/**
 * Ensures resume key exists in user meta and sets cookie for 30 days.
 * Call this after progress update (POST), and also after successful URL resume.
 */
function gtr_resume_set(int $user_id): bool
{
    if ($user_id <= 0) {
        return false;
    }

    $key = (string) get_user_meta($user_id, 'registration_resume_key', true);

    // Generate key once (so cookie+email link use same token)
    if (!$key) {
        $key = wp_generate_password(32, false, false);
        update_user_meta($user_id, 'registration_resume_key', $key);
    }

    $payload = base64_encode(wp_json_encode([
        'uid' => (int) $user_id,
        'key' => (string) $key,
    ]));

    $expire = time() + (7 * DAY_IN_SECONDS);

    // HttpOnly true; Secure depends on SSL
    setcookie('gtr_reg_resume', $payload, $expire, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

    // So current request can read it too
    $_COOKIE['gtr_reg_resume'] = $payload;

    return true;
}

function gtr_resume_clear(): void
{
    setcookie('gtr_reg_resume', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    unset($_COOKIE['gtr_reg_resume']);
}
