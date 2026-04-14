# Gym Trainer Registration Plugin — Context

WordPress plugin for the AFP-PK (Association of Fitness Professionals Pakistan) trainer registration system. Handles a 3-step public registration form with PayFast payment, admin approval workflow, and automated notifications.

**Version:** 1.6 — **Plugin slug:** `gym-trainer-registration`

---

## File Structure

```
gym-trainer-registration/
├── gym-trainer-registration.php        # Main file (~3200 lines). Entry + most logic
├── assets/
│   └── css/gtr-style.css               # Step progress bar + form styling
└── includes/
    ├── helpers.php                     # File validation, flash messages, resume cookie helpers
    ├── admin-trainer-filters.php       # NEW — users.php From/To + Pending/Completed filters
    ├── cron-pending-notifications.php  # NEW — admin digest + user reminder crons
    └── payfast/
        ├── class-gtr-payfast.php           # Initiates payment, builds PayFast redirect
        └── class-gtr-payfast-webhook.php   # Verifies payment callback, sets progress=complete
```

**File organization rule** (see `memory/feedback_file_organization.md`):
- **New features** → separate file in `includes/`, `require_once` from main file
- **Modifications to existing code** → edit in place in `gym-trainer-registration.php`

---

## Bootstrapping (main file, lines 12–23)

```php
define('GTR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GTR_MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('GOPAYFAST_MERCHANT_NAME', 'Association of Fitness Professionals');

require_once GTR_PLUGIN_DIR . 'includes/helpers.php';
require_once GTR_PLUGIN_DIR . '/includes/payfast/class-gtr-payfast.php';
require_once GTR_PLUGIN_DIR . '/includes/payfast/class-gtr-payfast-webhook.php';
require_once GTR_PLUGIN_DIR . 'includes/admin-trainer-filters.php';
require_once GTR_PLUGIN_DIR . 'includes/cron-pending-notifications.php';

GTR_PayFast_Webhook::init();
GTR_PayFast::init();
```

No custom DB tables — all data in WP `user_meta` + WP media attachments.

---

## Core Data Model

**Custom role:** `trainer` (capability `read` only)

**user_meta keys:**

| Key | Values / Type | Purpose |
|---|---|---|
| `approval_status` | `Active` / `Provisional` / `Inactive` | Admin-set status |
| `registration_progress` | `step1` / `step2` / `payment_pending` / `payment_failed` / `complete` | Set by webhook on success |
| `_approval_status_old` | string | Tracks previous status to detect change (deleted after use) |
| `is_directory_visible` | `yes` / `no` | Member directory visibility |
| `expiry_date` | YYYY-MM-DD | 1-year membership expiry (auto-set on payment) |
| `registration_resume_key` | 32-char token | For resume link + cookie |
| `incomplete_reminder_sent` | MySQL datetime | Last reminder timestamp (throttle key) |
| `payfast_basket_id` | UUID | PayFast transaction correlator |
| `city`, `mobile`, `dob`, `gender`, `nationality`, `nid` | strings | Step 1 data |
| `jobTitle`, `gymName`, `branchName` | strings | Step 2 data |
| `level` | array | Registration levels A–G |
| `qualifications` | array of `{qual_id, courseName, institutionName, completionDate, certificate_ids[]}` | Up to 5 qualifications, each with up to 5 certs |
| `first_aid_certificate` | attachment ID | First-aid cert |
| ID-photo front/back, profile photo, CV | attachment IDs | File uploads |

**Usernames** are auto-generated as `AFPPK` + 4 random digits (lines 1268, 1377, 1731). **Passwords** are user-chosen at Step 1 (`$_POST['password']`), hashed by WP — plaintext is NOT retrievable later.

---

## Registration Flow

**Shortcode:** `[gym_trainer_register]` — renders 3-step form (main file, `gym_trainer_registration_form()`).

1. **Step 1** — personal details (first name, last name, email, password, city, mobile, DOB, gender, nationality, NID) → creates user with role `trainer`, sets `registration_progress = step1`
2. **Step 2** — employment + photos (job title, gym, branch, ID photos, profile, CV, levels) → `registration_progress = step2`
3. **Step 3** — qualifications + certs + first-aid → validates → sets `registration_progress = payment_pending`, generates `payfast_basket_id`, redirects to PayFast

**Submission handler:** `gym_trainer_handle_form()` on `init` hook (main file, ~line 1033–1817).

**Payment success (webhook):** sets `registration_progress = complete`, `expiry_date = +1 year`, default `approval_status = Inactive` (awaits admin).

**Resume:** `gtr_resume_get()` in `helpers.php` — supports URL (`?trainer_resume=UID&key=TOKEN&step=PROGRESS`) or cookie `gtr_reg_resume` (base64 JSON). Key is validated with `hash_equals` against user meta `registration_resume_key`. Resume URL sets cookie on successful URL entry.

The `step` param in the resume URL is informational — the form reads authoritative progress from user meta (`$start_step` determined at main file ~line 149–156).

---

## Email System

**From filters** (main file, lines 1823–1828):
```php
wp_mail_from_name → 'Association of Fitness Professionals Registration'
wp_mail_from      → 'info@repspakistan.com'
```
⚠️ **Do NOT change the from-address** — it is managed via WP admin settings.

**Common sign-off:** `Best Regards,\nAssociation of Fitness Professionals` (applied consistently across all emails).

**Emails sent:**

| Trigger | Location | Recipient | Subject |
|---|---|---|---|
| Status → Active (admin approval) | `notify_trainer_on_approval()` main file ~1849 | Trainer | "Your Trainer Account Has Been Approved" |
| Status → Provisional | same function (branches on status) | Trainer | "Your Trainer Account Status: Provisional" |
| Registration complete | PayFast webhook | Trainer | "Thank you for your registration!" |
| Expiry (daily cron) | `auto_inactivate_expired_trainers()` ~3038 | Trainer | "Your Trainer Account Has Expired" |
| 7-day expiry reminder | `send_trainer_expiry_reminders()` ~3117 | Trainer | "Your Trainer Membership Expires in 7 Days" |
| 3-day expiry reminder | same | Trainer | "Your Trainer Membership Expires in 3 Days" |
| Incomplete registration reminder | `gym_trainer_send_incomplete_reminder()` ~3157 | Trainer | "Complete Your Trainer Registration…" |
| Pending trainers digest (daily cron) | `gtr_send_admin_pending_digest()` in cron-pending-notifications.php | `info@afp-pk.org` | "Pending Trainer Registrations — YYYY-MM-DD" |

**Approval email content** (Active + Provisional) includes:
- `Status: <value>`
- Login URL: `https://afp-pk.org/trainer-account/`
- Username (`user_login`), email, password reminder ("use the password you created at registration")
- Password reset link via `wp_lostpassword_url()`
- Provisional variant adds: "Your account has been marked as Provisional because some of your details or submitted documents have issues. Please review and update your information."

**Approval trigger condition** (kept as-is by design):
- Fires only when `old_status ∉ {Active, Provisional}` AND `new_status ∈ {Active, Provisional}`
- Old status captured at priority 5 in `td_capture_old_status_before_save()` ~1835, email sent at priority 20 in `notify_trainer_on_approval()` ~1849

---

## Cron Jobs

Custom interval `every_5_minutes` registered via `cron_schedules` filter (main file ~line 68).

| Hook | Schedule | Purpose |
|---|---|---|
| `check_trainer_expiry_daily` | daily | Marks trainers Inactive if `expiry_date` < today + expiry email |
| `trainer_expiry_reminder_daily` | `every_5_minutes` (testing) | 7-day + 3-day pre-expiry reminders |
| `gtr_admin_pending_digest_daily` | daily | Admin email with link to yesterday's pending list (skips if zero) |
| `gtr_pending_user_reminder_daily` | daily | Loops pending trainers, 24h throttled, sends resume link |

No deactivation cleanup hook exists — matches existing pattern. If adding cleanup in future, clear all 4 hooks with `wp_clear_scheduled_hook()`.

---

## Admin UI

Uses **standard `wp-admin/users.php`** — NO dedicated menu page. Customizations:

**Columns** (main file ~2884):
- `approval_status` → "Status" (Active / Provisional / Inactive)
- `registration_progress` → "Registration Progress" (Step 1 / Step 2 / Payment Pending / Completed)
- `expiry_date` → "Expiry Date" with days-left indicator
- `incomplete_reminder` → "Reminder" — "Send Reminder" button (nonced) + last-sent timestamp

**Filters** (in `includes/admin-trainer-filters.php`):
- Query params: `gtr_from` (YYYY-MM-DD), `gtr_to` (YYYY-MM-DD), `gtr_reg_status` (`pending` | `completed`)
- URL example: `users.php?role=trainer&gtr_from=2026-01-01&gtr_to=2026-01-31&gtr_reg_status=pending`
- `gtr_from`/`gtr_to` → `date_query` on `user_registered`
- `gtr_reg_status=completed` → `meta_query` `registration_progress = complete`
- `gtr_reg_status=pending` → `meta_query` `registration_progress != complete` OR `NOT EXISTS`
- UI rendered via `restrict_manage_users`, applied via `pre_get_users` (scoped to `pagenow === 'users.php'` and only fires when at least one param present)

**Trainer profile section** (main file ~1888 `show_trainer_fields()`): only visible for `trainer` role users. Editable personal/workplace fields, qualifications block with per-qualification certificate upload, expiry date display.

**Status dropdown** (main file ~2968 `td_add_status_field()`): Active / Provisional / Inactive select, saved via `td_save_status_field()`.

**Manual reminder action** (main file ~3055): `users.php?trainer_reminder=send&user_id=X` with nonce → `gym_trainer_send_incomplete_reminder()`.

---

## Dependencies

- No plugin dependencies (self-contained)
- PayFast gateway (external, merchant currency PKR)
- PHP 7.4+ (arrow functions, UUID)
- WordPress 5.x+ (date_query, meta_query)

---

## Recent Changes (session history)

1. **Approval email branching** — `notify_trainer_on_approval()` now sends different bodies for Active vs Provisional; both include login URL + credentials hint + reset link.
2. **Admin listing filters** — new `includes/admin-trainer-filters.php` adds From/To + Pending/Completed filters to `users.php` using query params `gtr_from` / `gtr_to` / `gtr_reg_status`.
3. **Daily admin digest cron** — `gtr_admin_pending_digest_daily` in `includes/cron-pending-notifications.php` emails `info@afp-pk.org` a filtered URL to yesterday's pending trainers (skips if zero).
4. **Daily user reminder cron** — `gtr_pending_user_reminder_daily` loops pending trainers and sends resume emails, 24h throttled via `incomplete_reminder_sent` meta.
5. **Resume URL step param** — `gym_trainer_send_incomplete_reminder()` now appends `&step={registration_progress}` to the resume URL; form still reads authoritative step from user meta.
6. **Unified sign-off** — all emails end with `Best Regards,\nAssociation of Fitness Professionals`.

---

## Key Development Rules

- **Don't change existing flows** unless explicitly asked — only modify content/output, not triggers or data model
- **Don't touch the from-address filter** — managed in WP admin
- **Don't store plaintext passwords** — WP hashes them; use reset link or "password you created at registration" messaging
- Keep crons optimized — skip heavy queries when nothing to process
- Respect file organization rule: new features in `includes/*.php`, in-place edits for modifying existing code
