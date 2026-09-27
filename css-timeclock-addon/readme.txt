=== SMOTC ===
Contributors: css
Tags: time clock, kiosk, pin, employee, aio time clock
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PIN pad and name-list kiosk add-on for SMOTC Core (All in One Time Clock Lite). Clock in without a WordPress login.

== Description ==

Shared tablet kiosks for SMOTC Core. Employees enter a PIN (or pick their name, then confirm with a PIN) and clock in or out. Punches write the same shift posts Real Time Monitoring already reads.

This plugin does not modify aio-time-clock-lite files. In wp-admin the add-on is named SMOTC and All in One Time Clock Lite is named SMOTC Core.

== Installation ==

1. Upload the `css-timeclock-addon` folder to `/wp-content/plugins/`.
2. Activate SMOTC.
3. Activate All in One Time Clock Lite (shown as SMOTC Core once this plugin is active).
4. Set employee PINs under SMOTC.

== Changelog ==

= 1.4.1 =
* Closed pay periods show a notice on the employee timecard and on SMOTC → Timecards, including in print: "This pay period is closed and can't be edited."
* Finished shifts longer than a configurable maximum (default 16 hours, next to the missed clock-out setting) are flagged on the day and the punch line. Those hours still count in the totals. The current period offers the correction pencil. Stored times are not changed.
* SMOTC replaces AIO's Real Time Monitoring screen (same menu link). Fresh open shifts are "working now" in the site timezone. Open shifts older than the missed clock-out limit are listed separately and are not counted as working. One table header per table.
* AIO's /time-clock/ page (and any page with [show_aio_time_clock_lite]) redirects to an SMOTC kiosk. If AIO's clock AJAX still runs, those clock-in and clock-out times are stored as UTC instead of site-local wall clocks. Existing rows are not rewritten.
* On narrow screens the timecard lists each day in a row so clock-in and clock-out stay on one line.

= 1.4.0 =
* Employee timecards on My Time Clock (`/my-time-clock/`) and an admin SMOTC → Timecards screen (`admin.php?page=css-tc-timecards`) with an employee picker, previous/next arrows, and a print stylesheet.
* Pay period dropdown: current period, previous period, and older periods. Older periods are display-only. Settings: weekly or biweekly (default biweekly) and a Monday anchor (default 2026-09-07). Weeks are Monday–Sunday.
* Pay Period, Pay Code (Regular, filterable), and Weekly summaries, plus a Monday–Sunday day grid with each day’s total and clock-in/clock-out pairs.
* Current-period days that need a correction (open shift, missed clock-out, clock-out without a clock-in, or a day the employee flagged) show an edit icon. The corrections page submits the whole open pay period into the existing pending queue. Approving still updates or creates AIO shifts. Pending days show a badge.
* Server-side rejection of any correction submit or approval that would change a shift in a closed pay period.
* Times display and parse in the site timezone (`wp_timezone()`). Stored shift meta stays `Y-m-d H:i:s` UTC instants, so switching the site from UTC to America/New_York does not move existing punches in absolute time. Calendar days use that timezone.
* Open shifts older than a configurable maximum (default 16 hours) are missed clock-outs, not “clocked in”, on the kiosk, who’s-working board, and timecard.
* Correction times keep seconds when the existing punch had them.

= 1.2.4 =
* Admin brand is SMOTC. The top-level Time Clock Lite menu is SMOTC. The kiosk screen (admin.php?page=css-tc-addon) and Settings entry use that name too. Plugin list: this plugin is SMOTC, All in One Time Clock Lite is SMOTC Core, and that row’s author and plugin links are blank.
* Hide the Codebangers logo, header link, and Help support banner on AIO Lite admin screens without editing AIO’s files. Page headings that say All in One Time Clock Lite read SMOTC.
* Folder, main file, text domain, options, admin page slugs, and shortcodes are unchanged.

= 1.2.3 =
* Kiosk & PINs: optional office IP allowlist. When the checkbox is off, or the list has no addresses, every network can use the kiosks (an empty list does not lock the sandbox out). Lines starting with # are comments.
* When the list is on and has addresses, PIN resolve, punch, the name-list, and the who's-working roster only succeed from those IPv4/IPv6 addresses or CIDR ranges. The kiosk says "This kiosk only works from the office network." and does not reveal the client IP.
* The allowlist uses the same client IP as the failed-PIN rate limit, including the first address in X-Forwarded-For (WP Engine / reverse proxies). Stored punch IPs use that address too, so tablets are no longer counted as the load balancer. WordPress admin is not restricted.
* Hide AIO Lite Pro upsell UI in wp-admin via addon CSS.

= 1.2.2 =
* PIN kiosk and name-kiosk PIN confirm accept a physical keyboard or USB numpad: digit keys and numpad 0–9 append (still limited to the configured PIN length), Backspace and Delete remove the last digit, and Enter submits the same way as Continue.
* Escape on the PIN screen clears the digits and stays on that screen (same as Clear). On the name kiosk, the on-screen Cancel button still returns to the name list. Escape on the Clock in / Clock out screen cancels, and Escape on the success screen returns to idle immediately.
* Keystrokes are ignored in real text fields (the name search box), while a request is in progress, when the kiosk is turned off, and when the visible screen is not PIN entry (except Escape on the action and success screens).
* Employee PINs: the Set PIN field stays masked, with an eye button to show or hide the digits being typed. Saving still stores only a WordPress password hash.

= 1.2.1 =
* Who's-working board finds open shifts with the same PHP empty clock-out check as punch status and AIO monitoring (no WP_Query empty-string meta_query).
* Punch AJAX returns a fresh board payload so the kiosk paints Who's working immediately (no second roster request).
* Public roster transient is skipped so WP Engine cannot serve a stale empty board.

= 1.2.0 =
* Employee My Time Clock page: view own punches by day and suggest edits.
* Supervisor Corrections queue: approve (writes AIO shift + audit) or reject.

= 1.1.0 =
* Public live who's-working board on PIN and name-list kiosk pages (AJAX refresh).

= 1.0.0 =
* Phase 1: PIN kiosk, name-list kiosk, hashed PIN management, AIO-compatible punches.
