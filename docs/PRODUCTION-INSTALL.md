# USOTC 1.9.1 production install

Use this on a new WordPress site that has no employees yet. Upload the plugin zip and activate it. Do not run SQL, and do not edit USOTC Core (All in One Time Clock Lite) files.

## 1. Site

1. Install WordPress on PHP 8.2 or 8.3.
2. Set **Settings → General → Timezone** to `America/New_York` before the first punch. Stored punches are UTC instants and display in the site timezone.
3. Install and activate **All in One Time Clock Lite**. After USOTC is active it is labeled **USOTC Core**.
4. Upload `dist/css-timeclock-addon.zip` under **Plugins → Add New → Upload Plugin** and activate **Ultimate Small Office Timeclock**.

## 2. What activation creates

Published pages:

| Title | Slug | Contents |
| --- | --- | --- |
| PIN Time Clock | `pin-time-clock` | `[css_tc_pin_kiosk]` |
| Name Time Clock | `name-time-clock` | `[css_tc_name_kiosk]` |
| My Time Clock | `my-time-clock` | `[css_tc_my_times]` |
| Time Clock | `time-clock` | An HTML comment. Visiting `/time-clock/` redirects to the PIN kiosk. |

An existing page with one of those slugs is left as it is. Activating again does not add a second copy.

Locations, only when the location list is empty (USOTC → Locations & departments):

| Location | Office network |
| --- | --- |
| Raleigh | `76.195.93.124` |
| Rocky Mount | `66.76.190.146` |
| Wilson | `csswilson.ddns.net` |

No companies or departments are created. The department picker stays off.

These stay off until someone turns them on:

- Office IP allowlist
- Overtime
- Holidays
- PTO and sick time

Pay period defaults are biweekly, anchored on Monday `2026-09-07`. Missed clock-out and the long-shift limit are 16 hours. An employee with no employment-status record is Active.

An older install that already has settings keeps those settings. Missing keys (holidays, PTO, employment status) are filled with these defaults. The correction schema stays at version 4. Existing locations are not replaced.

## 3. Still done by a person

Activation does not create users.

1. Keep one WordPress administrator. Administrators can manage the time clock. USOTC Core’s Time Clock Admin role can too, after that role exists.
2. Create each employee as a WordPress user with an Employee, Volunteer, Manager, or Contractor role.
3. Set a PIN for each employee under **USOTC → Employees & PINs**. Hire date and employment status are optional; missing status means Active.
4. Optional, when the office is ready: turn on “Ask which department at clock-in”, assign departments, turn on overtime, holidays, or PTO. A 1-week overtime window cannot be set above 168 hours.

## 4. Check before the first punch

- `/time-clock/` opens the PIN kiosk.
- `/my-time-clock/` asks a signed-out visitor to log in.
- Locations lists Raleigh, Rocky Mount, and Wilson.
- TC-Config shows the IP allowlist, overtime, holidays, and PTO unchecked.
