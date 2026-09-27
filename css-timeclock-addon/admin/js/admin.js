(function () {
  "use strict";

  var cfg = window.cssTcAdmin || {};

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

  function post(action, payload) {
    var body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", cfg.nonce || "");
    Object.keys(payload || {}).forEach(function (key) {
      body.set(key, String(payload[key]));
    });

    return fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || (cfg.strings && cfg.strings.error) || "Error");
        }
        return json.data || {};
      });
    });
  }

  function bindSettings() {
    var form = document.querySelector(".css-tc-settings-form");
    if (!form) {
      return;
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var data = {
        pin_kiosk_enabled: form.pin_kiosk_enabled && form.pin_kiosk_enabled.checked ? 1 : 0,
        name_kiosk_enabled: form.name_kiosk_enabled && form.name_kiosk_enabled.checked ? 1 : 0,
        pin_min_length: form.pin_min_length.value,
        pin_max_length: form.pin_max_length.value,
        rate_limit_max: form.rate_limit_max.value,
        rate_limit_window: form.rate_limit_window.value,
        ip_allowlist_enabled: form.ip_allowlist_enabled && form.ip_allowlist_enabled.checked ? 1 : 0,
        ip_allowlist: form.ip_allowlist ? form.ip_allowlist.value : "",
        idle_reset_ms: form.idle_reset_ms.value,
        times_lookback_days: form.times_lookback_days ? form.times_lookback_days.value : 21,
        pay_period_length: form.pay_period_length ? form.pay_period_length.value : "biweekly",
        pay_period_anchor: form.pay_period_anchor ? form.pay_period_anchor.value : "2026-09-07",
        missed_clock_out_hours: form.missed_clock_out_hours ? form.missed_clock_out_hours.value : 16,
        long_shift_hours: form.long_shift_hours ? form.long_shift_hours.value : 16,
        wide_layout: form.wide_layout && form.wide_layout.checked ? 1 : 0,
      };
      post("css_tc_save_settings", data)
        .then(function (result) {
          notice(result.message || (cfg.strings && cfg.strings.saved));
        })
        .catch(function (err) {
          notice(err.message, true);
        });
    });

    var createBtn = document.querySelector(".css-tc-create-pages");
    if (createBtn) {
      createBtn.addEventListener("click", function () {
        post("css_tc_create_pages", {})
          .then(function (result) {
            notice(result.message || (cfg.strings && cfg.strings.saved));
          })
          .catch(function (err) {
            notice(err.message, true);
          });
      });
    }
  }

  function updateStatus(row, hasPin) {
    var cell = row.querySelector(".css-tc-pin-status");
    var clearBtn = row.querySelector(".css-tc-clear-pin");
    if (cell) {
      cell.innerHTML = hasPin
        ? '<span class="css-tc-pill css-tc-pill-set">' + ((cfg.strings && cfg.strings.set) || "Set") + "</span>"
        : '<span class="css-tc-pill css-tc-pill-unset">' + ((cfg.strings && cfg.strings.notSet) || "Not set") + "</span>";
    }
    if (clearBtn) {
      clearBtn.disabled = !hasPin;
    }
  }

  function setPinRevealed(input, toggle, revealed) {
    if (!input || !toggle) {
      return;
    }
    var strings = cfg.strings || {};
    input.type = revealed ? "text" : "password";
    toggle.setAttribute("aria-pressed", revealed ? "true" : "false");
    toggle.setAttribute("aria-label", revealed ? strings.hidePin || "Hide PIN" : strings.showPin || "Show PIN");
  }

  function bindPins() {
    document.querySelectorAll(".css-tc-pin-form").forEach(function (form) {
      var input = form.querySelector(".css-tc-pin-input");
      var toggle = form.querySelector(".css-tc-pin-toggle");

      if (input && toggle) {
        toggle.addEventListener("mousedown", function (event) {
          event.preventDefault();
        });
        toggle.addEventListener("click", function () {
          setPinRevealed(input, toggle, input.type === "password");
        });
      }

      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var pin = input ? input.value : "";
        post("css_tc_save_pin", { user_id: form.getAttribute("data-user-id"), pin: pin })
          .then(function (result) {
            if (input) {
              input.value = "";
              setPinRevealed(input, toggle, false);
            }
            updateStatus(form.closest("tr"), true);
            notice(result.message || (cfg.strings && cfg.strings.saved));
          })
          .catch(function (err) {
            notice(err.message, true);
          });
      });

      var clearBtn = form.querySelector(".css-tc-clear-pin");
      if (clearBtn) {
        clearBtn.addEventListener("click", function () {
          if (!window.confirm((cfg.strings && cfg.strings.confirmClear) || "Clear this PIN?")) {
            return;
          }
          post("css_tc_clear_pin", { user_id: form.getAttribute("data-user-id") })
            .then(function (result) {
              updateStatus(form.closest("tr"), false);
              notice(result.message || (cfg.strings && cfg.strings.saved));
            })
            .catch(function (err) {
              notice(err.message, true);
            });
        });
      }
    });

    var filter = document.getElementById("css-tc-pin-filter");
    if (filter) {
      filter.addEventListener("input", function () {
        var q = filter.value.toLowerCase();
        document.querySelectorAll(".css-tc-pin-row").forEach(function (row) {
          var name = row.getAttribute("data-name") || "";
          row.hidden = q !== "" && name.indexOf(q) === -1;
        });
      });
    }
  }

  function bindCorrections() {
    var root = document.querySelector(".css-tc-admin");
    if (!root) {
      return;
    }

    root.addEventListener("click", function (event) {
      var button = event.target.closest("[data-decision]");
      if (!button) {
        return;
      }
      var form = button.closest(".css-tc-review-form");
      var card = button.closest(".css-tc-correction");
      if (!form || !card) {
        return;
      }
      var decision = button.getAttribute("data-decision");
      if (decision === "reject" && !window.confirm((cfg.strings && cfg.strings.confirmReject) || "Reject?")) {
        return;
      }
      var note = form.querySelector("[name='review_note']");
      post("css_tc_review_correction", {
        correction_id: card.getAttribute("data-correction-id"),
        decision: decision,
        review_note: note ? note.value : "",
      })
        .then(function (result) {
          notice(result.message || (cfg.strings && cfg.strings.saved));
          if (result.queue) {
            window.location.reload();
          }
        })
        .catch(function (err) {
          notice(err.message, true);
        });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    bindSettings();
    bindPins();
    bindCorrections();
  });
})();
