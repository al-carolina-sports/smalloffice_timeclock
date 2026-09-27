# CSS Time Clock Addon

This GitHub repository **is** the WordPress plugin. `css-timeclock-addon.php` is at the repo root (`https://github.com/al-carolina-sports/CSS_timeclock`). Default branch: `main`.

WP Engine upload zip (plugin wrapped in a `css-timeclock-addon/` folder):

- In-repo: [`dist/css-timeclock-addon.zip`](dist/css-timeclock-addon.zip)
- Rebuild: `./bin/make-zip.sh`

Add-on for **All in One Time Clock Lite** (Codebangers, slug `aio-time-clock-lite`). It adds shared-tablet kiosks so employees can clock in and out **without a WordPress login**, plus a logged-in employee times page with supervisor-approved corrections.

This plugin does **not** fork or edit AIO Lite. It writes the same `shift` posts and meta AIO already uses. **SMOTC → Real Time Monitoring** is the addon’s screen: fresh open shifts only, times in the site timezone. In wp-admin the menu and this plugin are labeled **SMOTC**, and AIO Lite is labeled **SMOTC Core**.

| Requirement | Status |
| --- | --- |
| PHP | 7.4+ (WP Engine sandbox is 8.2) |
| WordPress | 5.0+ |
| License | GPLv2 or later |
| AIO Lite | Soft dependency (admin notice if missing) |

## What this plugin includes

1. **PIN kiosk** — `[css_tc_pin_kiosk]` — large PIN pad, then Clock in / Clock out.
2. **Name-list kiosk** — `[css_tc_name_kiosk]` — alphabetical employees, tap a name, **confirm with PIN**, then Clock in / Clock out.
3. **Who’s working board** — on both kiosk pages (logged-out visitors). Side panel on wide screens; stacks under the pad on tablet widths. Lists **Working now** (with clock-in time) and **Not clocked in**. Refreshes every 20 seconds and immediately after a successful punch.
4. **My Time Clock** — `[css_tc_my_times]` — logged-in employees see their own timecard for the current and previous pay periods (older periods are listed read-only). The current period can be corrected; suggestions stay pending until a supervisor reviews them.
5. Admin **SMOTC** screen (`admin.php?page=css-tc-addon`, under the SMOTC menu when AIO is active, otherwise Settings), including pay-period settings and a **Corrections** queue. **SMOTC → Timecards** (`admin.php?page=css-tc-timecards`) shows any employee’s timecard. Approve writes the AIO-compatible shift and keeps an audit (original times, who suggested, who approved). Reject leaves punches unchanged. A correction that would change a closed pay period is rejected.
6. Per-employee PINs stored with `wp_hash_password()` / checked with `wp_check_password()`. Never plaintext.
7. Failed-PIN rate limit by tablet IP.
8. Optional **office IP allowlist** (IPv4, IPv6, and CIDR) for kiosk PIN checks, punches, the name list, and the who’s-working roster. Off, or on with an empty list, allows every network. wp-admin is not restricted.
9. After a punch, a success message, then the kiosk returns to idle. No employee WordPress session is created.
10. On AIO Lite wp-admin screens, addon CSS hides the Get Pro tab, “Available in Pro” rows, and the Reports Advanced tab (that tab is only a Pro button). Company name, wages, the Lite time clock page, employees, monitoring, and the date-range report stay. This does not enable Pro.

Later (not in this build): multi-facility, locations, bulletin / announcements, Pro features.

## How punches reach AIO Lite

AIO Lite’s front-end AJAX (`aio_time_clock_lite_js`) is registered as `wp_ajax_` only and calls `get_current_user_id()`. A logged-out kiosk cannot use that action without impersonating a user.

This add-on therefore writes AIO’s data model directly (same path Real Time Monitoring already reads):

| Field | Value |
| --- | --- |
| Post type | `shift` |
| `post_title` | `Employee Shift` |
| `post_status` | `publish` |
| `post_author` | the employee’s WordPress user ID |
| `employee_clock_in_time` | `Y-m-d H:i:s` UTC instant. Same digits AIO 2.1’s `wp_date()` wrote while this site’s timezone was UTC. Displays use `wp_timezone()`. |
| `employee_clock_out_time` | empty while working; set on clock-out |
| `department` | AIO `department` user taxonomy, when present |
| `ip_address_in` / `ip_address_out` | tablet IP |

An employee is **Working** in AIO monitoring when a shift has a clock-in time and an empty clock-out time.

## Install on WP Engine (carolinaspodev)

1. Zip the plugin folder so the archive contains `css-timeclock-addon/css-timeclock-addon.php` (not a pile of loose files). From this repo:

   ```bash
   ./bin/make-zip.sh
   ```

   That writes `dist/css-timeclock-addon.zip`. The zip in this repository is SMOTC 1.4.10. Install it on carolinaspodev only.

2. WP Engine → the `carolinaspodev` environment → **WordPress Admin** → **Plugins → Add New → Upload Plugin**.
3. Upload the zip, then **Activate**.
4. Confirm **All in One Time Clock Lite** is also installed and active.
5. WP Engine rules this plugin follows: no `exec` / shell, no ionCube, no code written to `uploads`, requests stay well under 60 seconds.

On activation the plugin creates pages if they do not already exist:

- `/pin-time-clock/` → `[css_tc_pin_kiosk]`
- `/name-time-clock/` → `[css_tc_name_kiosk]`
- `/my-time-clock/` → `[css_tc_my_times]`

You can recreate them from **SMOTC → Create or restore kiosk and times pages**.

## Set employee PINs

1. Create WordPress users with AIO roles (`employee`, `volunteer`, `manager`, `contractor`, or the `aio_tc_*` / `time_clock_admin` aliases).
2. Open **SMOTC → Employee PINs** (administrators can also use **Settings → SMOTC**).
3. Enter a 4–8 digit PIN (unique per employee) and **Save PIN**. The field is masked. The eye button on the field shows the digits while you type, and click it again to hide them.
4. The digits are hashed immediately with `wp_hash_password()`. A saved PIN can only be replaced or cleared. The eye never reads a stored PIN back.

Employees without a PIN do not appear on the name-list kiosk. The PIN kiosk only resolves people who have a hashed PIN.

## Use the kiosks

1. Open the PIN or name page on a shared tablet (Safari / Chrome). Bookmark it; the tablet can stay logged out of WordPress.
2. Enable the matching kiosk on the settings tab if a page says it is turned off.
3. PIN kiosk: enter PIN → **Continue** → Clock in or Clock out.
4. Name kiosk: tap a name → enter that person’s PIN → Clock in or Clock out.
5. A USB keyboard or numeric keypad works on the PIN screen (both kiosks). Digit keys and numpad 0–9 append, Backspace or Delete removes the last digit, and Enter submits (same as **Continue**). Length still follows the configured minimum and maximum. Escape on the PIN screen clears the digits and stays on that screen (same as **Clear**). On the name kiosk, the on-screen **Cancel** button is what returns to the name list. On the Clock in / Clock out screen, Escape cancels. On the success screen (which has no Cancel button), Escape returns to the idle screen immediately. Keys are ignored while the cursor is in the name search field, while a request is in progress, while the kiosk is turned off, and off the PIN screen (Escape on the action and success screens still works). On-screen pad buttons are unchanged.
6. The **Who’s working** board on the same page shows who is in or out. It updates after a punch without reloading the page.
7. Wait for the success screen. The kiosk resets by itself (default 8 seconds).

## Employee timecards and suggested edits

1. Employees sign in to WordPress (their existing AIO employee user) and open **My Time Clock** (`/my-time-clock/`). This is a front-end page, not wp-admin. They only see their own shifts.
2. The page is a timecard: pay-period dropdown (current, previous, and older periods), Print, Pay Period / Pay Code / Weekly summaries, and a Monday–Sunday calendar. Each day shows total hours and clock-in/clock-out pairs. Times are shown in the site timezone.
3. Pay period length (weekly or biweekly, default biweekly) and the Monday anchor (default 2026-09-07) are on the SMOTC settings screen. Weeks run Monday–Sunday. Past periods are display-only.
4. On the **current** pay period, a day with an open shift, a missed clock-out, a clock-out without a clock-in, a pending suggestion, or an employee flag shows an edit icon. **Correct this pay period** opens one form for every day in that period (change times, add a missing punch, reason required on each change).
5. A site admin, `time_clock_admin`, or anyone who can manage the kiosk opens **SMOTC → Timecards** to view any employee, and **SMOTC → Corrections** to approve or reject.
6. **Approve** writes the corrected `employee_clock_in_time` / `employee_clock_out_time` on the AIO `shift` (or creates a shift for a missing punch) and keeps seconds. The suggestion stores original times, the employee, the reviewer, and timestamps. **Reject** leaves punches unchanged. Submit and approve both refuse a change that would alter a shift in a closed pay period.

Open shifts older than the missed-clock-out setting (default 16 hours) are not treated as currently clocked in on the kiosk or who’s-working board.

The kiosk who’s-working board still reads the same open-shift rule after an approved correction.

## Office IP allowlist

1. Open **SMOTC** (or **Settings → SMOTC**). The allowlist is on the Kiosk settings tab.
2. Leave the checkbox off, or on with an empty box (comments and blank lines do not count). Kiosks keep working from every network, including the sandbox.
3. The page shows the address this browser is seen as. That is the same value the kiosk will check. On WP Engine it comes from `X-Forwarded-For` (then `True-Client-IP`, `X-Real-IP`, then `REMOTE_ADDR`).
4. To enforce: check **Only allow kiosk punches from these networks**, add that address or a CIDR such as `203.0.113.0/24`, one per line, and save. `#` starts a comment.
5. From an address on the list, PIN resolve, punch, the name list, and Who’s working still work. From any other address those requests return **This kiosk only works from the office network.** The message does not include an IP.
6. Open wp-admin from an address that is not on the list. Kiosk settings, PIN changes, and corrections still save. My Time Clock for a logged-in employee is not gated.

If every tablet is refused after you turn the list on, the proxy is not forwarding the office’s public address. Compare the address shown on this settings screen (load it from the office network) with what you entered. This screen itself stays reachable either way.

## Verify against AIO monitoring

1. Clock an employee **in** on a kiosk.
2. In wp-admin open **SMOTC → Real Time Monitoring**.
3. That employee should appear under **Employees Currently Working** with a clock-in time.
4. Clock the same employee **out** on the kiosk.
5. Refresh monitoring — they should leave the working list. The closed shift remains under **Shifts** / reports.

If monitoring is empty after a punch, confirm AIO Lite is active and the shift post type is registered, then edit the newest **Shift** and check `employee_clock_in_time` / `employee_clock_out_time`.

## Testing with sandbox employees

On carolinaspodev, existing AIO employees already have WordPress users. You only need to assign PINs:

1. Note two test employees (Employee role or equivalent).
2. Set distinct PINs (example: `2468` and `1357`) on **Employee PINs**.
3. Open the kiosk in a private window (logged out).
4. Clock in with employee A → confirm A is Working in monitoring.
5. Clock in with employee B from the name list → confirm both are Working.
6. Clock A out → only B remains Working.
7. Confirm a wrong PIN is rejected, and that after several failures the tablet is temporarily locked.

Do not commit real PINs. Treat them like passwords.

## Security

- Public AJAX uses a nonce (`css_tc_kiosk`). The employee times page uses a logged-in nonce (`css_tc_employee`); staff can only load or suggest edits for themselves. Admin PIN and correction screens require `manage_options`, `time_clock_admin`, or `edit_posts` when AIO is present, plus an admin nonce.
- The public roster action (`css_tc_roster`) returns display names, in/out status, and clock-in times only — no PINs, emails, user IDs, or admin data. It is rate-limited separately from the PIN lock (40 requests / minute / IP). The board is not transient-cached; a successful punch returns a fresh board payload.
- Failed PINs are counted per client IP (transient). After the configured limit the IP is locked for the window (default 5 failures / 15 minutes). The same client-IP helper is used for the office allowlist.
- Office allowlist (SMOTC screen): one IPv4, IPv6, or CIDR per line; `#` comments. Disabled or empty allows all, so a sandbox is not locked out. When it is enforcing, `css_tc_resolve_pin`, `css_tc_punch`, `css_tc_employees`, and `css_tc_roster` return “This kiosk only works from the office network.” with no IP in the error. Logged-in My Time Clock and every wp-admin screen stay open from any IP.
- On WP Engine, that helper trusts the first address in `X-Forwarded-For` (then `True-Client-IP`, `X-Real-IP`, then `REMOTE_ADDR`). The platform proxy is what makes the forwarded address trustworthy. A proxy in front of WP Engine must send the real client IP or every tablet looks like the proxy and will miss the office list. The settings screen shows the address this browser is seen as, so you can copy it onto the list. Shift meta `ip_address_in` / `ip_address_out` stores that same address.
- PIN lookup errors are generic (“That PIN was not recognized”).
- All kiosk output is escaped; all input is sanitized. PINs are digits-only before hashing.
- The kiosk never calls `wp_set_auth_cookie` / `wp_signon`.

## Folder structure

```
css-timeclock-addon.php    Plugin bootstrap
includes/                  Employees, PINs, punches, pay periods, timecards, corrections, AJAX, admin, shortcodes
admin/                     Settings UI
public/                    Kiosk markup, CSS, JS
uninstall.php              Removes settings, hashed PINs, and correction posts (AIO shifts stay)
```

## Local zip

```bash
./bin/make-zip.sh
```

Requires `zip`. The archive excludes `.git`, `dist`, `preview`, and this documentation’s tooling files.
