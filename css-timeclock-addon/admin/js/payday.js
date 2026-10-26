(function () {
  "use strict";

  var calc = window.cssTcPaydayCalc;
  var pack = window.cssTcPayday || {};
  var admin = window.cssTcAdmin || {};
  var months = pack.months || ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  var days = pack.days || ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
  var strings = pack.strings || {};

  function pad(n) {
    n = String(n);
    return n.length < 2 ? "0" + n : n;
  }

  function parts(ymd) {
    if (!calc || !calc.isYmd(ymd)) {
      return null;
    }
    var p = ymd.split("-");
    var dt = new Date(Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10)));
    return {
      y: p[0],
      m: months[dt.getUTCMonth()] || "",
      d: String(dt.getUTCDate()),
      w: days[dt.getUTCDay()] || "",
    };
  }

  function formatLong(ymd) {
    var bit = parts(ymd);
    if (!bit) {
      return "";
    }
    return bit.w + " " + bit.m + " " + bit.d + ", " + bit.y;
  }

  function formatPayday(ymd) {
    var bit = parts(ymd);
    if (!bit) {
      return "";
    }
    return bit.w + ", " + bit.m + " " + bit.d + ", " + bit.y;
  }

  function formatRange(start, end) {
    var a = parts(start);
    var b = parts(end);
    if (!a || !b) {
      return "";
    }
    if (a.y === b.y) {
      return a.m + " " + a.d + " – " + b.m + " " + b.d + ", " + b.y;
    }
    return a.m + " " + a.d + ", " + a.y + " – " + b.m + " " + b.d + ", " + b.y;
  }

  function notice(message, isError) {
    var box = document.querySelector(".css-tc-notice");
    if (!box) {
      window.alert(message);
      return;
    }
    box.hidden = false;
    box.textContent = message;
    box.classList.toggle("is-error", !!isError);
    box.classList.toggle("is-success", !isError);
  }

  function checkedMode(form) {
    var radios = form.querySelectorAll('input[name="payday_mode"]');
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].checked) {
        return radios[i].value;
      }
    }
    return "weekday";
  }

  function readConfig(form) {
    var engine = pack.engine || {};
    return {
      frequency: form.payday_frequency.value,
      anchor: form.payday_period_anchor.value,
      engine_length: engine.length || "biweekly",
      engine_anchor: engine.anchor || "2026-09-07",
      week_start: form.payday_week_start.value,
      payday_mode: checkedMode(form),
      payday_weekday: form.payday_weekday.value,
      payday_days: form.payday_days.value,
      payday_anchor: form.payday_anchor.value,
      shift: form.payday_shift.value,
      holidays: form.payday_holidays.value,
    };
  }

  function weekdayName(dow) {
    var full = pack.dayNames || ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    var n = parseInt(dow, 10);
    return full[n] || full[0];
  }

  function syncRule(form) {
    var freq = form.payday_frequency.value;
    var fixed = freq === "semimonthly_1_15" || freq === "semimonthly_15_eom" || freq === "monthly";
    var onEnd = form.querySelector("[data-css-tc-on-end]");
    var anchorRow = form.querySelector("[data-css-tc-anchor-row]");
    if (onEnd) {
      onEnd.hidden = !fixed;
    }
    if (anchorRow) {
      anchorRow.hidden = freq === "engine";
    }
    if (!fixed) {
      var onEndRadio = form.querySelector('input[name="payday_mode"][value="on_end"]');
      var weekdayRadio = form.querySelector('input[name="payday_mode"][value="weekday"]');
      if (onEndRadio && onEndRadio.checked && weekdayRadio) {
        weekdayRadio.checked = true;
      }
    }
  }

  function glyph(form) {
    if (form.payday_marker.value === "icon") {
      return "💰";
    }
    if (form.payday_marker.value === "star") {
      return "★";
    }
    return "$";
  }

  function markerLabel(form) {
    if (form.payday_marker.value === "icon") {
      return "💰 " + (strings.payday || "Payday");
    }
    if (form.payday_marker.value === "star") {
      return "★ " + (strings.payday || "Payday");
    }
    return "$ " + (strings.payday || "Payday");
  }

  function paintMarker(form) {
    var preview = document.getElementById("css-tc-payday-marker-preview");
    if (!preview) {
      return;
    }
    preview.textContent = markerLabel(form);
    preview.style.background = form.payday_marker_color.value || "#00a32a";
  }

  function paint(form) {
    syncRule(form);
    paintMarker(form);
    var length = document.getElementById("css-tc-payday-length");
    var empty = document.getElementById("css-tc-payday-empty");
    var body = document.querySelector("#css-tc-payday-preview tbody");
    if (!calc || !body) {
      return;
    }
    var config = readConfig(form);
    var sample = config.frequency === "engine" ? (calc.isYmd(pack.today) ? pack.today : config.engine_anchor) : (calc.isYmd(config.anchor) ? config.anchor : pack.today);
    var period = calc.periodFor(config, sample);
    var mismatch = document.getElementById("css-tc-payday-mismatch");
    if (mismatch && calc.differs) {
      mismatch.hidden = !calc.differs(config, calc.isYmd(pack.today) ? pack.today : sample);
    }
    if (length) {
      if (!period) {
        length.textContent = strings.needAnchor || "Choose a pay period start date.";
      } else {
        var startDow = weekdayName(new Date(Date.UTC(parseInt(period.start.slice(0, 4), 10), parseInt(period.start.slice(5, 7), 10) - 1, parseInt(period.start.slice(8, 10), 10))).getUTCDay());
        var endDow = weekdayName(new Date(Date.UTC(parseInt(period.end.slice(0, 4), 10), parseInt(period.end.slice(5, 7), 10) - 1, parseInt(period.end.slice(8, 10), 10))).getUTCDay());
        var source = config.frequency === "engine" ? (strings.engine || "timecard pay period") : (strings.auto || "auto from frequency");
        length.textContent = period.length + " " + (strings.daysWord || "days") + " (" + source + ") — " + startDow + " " + (strings.through || "through") + " " + endDow;
      }
    }
    var rows = [];
    var ready = config.frequency === "engine" || calc.isYmd(config.anchor);
    if (config.payday_mode === "anchor" && !calc.isYmd(config.payday_anchor)) {
      ready = false;
    }
    if (ready) {
      var from = calc.isYmd(pack.today) ? pack.today : (config.frequency === "engine" ? config.engine_anchor : config.anchor);
      rows = calc.upcoming(config, from, 4);
    }
    body.textContent = "";
    if (empty) {
      empty.hidden = rows.length > 0;
    }
    rows.forEach(function (row) {
      var tr = document.createElement("tr");
      [formatLong(row.start), formatLong(row.end), glyph(form) + " " + formatPayday(row.payday), row.length + " " + (strings.daysWord || "days")].forEach(function (text) {
        var td = document.createElement("td");
        td.textContent = text;
        tr.appendChild(td);
      });
      body.appendChild(tr);
    });
  }

  function syncColor(picker, text) {
    function fromPicker() {
      text.value = picker.value;
      paint(text.form);
    }
    function fromText() {
      if (/^#[0-9a-fA-F]{6}$/.test(text.value)) {
        picker.value = text.value;
      }
      paint(text.form);
    }
    picker.addEventListener("input", fromPicker);
    text.addEventListener("input", fromText);
  }

  function post(payload) {
    var body = new URLSearchParams();
    body.set("action", "css_tc_save_payday");
    body.set("nonce", admin.nonce || "");
    Object.keys(payload).forEach(function (key) {
      body.set(key, String(payload[key]));
    });
    return fetch(admin.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || (strings.error || "Error"));
        }
        return json.data || {};
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    var form = document.querySelector(".css-tc-payday-form");
    if (!form || !calc) {
      return;
    }
    syncColor(form.querySelector("#payday_color_a_pick"), form.payday_color_a);
    syncColor(form.querySelector("#payday_color_b_pick"), form.payday_color_b);
    syncColor(form.querySelector("#payday_marker_color_pick"), form.payday_marker_color);
    form.addEventListener("input", function () {
      paint(form);
    });
    form.addEventListener("change", function () {
      paint(form);
    });
    paint(form);
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var button = form.querySelector("[type=submit]");
      if (button) {
        button.disabled = true;
      }
      var data = readConfig(form);
      data.payday_frequency = data.frequency;
      data.payday_period_anchor = data.anchor;
      data.payday_week_start = data.week_start;
      data.payday_mode = data.payday_mode;
      data.payday_weekday = data.payday_weekday;
      data.payday_days = data.payday_days;
      data.payday_anchor = data.payday_anchor;
      data.payday_shift = data.shift;
      data.payday_holidays = data.holidays;
      data.payday_show_employee = form.payday_show_employee && form.payday_show_employee.checked ? 1 : 0;
      data.payday_show_admin = form.payday_show_admin && form.payday_show_admin.checked ? 1 : 0;
      data.payday_show_closes = form.payday_show_closes && form.payday_show_closes.checked ? 1 : 0;
      data.payday_color_a = form.payday_color_a.value;
      data.payday_color_b = form.payday_color_b.value;
      data.payday_marker = form.payday_marker.value;
      data.payday_marker_color = form.payday_marker_color.value;
      data.payday_effective = form.payday_effective.value;
      post(data)
        .then(function (res) {
          notice(res.message || strings.saved || "Saved.", false);
          window.location.reload();
        })
        .catch(function (err) {
          notice(err.message, true);
          if (button) {
            button.disabled = false;
          }
        });
    });
  });
})();
