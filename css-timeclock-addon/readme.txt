=== Ultimate Small Office Timeclock ===
Contributors: css
Tags: time clock, kiosk, pin, employee, aio time clock
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

PIN pad and name-list kiosk add-on for USOTC Core (All in One Time Clock Lite). Clock in without a WordPress login.

== Description ==

Shared tablet kiosks for USOTC Core. Employees enter a PIN (or pick their name, then confirm with a PIN) and clock in or out. Punches write the same shift posts Real Time Monitoring already reads.

This plugin does not modify aio-time-clock-lite files. In wp-admin the add-on is named Ultimate Small Office Timeclock (USOTC in the menu) and All in One Time Clock Lite is named USOTC Core.

== Installation ==

1. Upload the `css-timeclock-addon` folder to `/wp-content/plugins/`.
2. Activate USOTC.
3. Activate All in One Time Clock Lite (shown as USOTC Core once this plugin is active).
4. Set employee PINs under USOTC.

== Changelog ==

= 1.8.0 =
* PTO & sick time (TC-Config → PTO & sick, off by default). Each employee's leave year runs from their hire-date anniversary; the whole year's hours are available at the start of the year and unused hours are lost at the anniversary. Separate first-year amounts (default 24 h PTO; sick blank = same as yearly), usable once the introductory period ends. Settings: hours per year, first-year hours, "Sick time comes out of PTO" (one bank), days of notice for PTO (default 14), length of a day off, smallest amount (15 / 30 / 60 min), allow negative balance.
* My Time Clock → Request time off: balances, a month calendar (holidays, requested and approved days, days blocked by the notice rule or the introductory period), PTO or Sick, full or partial days, a note; cancel pending or future approved requests.
* USOTC → Time off (with a pending count): approve or deny (reason required), add time off for an employee for a missed day (requires "Employee agreed" and a note; approved at once, notice rule does not apply), who's-out calendar, recent decisions with cancel.
* PTO Summary at the top of every timecard (total, used, remaining, pending); Used links to that employee's used days (Reports → PTO & sick for managers, Request time off for employees). PTO and Sick pay codes on timecard days and summaries, in total paid hours, and in Reports (columns, By employee, CSV); never counted toward overtime.
* Reports → PTO & sick tab: PTO summary by employee (also under the pay period summary) with CSV, and each employee's used days per leave year. Employee profile: per-employee hours, balances and logged adjustments. Employees & PINs shows hours left.

= 1.7.0 =
* Paid holidays (TC-Config → Holidays, off by default): check the holidays the office observes (New Year's Day, Memorial Day, Independence Day, Labor Day, Thanksgiving and Christmas are pre-checked), add others as MM-DD Name (every year) or YYYY-MM-DD Name (one date), and set the hours paid per holiday (default 8). A weekend holiday is paid on Friday (Saturday) or Monday (Sunday); Christmas Eve and New Year's Eve move back to Friday. Each eligible employee gets the Holiday pay code on that day, shown on the timecard day, in the pay code, weekly and company summaries, and in Reports (Holiday column; Total is worked plus holiday). Holiday hours never count toward overtime and are charged to the employee's home department.
* Hire date and introductory period (30, 60 or 90 days) on each employee's profile. No holiday pay before the hire date or during the introductory period; the timecard says why and when holiday pay starts. The Employees & PINs tab shows each hire date.
* The PIN kiosk is the main time clock. Tap your name under Who's working (Not clocked in, or Working now to clock out) and the PIN pad asks for that person's PIN; typing a PIN without tapping still works. The name-list kiosk is retired: its page and shortcode open the PIN kiosk.

= 1.6.3 =
* Renamed to Ultimate Small Office Timeclock (USOTC in the admin menu; formerly SMOTC). Display names only: the plugin folder, code prefixes, settings and data are unchanged, so it updates in place.
* The time clock's own settings are now called TC-Config (menu item and tab; formerly Settings / Kiosk settings). AIO's page stays "Base clock settings".

= 1.6.2 =
* Office hostnames: the office IP allowlist and each location's office network accept a hostname (for example an office on dynamic DNS, csswilson.ddns.net) as well as addresses and CIDR ranges. Names are looked up every five minutes in the background and again at punch time if the saved answer is over ten minutes old. If a lookup fails, the last address that worked is kept; a name that has never resolved matches no one (the allowlist stays closed rather than opening to everyone). Settings and the Locations tab show where each name points and when it was checked, and "This computer's address" says which name it matched. Trusted proxies still take addresses only.
* Refused kiosk requests (from the Cursor version, PR #22): requests turned away by the allowlist are logged (address, time, action, and the employee if the request named one; never a PIN), at most one per address per minute, the last 200 kept, and listed in Settings. Managers get a notice when an address was refused in the last hour, with Add this IP to the allowlist and Dismiss. The kiosk message now says "Please tell your manager."
* The command-line checks in bin/ are left out of the plugin zip and refuse to run from the web.

= 1.6.1 =
* Security: the client address (office IP allowlist, office detection for locations, failed-PIN limits) can no longer be faked with an X-Forwarded-For header. Forwarded headers are only believed when the request comes through the hosting network (private or internal addresses) or a proxy listed under the new "Trusted proxies" setting, and then the right-most outside address is used. Settings shows what each request looks like, and the Locations tab shows this computer's address and which location it matches.
* Security: only administrators and AIO's Time Clock Admin role manage the time clock (new css_tc_manage capability). Before, anyone who could write posts (Contributor, Author, Editor) could open timecards, reports and PINs. The Time Clock menu and AIO's own time clock pages are hidden and closed for everyone else.

= 1.6.0 =
* Companies, locations and departments. USOTC → Locations & departments sets up companies (employers, one payroll each), locations (offices, with their office network IP addresses) and departments (inside a location, owned by one company). "Import AIO departments" builds this from AIO department names written as Location-Department (for example Raleigh-CSS) and assigns employees.
* Employee assignments on the user profile: tick every department an employee can clock into and mark one as home.
* Kiosk department picker, turned on by "Ask which department at clock-in, and allow Switch" in Kiosk settings (off by default, so single-site installs see no change). The location comes from the kiosk page (shortcode attribute location="Raleigh"), else the office network, so a desk computer on the office network works when a tablet is down; otherwise the employee picks. Employees only see their assigned departments, home first; with one choice the question is skipped.
* Cleaner admin menu under the time clock: Timecards, Reports, Who's working, Corrections (with pending count), Employees & PINs, Locations & departments, Settings, and Base clock settings (AIO, administrators only) last. AIO's Employees and Shifts pages, and AIO's Departments page and Users → Department while USOTC departments are on, are removed from the menu and closed (Shifts edited punches without the correction audit trail). The duplicate Settings → USOTC entry is gone.
* Reports → Pay period summary has a "By employee" section: each employee's hours split by company, department and location (shifts, Regular, Overtime, Total) with a subtotal per employee, following the report filters, with its own CSV.
* Fix: the Set PIN box is a masked text field instead of a password field, so password managers no longer autofill or replace the PIN (which made saves fail with "PIN must be 4 to 8 digits"). Only digits can be typed, and the save result or error now shows next to that row's Save PIN button.
* Fix: scripts and styles are versioned by install time, so browsers and WP Engine's cache load the new files after every update (the PIN eye was running old cached code).
* A manager can choose "No home department" on an employee's profile; the kiosk then lists their choices alphabetically with no Home badge.
* With departments at clock-in on, AIO's single-choice "Department" list is removed from the user profile so only "Time clock departments" shows (saved with Update User).
* Fix: saving a user profile with an AIO department selected no longer crashes the site. AIO Lite's department taxonomy names a count function it never defines (aio_lite_update_department_count); USOTC now provides it when AIO does not.
* Employee PINs tab has a PIN column: managers press the eye to reveal an employee's PIN (hidden again after 20 seconds), and employees can reveal their own PIN on My Time Clock. New PINs are also stored encrypted with a key derived from the site's wp-config.php secret keys; the kiosk still checks the password hash. Each reveal is logged and the last viewer is shown. PINs set before this version show "Set a new PIN to view it".
* Switch has its own setting, "Allow Switch" (on by default when departments at clock-in is on). Turned off, the Switch button and the "Switch to <office>" prompt are hidden and switch requests are refused, so employees clock out and back in to change departments.
* Switch: a clocked-in employee can move to another company, location or department in one tap. The open shift ends and the new one starts at the same second. Entering a PIN at a different office while still clocked in offers "Switch to <location>". Missed clock-out and long-shift checks count from the start of the chain. Travel between offices stays in the first segment.
* A per-employee punch lock stops two taps or two kiosks from opening two shifts.
* Timecards show company · department · location on every segment, hours by company, and overtime per company. Managers can change a segment's department in the day editor; employees can request a department change as a correction. Both are recorded.
* Overtime setting "With more than one company": add up hours across companies (default; overtime is charged to the company whose hours crossed the limit) or count each company separately.
* Reports filter and total by company, location and department. Filtering by company gives that company's payroll CSV. Shift detail shows where each segment was worked, switches, and clock-ins made from another office. Real Time Monitoring and the who's-working board show where people are working.

= 1.5.0 =
* Overtime setting under USOTC settings: "Hours worked after X hours per 1 or 2 weeks are overtime." Off by default. US federal overtime is 40 hours per 1 week; other countries or averaging rules can use a different number of hours or a 2-week window (biweekly pay period only). Weeks are the pay period's Monday–Sunday weeks. Timecards show Overtime as its own pay code, with the overtime included in each week's total.
* USOTC → Reports replaces AIO Lite's Reports screen, which printed stored UTC times as local time (4 to 5 hours late in Eastern time). Two prebuilt reports, both filterable by pay period and department and downloadable as CSV: Pay period summary (hours per employee by week, Regular, Overtime, total in H:MM and decimal hours, and items that need attention such as long shifts, missed clock-outs and pending requests, plus totals by department) and Shift detail (every shift in site time with clock-in and clock-out IP addresses and flags). The report defaults to the last finished pay period.

= 1.4.11 =
* Day status icons on My Time Clock and USOTC → Timecards follow a standard color scheme. A blue pencil means the day can be edited. A green check means a correction was approved or a manager already edited that day, and the blue pencil stays beside it while the period is open. Amber is a pending request. Red is only a real problem: missed clock-out, clock-out before clock-in, or a shift over 16 hours. A gray lock means the pay period is closed. Employees cannot click the lock. Managers still open the closed-period warning. Future days have no icon. The legend under Day Summary reads: Blue pencil: edit · Green check: changes completed · Amber: pending request · Red: needs attention · Gray lock: pay period closed.

= 1.4.10 =
* On USOTC → Timecards, Save changes is a primary button under the punches and next to the day heading. It stays enabled. An invalid row is not saved. Invalid means the clock-out is earlier than the clock-in with next day unchecked, a missing clock-in, or a time that cannot be read. That row and the button say what to fix. For an earlier clock-out the message is: Clock-out is earlier than clock-in. Fix the time, or check "Clock-out is the next day" if the shift ended after midnight. Shift hours shows that problem instead of --:--.
* A manager can save a shift longer than 16 hours after confirming the length (This shift is 18:20 long. Save anyway?). The times are stored as entered. The timecard keeps the long-shift flag and does not turn that shift back into a missed clock-out. The 16-hour missed clock-out rule still applies only to an open shift with no clock-out. Entering a clock-out on a missed clock-out clears that state.
* A successful save returns to the timecard with a green Saved notice. The day and pay-period totals include the new times. If the server rejects the save, the editor stays open, shows the reason in red, and keeps the times that were typed.
* A stored shift whose clock-out is before its clock-in shows a red Clock-out before clock-in flag on My Time Clock and on USOTC → Timecards. Those shifts count as 0 in the totals. The manager editor opens that row with the error already visible.
* The employee correction form on My Time Clock uses the same messages when a request cannot be submitted. It does not ask to confirm a shift over 16 hours.
* The day pencil is red, green, or gray on My Time Clock and on USOTC → Timecards. Red means the day can be edited. Green means an approved correction or a manager edit is already on that day, and it stays clickable while the period is open. Gray means the pay period has ended. Employees cannot click a gray pencil. Managers can, and the closed-period warning is unchanged. Future days have no pencil. The green approved dot is gone. A pending request still shows the Pending badge. Under Day Summary: Red pencil: edit · Green: changes completed · Gray: pay period closed.

= 1.4.9 =
* Today and earlier days in the pay period show a pencil, including a day with no punches. On My Time Clock, while the pay period is open, the pencil opens that day's correction form. An empty day can request a new shift: clock-in, clock-out, next day, and Reason (optional). Submitting still waits for a manager. Days after today have no pencil, and those fields stay closed. The day cells do not have a Request change link. A pending day keeps the Pending badge. Cancel request is on that day's correction form and withdraws the pending suggestion without changing punches. An approved correction shows a small dot. Closed periods do not offer the employee pencil. Correct this pay period stays at the top of the timecard. Today is the site timezone, Eastern time.
* Timecard clock times and totals come from stored punches. A pending suggestion does not change them.
* Approving or rejecting a correction, and a manager edit of that day, removes the old Request change flag for that date when no other suggestion is still pending. The first load after this update does the same for flags left behind, including a day that was flagged and never reviewed. That cleanup changes only the employee's flagged-dates list. The timecard does not read the flag. The Pending badge and Cancel request follow the pending correction.
* On USOTC → Timecards the pencil is on today and earlier days, including a day with no punches. A later day has no pencil, and saving one is refused. The editor changes clock-in, clock-out, next day, adds a punch, or deletes a shift after a confirmation. An empty day starts with a blank punch. Shift hours and the day total update as you type. Each change is an auto-approved correction labeled Edited by manager, with the before and after times and the day totals. Closed periods warn and can still be edited through today.

= 1.4.8 =
* The Corrections tab puts Original shift on the time line and Proposed shift hours on the Proposed line. Pending cards label that line Current. Approved and rejected cards label it Before. The separate shift-hours line is gone. Day totals, snapshots, and legacy cards are unchanged.

= 1.4.7 =
* Approving or rejecting a correction stores that day's Total hours after the decision and the Original day total from just before it, plus the shift's Shift hours and Original shift. Reviewed cards keep those numbers. Pending cards still calculate from current shifts.
* Reviewed corrections saved before this version are not backfilled. They show Shift hours and Original shift from the times stored on that correction, and "Day totals not recorded" instead of a live day total.

= 1.4.6 =
* Each day on the employee corrections form shows Total hours next to the date, and each punch row shows Shift hours beside “Clock-out is the next day”. Both update as the times or the next-day box change. A missing clock-out shows --:-- and is left out of the day total.
* The admin Corrections list shows the same totals from the proposed times, with the original shift and original day when they differ.

= 1.4.5 =
* The employee corrections form labels the note "Reason (optional)". An empty reason is saved. The admin Corrections list shows "No reason given" for that suggestion.
* Wide-layout kiosk pages no longer keep the 32px admin-bar gap at the top when a staff member is logged in, so the PIN pad stays on the screen.

= 1.4.4 =
* Kiosk pages keep a small Staff login link in the header when nobody is signed in. It opens the WordPress login and returns to My Time Clock. Signed-in staff see My timecard, managers also see Admin, and Log out returns to that kiosk. The links stay out of the PIN pad and the name list, and they still show when Wide layout is off.
* My Time Clock and the corrections view link to login when signed out, and to USOTC Timecards for managers. A Time clock link opens the kiosk.
* Clock-in and clock-out fields on the corrections form use a wider pair of columns on a wide sheet. A narrow window still stacks them.

= 1.4.3 =
* My Time Clock, the corrections view, and the kiosks use a full-width page instead of the theme content column, so a desktop shows the seven-day week grid. A setting, Wide layout, turns that off and leaves the pages inside the theme. A narrow window still stacks each day. The theme stylesheet is not loaded on those pages, so a sidebar cannot cover the sheet.

= 1.4.2 =
* The timecard follows the width of its column, not the browser window. In a narrow theme column (about 500px, as on Twenty Fifteen) each day is its own row, weekday names shorten to Mon, Tue, and clock-in and clock-out both stay visible. A medium column keeps the week grid with short names and stacks the out time under the in time. A wide column keeps the full week grid.
* The long-shift badge wraps instead of being clipped.
* "Flag day" was a control on every day of the open period, including empty days. It only marked that day so the correction pencil appeared. It is now "Request change", and only on days that already have punches and do not already show the pencil. "Cancel request" removes it.
* A finished shift shorter than 30 seconds displays as "<1 min" instead of "0:00". Those seconds still count in the period total. An open shift shows "Still clocked in" while it is inside the missed clock-out window, and "Missed clock-out" after that, with the correction pencil. It is not shown as 0:00.
* Real Time Monitoring's Shift column opens that employee's USOTC timecard on the punch's pay period and day. The shift post type has no edit screen, so the old edit link was blank.

= 1.4.1 =
* Closed pay periods show a notice on the employee timecard and on USOTC → Timecards, including in print: "This pay period is closed and can't be edited."
* Finished shifts longer than a configurable maximum (default 16 hours, next to the missed clock-out setting) are flagged on the day and the punch line. Those hours still count in the totals. The current period offers the correction pencil. Stored times are not changed.
* USOTC replaces AIO's Real Time Monitoring screen (same menu link). Fresh open shifts are "working now" in the site timezone. Open shifts older than the missed clock-out limit are listed separately and are not counted as working. One table header per table.
* AIO's /time-clock/ page (and any page with [show_aio_time_clock_lite]) redirects to an USOTC kiosk. If AIO's clock AJAX still runs, those clock-in and clock-out times are stored as UTC instead of site-local wall clocks. Existing rows are not rewritten.
* On narrow screens the timecard lists each day in a row so clock-in and clock-out stay on one line.

= 1.4.0 =
* Employee timecards on My Time Clock (`/my-time-clock/`) and an admin USOTC → Timecards screen (`admin.php?page=css-tc-timecards`) with an employee picker, previous/next arrows, and a print stylesheet.
* Pay period dropdown: current period, previous period, and older periods. Older periods are display-only. Settings: weekly or biweekly (default biweekly) and a Monday anchor (default 2026-09-07). Weeks are Monday–Sunday.
* Pay Period, Pay Code (Regular, filterable), and Weekly summaries, plus a Monday–Sunday day grid with each day’s total and clock-in/clock-out pairs.
* Current-period days that need a correction (open shift, missed clock-out, clock-out without a clock-in, or a day the employee flagged) show an edit icon. The corrections page submits the whole open pay period into the existing pending queue. Approving still updates or creates AIO shifts. Pending days show a badge.
* Server-side rejection of any correction submit or approval that would change a shift in a closed pay period.
* Times display and parse in the site timezone (`wp_timezone()`). Stored shift meta stays `Y-m-d H:i:s` UTC instants, so switching the site from UTC to America/New_York does not move existing punches in absolute time. Calendar days use that timezone.
* Open shifts older than a configurable maximum (default 16 hours) are missed clock-outs, not “clocked in”, on the kiosk, who’s-working board, and timecard.
* Correction times keep seconds when the existing punch had them.

= 1.2.4 =
* Admin brand is USOTC. The top-level Time Clock Lite menu is USOTC. The kiosk screen (admin.php?page=css-tc-addon) and Settings entry use that name too. Plugin list: this plugin is USOTC, All in One Time Clock Lite is USOTC Core, and that row’s author and plugin links are blank.
* Hide the Codebangers logo, header link, and Help support banner on AIO Lite admin screens without editing AIO’s files. Page headings that say All in One Time Clock Lite read USOTC.
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
