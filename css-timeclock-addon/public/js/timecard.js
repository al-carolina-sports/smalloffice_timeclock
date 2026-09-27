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

  function spanSeconds(line) {
    var inn = line.querySelector('input[name$="[proposed_in]"]');
    var out = line.querySelector('input[name$="[proposed_out]"]');
    var next = line.querySelector('input[name$="[out_next_day]"]');
    var inSec = hmsToSeconds(inn ? inn.value : "");
    var outSec = hmsToSeconds(out ? out.value : "");
    if (inSec === null || outSec === null) {
      return -1;
    }
    var diff = outSec - inSec;
    if (next && next.checked) {
      diff += 86400;
    }
    return diff < 0 ? -1 : diff;
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
      var seconds = spanSeconds(line);
      var slot = line.querySelector("[data-shift-hours]");
      if (slot) {
        slot.textContent = formatHours(seconds);
      }
      if (seconds >= 0) {
        total += seconds;
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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", refreshAll);
  } else {
    refreshAll();
  }
})();
