(function () {
  "use strict";

  var cfg = window.cssTcTimes || {};
  var strings = cfg.strings || {};
  var root = document.querySelector(".css-tc-times[data-enabled='1']");
  if (!root) {
    return;
  }

  function $(sel) {
    return root.querySelector(sel);
  }

  function text(el, value) {
    if (el) {
      el.textContent = value || "";
    }
  }

  function show(el, on) {
    if (el) {
      el.hidden = !on;
    }
  }

  function post(action, payload) {
    var body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", cfg.nonce || "");
    Object.keys(payload || {}).forEach(function (key) {
      if (payload[key] !== undefined && payload[key] !== null) {
        body.set(key, String(payload[key]));
      }
    });
    return fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || strings.loadError || "Error");
        }
        return json.data || {};
      });
    });
  }

  function statusLabel(status) {
    if (status === "approved") {
      return strings.approved || "Approved";
    }
    if (status === "rejected") {
      return strings.rejected || "Rejected";
    }
    return strings.pending || "Pending review";
  }

  function renderDays(data) {
    var host = $('[data-role="days"]');
    if (!host) {
      return;
    }
    host.innerHTML = "";
    text(
      $('[data-role="intro"]'),
      (data.name ? data.name + " · " : "") +
        (data.lookback || 21) +
        " days"
    );

    (data.days || []).forEach(function (day) {
      var card = document.createElement("article");
      card.className = "css-tc-times__day";

      var head = document.createElement("div");
      head.className = "css-tc-times__day-head";
      var titles = document.createElement("div");
      var h2 = document.createElement("h2");
      h2.textContent = day.date_label || day.date;
      if (day.is_today) {
        h2.textContent += " · " + (strings.today || "Today");
      }
      var weekday = document.createElement("p");
      weekday.className = "css-tc-times__weekday";
      weekday.textContent = day.weekday || "";
      titles.appendChild(h2);
      titles.appendChild(weekday);
      head.appendChild(titles);

      if (day.suggestion) {
        var pill = document.createElement("span");
        pill.className = "css-tc-times__pill css-tc-times__pill--" + day.suggestion.status;
        pill.textContent = statusLabel(day.suggestion.status);
        head.appendChild(pill);
      }
      card.appendChild(head);

      var shifts = day.shifts || [];
      if (!shifts.length) {
        var empty = document.createElement("p");
        empty.className = "css-tc-times__empty";
        empty.textContent = strings.noShifts || "No punches this day.";
        card.appendChild(empty);
      } else {
        shifts.forEach(function (shift) {
          var row = document.createElement("div");
          row.className = "css-tc-times__shift";
          var inSpan = document.createElement("span");
          inSpan.textContent = (strings.clockIn || "Clock in") + ": " + (shift.clock_in || "—");
          row.appendChild(inSpan);
          var outSpan = document.createElement("span");
          outSpan.textContent = shift.is_open
            ? strings.openShift || "Still clocked in"
            : (strings.clockOut || "Clock out") + ": " + (shift.clock_out || "—");
          row.appendChild(outSpan);
          if (shift.time_total) {
            var tot = document.createElement("span");
            tot.textContent = (strings.shiftTotal || "Shift time") + " " + shift.time_total;
            row.appendChild(tot);
          }
          card.appendChild(row);
        });
      }

      if (day.suggestion) {
        var box = document.createElement("div");
        box.className = "css-tc-times__suggestion";
        var bits = [];
        if (day.suggestion.proposed_in) {
          bits.push((strings.clockIn || "In") + " " + day.suggestion.proposed_in);
        }
        if (day.suggestion.proposed_out) {
          bits.push((strings.clockOut || "Out") + " " + day.suggestion.proposed_out);
        }
        if (day.suggestion.missing_punch) {
          bits.push(strings.missing || "Missing punch");
        }
        var line = document.createElement("p");
        line.textContent = bits.join(" · ");
        box.appendChild(line);
        if (day.suggestion.reason) {
          var reason = document.createElement("p");
          reason.textContent = (strings.reasonLabel || "Reason") + ": " + day.suggestion.reason;
          box.appendChild(reason);
        }
        if (day.suggestion.review_note) {
          var note = document.createElement("p");
          note.textContent = (strings.reviewNote || "Supervisor note") + ": " + day.suggestion.review_note;
          box.appendChild(note);
        }
        card.appendChild(box);
      }

      var canSuggest = !day.suggestion || day.suggestion.status !== "pending";
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "css-tc-times__button css-tc-times__suggest";
      btn.textContent = day.suggestion && day.suggestion.status === "pending"
        ? strings.updatePending || "Update pending suggestion"
        : strings.suggest || "Suggest edit";
      btn.setAttribute("data-action", "suggest");
      btn.setAttribute("data-date", day.date);
      btn.setAttribute("data-label", day.date_label || day.date);
      if (shifts[0]) {
        btn.setAttribute("data-shift", String(shifts[0].id));
        btn.setAttribute("data-in", shifts[0].clock_in_hm || "");
        btn.setAttribute("data-out", shifts[0].clock_out_hm || "");
        btn.setAttribute("data-next", shifts[0].out_next_day ? "1" : "");
      } else {
        btn.setAttribute("data-shift", "0");
        btn.setAttribute("data-missing", "1");
      }
      if (day.suggestion && day.suggestion.status === "pending") {
        btn.setAttribute("data-shift", String(day.suggestion.shift_id || 0));
        btn.setAttribute("data-in", day.suggestion.proposed_in_hm || "");
        btn.setAttribute("data-out", day.suggestion.proposed_out_hm || "");
        btn.setAttribute("data-next", day.suggestion.out_next_day ? "1" : "");
        btn.setAttribute("data-reason", day.suggestion.reason || "");
        btn.setAttribute("data-missing", day.suggestion.missing_punch ? "1" : "");
      }
      if (!canSuggest) {
        btn.textContent = strings.updatePending || "Update pending suggestion";
      }
      card.appendChild(btn);
      host.appendChild(card);
    });
  }

  function openModal(button) {
    var modal = $('[data-role="modal"]');
    var form = $('[data-role="suggest-form"]');
    if (!modal || !form) {
      return;
    }
    form.work_date.value = button.getAttribute("data-date") || "";
    form.shift_id.value = button.getAttribute("data-shift") || "0";
    form.proposed_in.value = button.getAttribute("data-in") || "";
    form.proposed_out.value = button.getAttribute("data-out") || "";
    form.out_next_day.checked = button.getAttribute("data-next") === "1";
    form.missing_punch.checked = button.getAttribute("data-missing") === "1";
    form.reason.value = button.getAttribute("data-reason") || "";
    text($('[data-role="modal-day"]'), button.getAttribute("data-label") || "");
    show($('[data-role="form-error"]'), false);
    show(modal, true);
    form.reason.focus();
  }

  function closeModal() {
    show($('[data-role="modal"]'), false);
  }

  function load() {
    post("css_tc_my_times", {})
      .then(function (data) {
        renderDays(data);
        show($('[data-role="error"]'), false);
      })
      .catch(function (err) {
        text($('[data-role="error"]'), err.message || strings.loadError);
        show($('[data-role="error"]'), true);
      });
  }

  root.addEventListener("click", function (event) {
    var button = event.target.closest("button");
    if (!button || !root.contains(button)) {
      return;
    }
    if (button.getAttribute("data-action") === "suggest") {
      openModal(button);
    }
    if (button.getAttribute("data-action") === "close-modal") {
      closeModal();
    }
  });

  var form = $('[data-role="suggest-form"]');
  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var reason = form.reason ? (form.reason.value || "").trim() : "";
      post("css_tc_suggest_edit", {
        work_date: form.work_date.value,
        shift_id: form.shift_id.value,
        proposed_in: form.proposed_in.value,
        proposed_out: form.proposed_out.value,
        out_next_day: form.out_next_day.checked ? 1 : 0,
        missing_punch: form.missing_punch.checked ? 1 : 0,
        reason: reason,
      })
        .then(function (data) {
          closeModal();
          if (data.dashboard) {
            renderDays(data.dashboard);
          }
          text($('[data-role="notice"]'), data.message || strings.sent);
          show($('[data-role="notice"]'), true);
        })
        .catch(function (err) {
          text($('[data-role="form-error"]'), err.message);
          show($('[data-role="form-error"]'), true);
        });
    });
  }

  load();
})();
