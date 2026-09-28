(function () {
  "use strict";

  document.addEventListener("change", function (event) {
    var el = event.target;
    if (!el || !el.hasAttribute || !el.hasAttribute("data-css-tc-jump")) {
      return;
    }
    if (el.value) {
      window.location.href = el.value;
    }
  });

  document.addEventListener("click", function (event) {
    var button = event.target && event.target.closest ? event.target.closest("[data-add-punch]") : null;
    if (!button) {
      return;
    }
    var day = button.closest("[data-day]");
    if (!day) {
      return;
    }
    var tmpl = day.querySelector("template");
    var lines = day.querySelector("[data-lines]");
    if (!tmpl || !lines) {
      return;
    }
    var html = tmpl.innerHTML.replace(/__INDEX__/g, String(Date.now()));
    var holder = document.createElement("div");
    holder.innerHTML = html.trim();
    if (holder.firstElementChild) {
      lines.appendChild(holder.firstElementChild);
      refreshDay(day);
    }
  });

  function pad(n) {
    return (n < 10 ? "0" : "") + n;
  }

  function hmsToSeconds(value) {
    var match = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(value || "");
    if (!match) {
      return null;
    }
    var hours = parseInt(match[1], 10);
    var minutes = parseInt(match[2], 10);
    var seconds = match[3] ? parseInt(match[3], 10) : 0;
    if (hours > 23 || minutes > 59 || seconds > 59) {
      return null;
    }
    return hours * 3600 + minutes * 60 + seconds;
  }

  function formMessages(form) {
    function read(name, fallback) {
      if (form && form.getAttribute) {
        var value = form.getAttribute(name);
        if (value) {
          return value;
        }
      }
      return fallback;
    }
    return {
      order: read("data-msg-order", 'Clock-out is earlier than clock-in. Fix the time, or check "Clock-out is the next day" if the shift ended after midnight.'),
      short: read("data-msg-order-short", "Clock-out is earlier than clock-in"),
      missing: read("data-msg-missing", "Enter a clock-in time."),
      bad: read("data-msg-bad-time", "Enter a valid clock time."),
      longTpl: read("data-msg-long", "This shift is %s long. Save anyway?")
    };
  }

  function badInput(input) {
    return !!(input && input.validity && input.validity.badInput);
  }

  function describeLine(line) {
    var msgs = formMessages(line.closest("form"));
    var inn = line.querySelector('input[name$="[proposed_in]"]');
    var out = line.querySelector('input[name$="[proposed_out]"]');
    var next = line.querySelector('input[name$="[out_next_day]"]');
    var del = line.querySelector('input[name$="[delete]"]');
    var shift = line.querySelector('input[name$="[shift_id]"]');
    var blank = { kind: "", problem: "", seconds: -1, hoursText: "--:--" };
    if (inn && inn.disabled) {
      return blank;
    }
    if (del && del.value === "1") {
      return blank;
    }
    if (badInput(inn) || badInput(out)) {
      return { kind: "time", problem: msgs.bad, seconds: -1, hoursText: "--:--" };
    }
    var inVal = inn ? (inn.value || "").trim() : "";
    var outVal = out ? (out.value || "").trim() : "";
    var shiftId = shift ? parseInt(shift.value, 10) || 0 : 0;
    if (!shiftId && inVal === "" && outVal === "") {
      return blank;
    }
    if ((inVal !== "" && hmsToSeconds(inVal) === null) || (outVal !== "" && hmsToSeconds(outVal) === null)) {
      return { kind: "time", problem: msgs.bad, seconds: -1, hoursText: "--:--" };
    }
    if (inVal === "") {
      return { kind: "in", problem: msgs.missing, seconds: -1, hoursText: "--:--" };
    }
    if (outVal === "") {
      return blank;
    }
    var diff = hmsToSeconds(outVal) - hmsToSeconds(inVal);
    if (next && next.checked) {
      diff += 86400;
    }
    if (diff < 0) {
      return { kind: "order", problem: msgs.order, seconds: -1, hoursText: msgs.short };
    }
    return { kind: "", problem: "", seconds: diff, hoursText: formatHours(diff) };
  }

  function setLineError(line, message) {
    var el = line.querySelector("[data-line-error]");
    if (!el) {
      return;
    }
    el.textContent = message || "";
    if (message) {
      el.hidden = false;
      line.classList.add("is-invalid");
    } else {
      el.hidden = true;
      line.classList.remove("is-invalid");
    }
  }

  function setSaveErrors(form, message) {
    form.querySelectorAll("[data-save-error]").forEach(function (el) {
      el.textContent = message || "";
      el.hidden = !message;
    });
  }

  function formatHours(seconds) {
    if (seconds < 0) {
      return "--:--";
    }
    var rounded = Math.round(seconds / 60);
    var hours = Math.floor(rounded / 60);
    var minutes = rounded % 60;
    return pad(hours) + ":" + pad(minutes);
  }

  function refreshDay(day) {
    if (!day) {
      return;
    }
    var total = 0;
    day.querySelectorAll("[data-line]").forEach(function (line) {
      var state = describeLine(line);
      var slot = line.querySelector("[data-shift-hours]");
      if (slot) {
        slot.textContent = state.hoursText;
        if (state.kind === "order") {
          slot.classList.add("is-problem");
        } else {
          slot.classList.remove("is-problem");
        }
      }
      if (line.classList.contains("is-invalid")) {
        setLineError(line, state.problem);
      }
      if (state.seconds >= 0) {
        total += state.seconds;
      }
    });
    var daySlot = day.querySelector("[data-day-total]");
    if (daySlot) {
      daySlot.textContent = formatHours(total);
    }
  }

  function refreshAll() {
    document.querySelectorAll(".css-tc-correct [data-day]").forEach(refreshDay);
  }

  document.addEventListener("input", function (event) {
    var day = event.target && event.target.closest ? event.target.closest(".css-tc-correct [data-day]") : null;
    if (day) {
      refreshDay(day);
    }
  });

  document.addEventListener("change", function (event) {
    var day = event.target && event.target.closest ? event.target.closest(".css-tc-correct [data-day]") : null;
    if (day) {
      refreshDay(day);
    }
  });

  function revealHashedDay() {
    var hash = window.location.hash || "";
    if (hash.indexOf("#day-") !== 0) {
      return;
    }
    var day = document.getElementById(hash.slice(1));
    if (!day || !day.scrollIntoView) {
      return;
    }
    window.setTimeout(function () {
      day.scrollIntoView({ block: "start" });
    }, 0);
  }

  document.addEventListener("click", function (event) {
    var btn = event.target && event.target.closest ? event.target.closest("[data-delete-shift]") : null;
    if (!btn) {
      return;
    }
    event.preventDefault();
    var line = btn.closest("[data-line]");
    var day = btn.closest("[data-day]");
    if (!line) {
      return;
    }
    var shiftInput = line.querySelector('input[name$="[shift_id]"]');
    var shiftId = shiftInput ? parseInt(shiftInput.value, 10) : 0;
    if (!shiftId) {
      line.remove();
      refreshDay(day);
      return;
    }
    var message = btn.getAttribute("data-confirm") || "Delete this shift?";
    if (!window.confirm(message)) {
      return;
    }
    var flag = line.querySelector('input[name$="[delete]"]');
    if (flag) {
      flag.value = "1";
    }
    var form = btn.closest("form");
    if (form) {
      form.submit();
    }
  });

  document.addEventListener("submit", function (event) {
    var form = event.target;
    if (!form || !form.getAttribute) {
      return;
    }
    var manager = form.hasAttribute("data-manager-edit");
    var employee = form.classList && form.classList.contains("css-tc-correct__form");
    if (!manager && !employee) {
      return;
    }

    var problems = [];
    var seen = {};
    form.querySelectorAll("[data-line]").forEach(function (line) {
      var state = describeLine(line);
      if (state.problem) {
        setLineError(line, state.problem);
        if (!seen[state.problem]) {
          seen[state.problem] = true;
          problems.push(state.problem);
        }
      } else {
        setLineError(line, "");
      }
    });
    if (problems.length) {
      event.preventDefault();
      setSaveErrors(form, problems.join(" "));
      var first = form.querySelector(".css-tc-correct__line.is-invalid");
      if (first && first.scrollIntoView) {
        first.scrollIntoView({ block: "nearest" });
      }
      return;
    }

    setSaveErrors(form, "");
    if (!manager) {
      return;
    }

    var limit = parseFloat(form.getAttribute("data-long-hours") || "16");
    if (!isFinite(limit) || limit <= 0) {
      limit = 16;
    }
    var maxSeconds = limit * 3600;
    var longs = [];
    form.querySelectorAll("[data-line]").forEach(function (line) {
      var state = describeLine(line);
      if (state.seconds > maxSeconds) {
        longs.push(state.seconds);
      }
    });
    var msgs = formMessages(form);
    var i;
    for (i = 0; i < longs.length; i++) {
      var label = formatHours(longs[i]);
      var text = msgs.longTpl.indexOf("%s") >= 0 ? msgs.longTpl.replace("%s", label) : "This shift is " + label + " long. Save anyway?";
      if (!window.confirm(text)) {
        event.preventDefault();
        return;
      }
    }
  });

  function boot() {
    refreshAll();
    revealHashedDay();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();

// "Your PIN" reveal on My Time Clock. The PIN is fetched only when the eye is
// pressed, and hidden again after 20 seconds.
(function () {
  "use strict";

  function bindMyPin() {
    var box = document.querySelector("[data-css-tc-mypin]");
    if (!box) {
      return;
    }
    var value = box.querySelector("[data-mypin-value]");
    var toggle = box.querySelector("[data-mypin-toggle]");
    if (!value || !toggle) {
      return;
    }
    var timer = null;

    function hide() {
      window.clearTimeout(timer);
      value.textContent = "••••";
      toggle.setAttribute("aria-pressed", "false");
      toggle.setAttribute("aria-label", toggle.getAttribute("data-label-show") || "Show PIN");
    }

    toggle.addEventListener("click", function () {
      if (toggle.getAttribute("aria-pressed") === "true") {
        hide();
        return;
      }
      var body = new URLSearchParams();
      body.set("action", "css_tc_reveal_my_pin");
      body.set("nonce", box.getAttribute("data-nonce") || "");
      fetch(box.getAttribute("data-ajax-url"), {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: body.toString(),
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (json) {
          if (!json || !json.success) {
            value.textContent = (json && json.data && json.data.message) || "";
            return;
          }
          value.textContent = json.data.pin;
          toggle.setAttribute("aria-pressed", "true");
          toggle.setAttribute("aria-label", toggle.getAttribute("data-label-hide") || "Hide PIN");
          window.clearTimeout(timer);
          timer = window.setTimeout(hide, 20000);
        });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindMyPin);
  } else {
    bindMyPin();
  }
})();
