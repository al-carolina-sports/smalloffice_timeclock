# Carolina Sports and Spine Timeclock  
## Technical Whitepaper for Developers

**Document version:** 1.4  
**Date:** September 25, 2026  
**Audience:** Internal developers and technical collaborators  
**Status:** Phase 1 live on WP Engine sandbox; Phases 2–5 planned  

---

## 1. Executive summary

Carolina Sports and Spine (CSS), BioFunctional, and related practices need an employee punch clock that:

1. Makes it easy for staff to clock in/out at a shared desk tablet (so people stop forgetting).
2. Lets supervisors see **who is currently clocked in** in real time.
3. Runs on the practice’s existing **WP Engine** WordPress hosting (not a separate VPS or SaaS).
4. Can be customized in Cursor and shared via a private GitHub repo under `al-carolina-sports`.

**Current production ADP timeclock** is too primitive for those needs. **Phase 1** of a custom WordPress add-on is live on the WP Engine sandbox `carolinaspodev` and has been punch-tested end-to-end.

**Approach:** Keep [All in One Time Clock Lite](https://wordpress.org/plugins/aio-time-clock-lite/) (GPL, Codebangers) as the system of record for shifts and Real Time Monitoring. Ship a separate plugin, **CSS Time Clock Addon** (`css-timeclock-addon` v1.0.0), that adds shared-tablet kiosks without forking or editing AIO Lite’s files.

---

## 2. Problem statement

| Pain | Detail |
| --- | --- |
| Forgotten punches | Staff routinely forget to clock in; ADP does little to prevent that at the door. |
| No live visibility | Supervisors cannot reliably see who is currently working. |
| Hosting constraint | The business already runs WordPress sites on WP Engine; a standalone PHP/Node app is a poor operational fit. |
| Prior tool | A classic PHP Timeclock was previously hosted on Bluehost; that stack is abandoned, has known SQLi/XSS issues, and is unsupported on WP Engine. |

Practices in scope for the product vision: **Carolina Sports and Spine**, **BioFunctional**, **TrueRadianceMedispa**, plus an **Other** facility bucket; physical locations **Rocky Mount**, **Wilson**, and **Raleigh** (tied to facility in Phase 2).

---

## 3. Goals and non-goals

### Goals
- Shared **PIN kiosk** and **name-list / quick-pick kiosk** (no full WordPress login per punch).
- Live **who’s working** for supervisors (via AIO Real Time Monitoring today; dedicated boards in Phase 3).
- Multi-facility / multi-location punches (Phase 2).
- Bulletin / announcements + daily working board (Phase 3).
- Employee self-correction of times with **supervisor approval** and audit trail (Phase 4).
- Office **IP allowlist** so punches only succeed from clinic networks (Phase 5).
- Source of truth in a **private GitHub** repo for collaborators; deploy as a WordPress plugin to WP Engine.

### Non-goals (v1 / near term)
- Facial recognition, RFID/NFC hardware, native offline mobile apps.
- Full HRIS / PTO accruals / payroll engine.
- Replacing ADP payroll export workflows in Phase 1.
- Installing on live marketing sites (`carolinasportsandspine.com`, `biofunctionalmed.com`, `trueradiancemedispa.com`, etc.).

---

## 4. Alternatives considered

| Option | Verdict | Why |
| --- | --- | --- |
| Classic PHP Timeclock (SourceForge / forks) | Rejected for WP Engine | Abandoned lineage, known SQLi/XSS, unsupported as a non-WP app on WP Engine. |
| Kimai | Rejected for this host | Strong OSS timesheet product; Composer/console; live who’s-in / kiosk not free; project-time mental model. |
| IceHrm / OrangeHRM | Rejected | Full HR suites; IceHrm had unpatched SQLi (CVE-2026-15478 as of research window). |
| OpenTimeClock (opentimeclock.com) | Rejected as platform | Feature-rich **SaaS**, not self-hostable open source; “Open” is branding. |
| Time Clock (Scott Paterson) WP plugin | Not used as SoR | GPLv2+, small codebase, but plaintext employee passwords in free, no locations, almost no punch hooks; “currently working” UI is Pro-only. |
| AIO Time Clock Lite + custom add-on | **Selected** | GPL free plugin already has Real Time Monitoring; WP Engine–friendly; custom add-on can add kiosks without forking vendor files. |
| Custom plugin from scratch only | Deferred | More build time; AIO already stores shifts and monitoring. |

**Licensing note:** AIO Time Clock Lite and Time Clock free are both **GPLv2 or later** and unencoded (no ionCube) in the wordpress.org distributions inspected. Pro editions are separate paid products and must not be assumed forkable.

---

## 5. Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ WP Engine environment: carolinaspodev (PHP 8.2, sandbox)    │
│                                                             │
│  ┌──────────────────────┐    ┌────────────────────────────┐ │
│  │ AIO Time Clock Lite  │◄───│ CSS Time Clock Addon       │ │
│  │ (system of record)   │    │ (kiosk + PIN layer)        │ │
│  │                      │    │                            │ │
│  │ • shift CPT          │    │ • PIN / name kiosks        │ │
│  │ • Real Time Monitor  │    │ • hashed employee PINs     │ │
│  │ • employees / roles  │    │ • writes same shift meta   │ │
│  │ • reports            │    │ • no WP login for punch    │ │
│  └──────────────────────┘    └────────────────────────────┘ │
│                                                             │
│  Pages: /time-clock/  /pin-time-clock/  /name-time-clock/   │
└─────────────────────────────────────────────────────────────┘
```

### Design principles
1. **Do not edit AIO Lite files** — WordPress.org updates must not wipe customizations.
2. **Compatible punch writes** — Kiosk punches create/update the same `shift` posts AIO’s Real Time Monitoring already reads.
3. **WP Engine–safe** — No `exec`/`shell_exec`, no ionCube, no PHP in `uploads`, request work under the 60s process killer, prefer Alternate Cron for future scheduled jobs.
4. **Staff stay out of wp-admin** for punching; supervisors use monitoring / future boards.

### Why kiosks write shifts directly
AIO Lite’s front-end AJAX is registered for logged-in users and uses `get_current_user_id()`. A shared tablet must stay logged out as an employee. The add-on therefore writes AIO’s data model:

| Field | Value |
| --- | --- |
| Post type | `shift` |
| `post_title` | `Employee Shift` |
| `post_status` | `publish` |
| `post_author` | Employee WP user ID |
| `employee_clock_in_time` | `Y-m-d H:i:s` UTC instant (see §16) |
| `employee_clock_out_time` | Empty while working; set on clock-out |
| `department` | From AIO department taxonomy when present |
| `ip_address_in` / `ip_address_out` | Tablet IP |

An employee is **Working** when a shift has clock-in set and clock-out empty.

---

## 6. Current deployment (Phase 1)

### Environment
| Item | Value |
| --- | --- |
| WP Engine env | `carolinaspodev` only |
| URL | https://carolinaspodev.wpenginepowered.com/ |
| PHP | 8.2 |
| Site title (sandbox) | Carolina Sports and Spine Dev Timeclock |
| Live sites | **Not touched** (CSS, BioFunctional, True Radiance, etc.) |

### Installed software
| Component | Version / notes |
| --- | --- |
| All in One Time Clock Lite | 2.1.0 — active |
| CSS Time Clock Addon | 1.0.0 — active |
| License | GPLv2 or later |

### Public / admin URLs
| Purpose | URL |
| --- | --- |
| PIN kiosk | https://carolinaspodev.wpenginepowered.com/pin-time-clock/ |
| Name-list kiosk | https://carolinaspodev.wpenginepowered.com/name-time-clock/ |
| Logged-in AIO clock | https://carolinaspodev.wpenginepowered.com/time-clock/ |
| Real Time Monitoring | `/wp-admin/admin.php?page=aio-monitoring-sub` |
| Kiosk & PINs admin | Time Clock Lite → **Kiosk & PINs** |

### Shortcodes
- `[css_tc_pin_kiosk]` — large PIN pad → Clock in / Clock out  
- `[css_tc_name_kiosk]` — alphabetical name list → PIN confirm → Clock in / Clock out  

### Phase 1 security behaviors
- PINs stored with `wp_hash_password()`; verified with `wp_check_password()`; never stored or displayed in plaintext.
- Failed PIN attempts rate-limited by tablet IP (default 5 tries / 15 minutes).
- After punch: success UI, then idle kiosk — **no employee WordPress session**.
- Name-list requires PIN confirmation in v1.
- Employees without a PIN do not appear on the name list.

### Verification performed
1. PIN kiosk: Staff One clock in → Monitoring **Working** → clock out → Total 0.  
2. Name kiosk: Staff Two + PIN clock in → Monitoring **Working** → clock out → Total 0.  
No UI/JS errors observed during those tests.

### Plugin layout (developers)
```
css-timeclock-addon/
  css-timeclock-addon.php      # bootstrap
  uninstall.php
  includes/
    class-plugin.php
    class-admin.php
    class-ajax.php
    class-employees.php
    class-pins.php
    class-punches.php
    class-shortcodes.php
  admin/                       # settings UI + assets
  public/                      # kiosk views, CSS, JS
  bin/make-zip.sh              # WP Engine upload zip
  readme.txt / README.md
```

---

## 7. AIO Lite roles (context)

AIO Lite registers roles used for clockable staff and admins, including approximately:

- `employee`, `volunteer`, `manager`, `contractor` (and `aio_tc_*` aliases)
- `time_clock_admin` for timeclock administration

Employees are WordPress users with those roles. The add-on’s name list is driven from the same role set. Supervisors use WordPress admin (or `time_clock_admin`) for Real Time Monitoring today.

---

## 8. WP Engine constraints (must-know)

| Constraint | Implication |
| --- | --- |
| WordPress-shaped hosting | No Docker / Node / Laravel app on the WP plan; timeclock must be a plugin. |
| 60s max execution | No long-running punch jobs. |
| Disabled `exec` family | No shelling out. |
| No ionCube | Encoded Pro plugins will fail — confirm before buying encoded software. |
| No true server cron | Use Alternate Cron (hits `wp-cron.php`) for future missed-punch emails. |
| GitPush / GitHub Action | Preferred deploy path for plugin subdirectory under `wp-content/plugins/`. |
| Subdomain vs subdirectory | Prefer a dedicated WP environment / subdomain for a staff clock; subdirectory multi-site patterns are discouraged. |

Standalone PHP Timeclock beside WordPress on WP Engine is **unsupported** and should not be attempted.

---

## 9. Source control and collaboration

| Item | Status |
| --- | --- |
| Intended private repo | https://github.com/al-carolina-sports/CSS_timeclock |
| Account | `al-carolina-sports` |
| Intended layout | Repo **is** the plugin (or `css-timeclock-addon/` folder + `dist/*.zip`) |
| Current gap | GitHub PAT used by automation lacked **Contents: Write** at last push attempt; sandbox install used a local zip. Collaborators should treat the repo as the long-term home once write access is fixed. |

**Recommended collaborator workflow (target state):**
1. Develop on a branch in `CSS_timeclock`.
2. Build zip with `./bin/make-zip.sh`.
3. Deploy to `carolinaspodev` only (upload or GitHub Action → WP Engine).
4. Never deploy experimental builds to live marketing environments without an explicit go-ahead.

---

## 10. Product roadmap

| Phase | Scope | Status |
| --- | --- | --- |
| **1** | PIN kiosk + name-list/quick-pick; hashed PINs; AIO-compatible punches | **Done** on `carolinaspodev` |
| **2** | Multi-facility (CSS, BioFunctional, TrueRadianceMedispa, Other) + multi-location (Rocky Mount, Wilson, Raleigh) tied to facility; punch stores pair | Planned |
| **3** | Bulletin / announcement board + daily who’s-working board (staff-facing, not full wp-admin) | Planned |
| **4** | Employee dashboard; self-correction requests; supervisor approve/deny; audit of original vs corrected | Planned |
| **5** | Office IP / CIDR allowlist. Global list shipped in add-on 1.2.3 (kiosk punch, PIN resolve, name list, roster). Per-location lists wait on Phase 2. | **Global list in 1.2.3** |

Suggested build order after Phase 1: **2 → 5 → 3 → 4** (or 3 before 5 if boards are needed sooner). Missed-punch email alerts are a natural add-on after facilities/locations exist.

Optional commercial shortcut: AIO **Pro** (~$40/year) documents PIN/QR/locations/CSV — only if the Pro package is **not** ionCube-encoded on WP Engine. Prefer custom add-on control for clinic-specific facility/location rules and approval workflows.

---

## 11. Data model (Phase 2 sketch)

- **Facilities:** Carolina Sports and Spine · BioFunctional · TrueRadianceMedispa · Other  
- **Locations:** Rocky Mount · Wilson · Raleigh  
- **Constraint:** Only valid facility↔location pairs (configured in admin).  
- **Punch:** Every kiosk punch stores facility + location (+ optional kiosk/device id).  
- Tablets may be locked to a default pair so staff do not pick the wrong site.

---

## 12. Security and compliance notes

- Employee attendance punches are generally **not PHI**; still keep the clock off public marketing pages and use a sandbox / dedicated staff environment.
- Prefer hashed PINs (done), HTTPS (WP Engine), and future IP allowlists (Phase 5).
- Do not log raw PINs.
- Supervisor corrections (Phase 4) must retain an audit trail of who changed what and when.
- Review AIO Lite and the add-on after each WordPress core/plugin update on the sandbox before promoting.

---

## 13. How to extend (developer quickstart)

1. Confirm AIO Lite + CSS Time Clock Addon are active on `carolinaspodev`.  
2. Clone / open `CSS_timeclock` (once populated) or work from the plugin folder.  
3. Keep new features in the **add-on** (or a second add-on), not in patched AIO files.  
4. For punch-related features, read `includes/class-punches.php` and AIO’s `shift` meta conventions.  
5. For kiosk UI, start from `public/views/*`, `public/js/kiosk.js`, `public/css/kiosk.css`.  
6. Test against **Real Time Monitoring** after every punch-path change.  
7. Package with `./bin/make-zip.sh`; install only on the sandbox until sign-off.

---

## 14. Open items / risks

| Item | Risk / action |
| --- | --- |
| GitHub write access | Fix PAT `repo` / Contents: Write so source lives in `CSS_timeclock`. |
| Theme chrome on kiosks | Default theme sidebar still visible; Phase 3+ may add a kiosk template that hides chrome. |
| AIO upgrade compatibility | Soft dependency; re-verify shift meta after AIO updates. |
| Promotion to live | Requires separate decision; never auto-deploy to marketing prod. |
| Facility/location matrix | Product owner must confirm which locations apply to which facilities before Phase 2 coding freezes. |

---

## 15. Document history

| Version | Date | Notes |
| --- | --- | --- |
| 1.0 | 2026-09-19 | Initial whitepaper for developer sharing; reflects Phase 1 live on carolinaspodev. |
| 1.4 | 2026-09-25 | Add-on 1.4.0 timecards, pay periods, closed-period enforcement, and the UTC storage contract. |
| 1.4.1 | 2026-09-27 | Closed-period notice, long-shift flags, SMOTC Real Time Monitoring, and AIO clock times stored as UTC. |
| 1.4.2 | 2026-09-27 | Timecard layout follows the content column. Request change replaces Flag day. Sub-minute shifts and missed clock-outs are labeled. |
| 1.4.3 | 2026-09-27 | My Time Clock and the kiosks use a full-width page so the week grid can use a desktop screen. Wide layout setting turns it off. |
| 1.4.4 | 2026-09-27 | Kiosk and My Time Clock pages link to staff login, the timecard, and SMOTC Timecards. Correction time fields are wider on a wide sheet. |
| 1.4.5 | 2026-09-27 | Correction reasons are optional. Wide kiosk pages drop the logged-in admin-bar gap. |
| 1.4.6 | 2026-09-27 | Correction forms show day and shift hours, including overnight, and update as times are edited. |
| 1.4.7 | 2026-09-27 | Reviewed corrections keep the day and shift totals from the moment they were approved or rejected. Older reviewed rows are not backfilled. |
| 1.4.8 | 2026-09-27 | Corrections cards place Original shift and Proposed shift hours on the time lines. |
| 1.4.9 | 2026-09-27 | A pencil on every day with a punch. Employees open that day's correction. Managers edit the day immediately, recorded as Edited by manager. |

---

## 16. Timecards (add-on 1.4.0)

Employees open **My Time Clock** (`/my-time-clock/`, shortcode `[css_tc_my_times]`, logged-in, own shifts only). Supervisors open **SMOTC → Timecards** (`/wp-admin/admin.php?page=css-tc-timecards`) and can switch employees. Both screens share one sheet: pay-period dropdown, Print, three summary cards, and a Monday–Sunday day grid. Print uses `@media print` in `public/css/timecard.css`.

### Pay periods

Settings on the SMOTC screen:

| Setting | Default |
| --- | --- |
| Length | Biweekly (or weekly) |
| Anchor | Monday `2026-09-07` |
| Missed clock-out | 16 hours |
| Long shift | 16 hours (still counted in totals; flagged only) |

Weeks are Monday–Sunday. With the default anchor, 2026-09-07 through 2026-09-20 is one period and 2026-09-21 through 2026-10-04 is the next. The dropdown lists the current period, the previous period, and older periods. Only the current period accepts employee corrections. Past periods are display-only for employees, and `Css_Tc_Corrections::submit()`, `submit_period()`, and `approve()` reject any change whose clock-in day is outside that open period. A manager can still edit a closed period from SMOTC → Timecards. That editor warns first, skips the closed-period check, and records the change.

### Pay codes

Counted time is **Regular**. `css_tc_pay_codes` can register more codes (Holiday and others). `css_tc_shift_pay_code` can classify a shift. There is no overtime rule.

### Corrections

A closed period shows “This pay period is closed and can't be edited.” on the employee page, and that notice prints with the sheet. On SMOTC → Timecards the same banner says a manager can still edit a day. The day editor repeats that warning.

A finished shift longer than the long-shift setting (default 16 hours) is badged on the day and on the punch line, and counted in a warning above the cards. Those hours stay in the pay-period, pay-code, and weekly totals. Nothing rewrites the stored times until a correction is approved or a manager saves an edit.

In 1.4.9 a pencil is on every day that already has at least one punch. On My Time Clock, while the pay period is open, it opens the correction form scrolled to that day (`#day-YYYY-MM-DD`). The form is the same one as Correct this pay period, including Reason (optional). Submitting still creates a pending correction. The pencil is the only way to request a change for a day. Day cells do not show Request change or Cancel request. A pending correction keeps the Pending badge, and a small amber dot. Cancel request sits on that day's correction form and deletes the pending suggestion. The stored punches stay as they are. An approved correction shows a green dot. A closed period does not show the employee pencil. Correct this pay period stays above the summaries. A one-time schema upgrade (option `css_tc_correction_schema` = 4) removes flagged dates that have no pending correction, including a date that was flagged and never reviewed. It writes only the employee's `css_tc_flagged_dates` user meta and does not rewrite correction posts or shifts. Approving, rejecting, or a manager edit of that day does the same when nothing else is still pending for the employee and date. The timecard does not read that flag. The Pending badge and Cancel request follow the pending correction. Timecard clock times and totals are the stored punches only. A pending suggestion does not replace them, including on the day cell and in the pay-period total. The correction form can still show the proposed times in its inputs.

The correction form lists the whole open pay period (every day, extra punches, an optional reason per change). A blank reason is stored as sanitized text and the Corrections tab shows “No reason given”. Each day on that form shows **Total hours** beside the date, and each row shows **Shift hours** beside the next-day checkbox. Both follow the timecard’s nearest-minute rounding, zero-padded (`08:30`, `00:00`). A missing clock-out is `--:--` and is not added to the day. Checking “Clock-out is the next day” adds 24 hours. The totals update in the browser as the times change. The Corrections tab shows the proposed shift and the day’s total, and the original shift or original day when those differ. Pending cards calculate those figures from the shifts as they are now. Approving or rejecting stores a snapshot: Total hours is the day right after the decision, Original day is the day just before it, and Shift hours and Original shift come from the times on that correction. Later edits do not change a reviewed card. Corrections reviewed before 1.4.7 have no snapshot and are not backfilled; those cards show Proposed shift hours and Original shift only when both times are stored on the correction, and “Day totals not recorded” in place of the day totals. On the Corrections tab, Original shift sits on the time line and Proposed shift hours sits on the Proposed line. Pending cards label the stored times Current. Approved and rejected cards label them Before. A missing punch with no current times omits Original shift. Missing clock times still show as `--:--`. Each changed line is a `css_tc_correction` post. Approve writes `employee_clock_in_time` / `employee_clock_out_time` or creates a shift.

On SMOTC → Timecards the pencil opens an editor for that day, including a closed period. A manager who can edit timecards changes clock-in and clock-out, checks next day, adds a punch, or deletes a shift after confirming. Shift hours and Total hours update as the times change. Save writes the shift the same way an approval does (site timezone into UTC) and does not turn off the missed clock-out rule. Each change is a private `css_tc_correction` with reason “Edited by manager”, the manager, the time, an optional note, and the same before/after day snapshot as 1.4.7. It appears under Recently reviewed with an Edited by manager label. Deleting a shift records the old times and removes the punch.

**Flag day** (1.4.0–1.4.1) posted `css_tc_flag_day` and stored the date in user meta `css_tc_flagged_dates`. That made `needs_correction` true so the pencil appeared. The button was rendered on every day of the open period that did not already need a correction, including days with no punches, and the label was easy to misread as a status. In 1.4.2 the same action is labeled **Request change** and is shown only on days that already have punches and are not already flagged, pending, or otherwise marked. **Cancel request** cleared the flag. In 1.4.9 those links are gone from the day cells. The pencil opens the correction form, and **Cancel request** on a day that has a pending correction withdraws that suggestion. **Correct this pay period** is still the way to add a missing shift on an empty day. The manager editor's Add punch is only on a day that already has a punch. The day flag is not used for the pencil, the Pending badge, or Cancel request. Those follow a pending correction for the date. The meta is still cleared on review and on the schema 4 upgrade so an old Request change cannot leave the day marked. `css_tc_flag_day` remains registered so a cached form does not fail, and uninstall still deletes the meta.

The kiosk header and the My Time Clock bar include a small staff nav. Signed-out visitors get **Staff login**, which uses `wp_login_url()` and returns to My Time Clock from a kiosk, or back to the timecard page (including the corrections view) from My Time Clock. Signed-in staff get **My timecard**. People who can open SMOTC admin also get **Admin** (`css-tc-timecards`). A kiosk adds **Log out** back to that kiosk. My Time Clock adds **Time clock**, which opens the preferred kiosk. The same nav is rendered in the shortcode when Wide layout is off, so it is not drawn twice. Keyboard and numpad PIN entry ignore those links unless one of them is focused.

On a corrections sheet at least 900px wide, clock-in and clock-out each get a column up to 360px. Narrower sheets still stack the fields.

My Time Clock, the corrections view on that page, and the kiosk pages use `template_include` to render `public/views/wide-layout.php` instead of the theme template. The theme stylesheet is dequeued on those requests. A CSS breakout would still sit in Twenty Fifteen’s sidebar column and can be clipped by a parent with `overflow` hidden. The wide canvas is up to 1360px, so a desktop gets the seven-day grid. The SMOTC setting **Wide layout** (default on) turns the template off and leaves the pages in the theme column.

The day grid still uses container queries on `.css-tc-sheet`. At 680px and under, each day is one row, the weekday is Mon/Tue, and both clock times stay on the row. Between that and 1040px the week grid stays, with short weekday names and the clock-out stacked under the clock-in. Wider sheets keep the full names and a single in–out line. The long-shift badge is allowed to wrap.

A finished shift under 30 seconds displays as `<1 min` on that day. The raw seconds still add into the pay-period total, which is rounded to the nearest minute. An open shift does not display as `0:00`: inside the missed clock-out window it says **Still clocked in**, and after that **Missed clock-out**. The employee pencil is on that day while the period is open.

### How AIO Lite 2.1.0 stores times

`AIO_Time_Clock_Lite_Actions::getCurrentTime()` saves `employee_clock_in_time` and `employee_clock_out_time` with `wp_date( 'Y-m-d H:i:s' )`: a naive wall clock in `wp_timezone()`, with no offset in post meta. Display (`cleanDate()`) is `date( $format, strtotime( $stored ) )`. WordPress forces PHP’s default timezone to UTC, so `strtotime` and `date` both treat that naive string as UTC and the digits are shown unchanged.

On carolinaspodev the site timezone was UTC while those punches were stored, so every existing punch string **is** the UTC instant. A 5:05 PM America/New_York punch is stored as `21:05:00`. While the site timezone stayed UTC, every screen showed 9:05 PM.

This add-on keeps the meta format (`Y-m-d H:i:s`, no rewrite of old rows) and treats those strings as **UTC instants**. New kiosk punches and approved corrections are written in UTC (`DateTimeImmutable` / the same digits `wp_date()` produced while the site was UTC). Displays, correction inputs, and calendar days use `wp_timezone()`. After an admin sets the site timezone to `America/New_York`, `21:05:00` still means 21:05 UTC and renders as 5:05:00 PM Eastern on the same calendar day. Storage does **not** switch to `wp_date()` at that point: `wp_date()` would start writing Eastern wall clocks and the old UTC rows would be misread.

Form fields accept `H:i` or `H:i:s`. When seconds are omitted and the hour and minute match the stored punch, the original seconds are kept.

On carolinaspodev the timezone is now `America/New_York`. Strings already stored while the site was UTC remain UTC instants and display as Eastern. Add-on 1.4.1 does not rewrite those rows.

AIO’s clock page (`/time-clock/`, shortcode `[show_aio_time_clock_lite]`) redirects to an SMOTC kiosk. If `admin-ajax.php?action=aio_time_clock_lite_js` still runs `clock_in` or `clock_out`, the addon converts that `wp_date()` value from the site timezone to UTC before it is stored. Kiosk punches and approved corrections are written in UTC and are not converted a second time. Punches AIO already saved as Eastern wall clocks, before this version, are still misread as UTC until someone corrects them on the timecard.

SMOTC replaces the callback for **SMOTC → Real Time Monitoring** (`admin.php?page=aio-monitoring-sub`) without editing AIO. Fresh open shifts are “working now,” with clock-in shown in the site timezone. Open shifts older than the missed clock-out limit are a separate list and are not included in the working count. The Shift column links to that employee’s SMOTC timecard for the clock-in’s pay period and day (`#day-YYYY-MM-DD`). AIO’s `shift` post type has no edit screen, so `get_edit_post_link()` was empty and the cell was blank.

### Missed clock-out

`open_shift_for()` and the who’s-working board ignore open shifts older than the configured maximum (default 16 hours). Those rows stay open in the database and show on the timecard as missed clock-outs so a correction can add the clock-out. A newer open shift within the window still means the employee is clocked in.

---

*Prepared for internal technical collaboration. Sandbox credentials and test PINs are intentionally omitted from this document; request them through the project owner if needed for QA.*
