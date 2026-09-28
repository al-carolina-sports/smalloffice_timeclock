(function () {
  "use strict";

  var cfg = window.cssTcTimeoff || {};
  var S = cfg.strings || {};

  function $(root, sel) {
    return root.querySelector(sel);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) {
      n.className = cls;
    }
    if (text !== undefined && text !== null) {
      n.textContent = text;
    }
    return n;
  }

  function fmt(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(str || "")
      .replace(/%(\d)\$[sd]/g, function (_, n) {
        return args[parseInt(n, 10) - 1];
      })
      .replace(/%[sd]/g, function () {
        return args[i++];
      });
  }

  function hours(seconds) {
    var h = Math.round((seconds / 3600) * 100) / 100;
    return h + " h";
  }

  function pad(n) {
    return n < 10 ? "0" + n : String(n);
  }

  function ymd(d) {
    return d.getUTCFullYear() + "-" + pad(d.getUTCMonth() + 1) + "-" + pad(d.getUTCDate());
  }

  function parse(s) {
    var p = String(s).split("-");
    return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
  }

  function niceDate(s) {
    try {
      return parse(s).toLocaleDateString(cfg.locale || undefined, { weekday: "short", month: "short", day: "numeric", timeZone: "UTC" });
    } catch (e) {
      return s;
    }
  }

  function post(action, payload) {
    var body = new URLSearchParams();
    body.set("action", action);
    body.set("nonce", cfg.nonce || "");
    Object.keys(payload || {}).forEach(function (k) {
      body.set(k, String(payload[k]));
    });
    return fetch(cfg.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
      body: body.toString(),
    }).then(function (res) {
      return res.json().then(function (json) {
        if (!json || !json.success) {
          throw new Error((json && json.data && json.data.message) || S.network || "Request failed.");
        }
        return json.data || {};
      });
    });
  }

  function TimeOff(root) {
    this.root = root;
    this.state = null;
    this.type = "";
    this.selected = {};
    this.month = null;
    this.busy = false;
    this.bind();
    this.load();
  }

  TimeOff.prototype.bind = function () {
    var self = this;
    this.root.addEventListener("click", function (e) {
      var b = e.target.closest("button");
      if (!b || !self.root.contains(b)) {
        return;
      }
      var a = b.getAttribute("data-action");
      if (a === "prev" || a === "next") {
        self.shiftMonth(a === "prev" ? -1 : 1);
      } else if (a === "send") {
        self.send();
      } else if (b.hasAttribute("data-date")) {
        self.toggle(b.getAttribute("data-date"));
      } else if (b.hasAttribute("data-cancel")) {
        self.cancel(b);
      }
    });
    this.root.addEventListener("change", function (e) {
      if (e.target.name === "css_tc_to_type") {
        self.type = e.target.value;
        // Drop picks the new type does not allow (e.g. PTO inside the notice window).
        Object.keys(self.selected).forEach(function (d) {
          if (self.blocked(d)) {
            delete self.selected[d];
          }
        });
        self.renderCal();
        self.renderPicked();
      } else if (e.target.getAttribute("data-role") === "hours") {
        self.renderPicked();
      }
    });
  };

  TimeOff.prototype.load = function () {
    var self = this;
    post("css_tc_leave_state", {})
      .then(function (s) {
        self.apply(s);
      })
      .catch(function (err) {
        self.msg(err.message, true);
      });
  };

  TimeOff.prototype.apply = function (s) {
    this.state = s;
    if (!this.type && s.types && s.types.length) {
      this.type = s.types[0].type;
    }
    if (!this.month) {
      var start = s.today > (s.usable_from || "") ? s.today : s.usable_from || s.today;
      var d = parse(start);
      this.month = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1));
    }
    this.renderBalances();
    this.renderTypes();
    this.renderHours();
    this.renderCal();
    this.renderPicked();
    this.renderMine();
    $(this.root, '[data-role="layout"]').hidden = !s.hire;
  };

  TimeOff.prototype.msg = function (text, isError) {
    var m = $(this.root, '[data-role="msg"]');
    m.textContent = text || "";
    m.hidden = !text;
    m.classList.toggle("is-error", !!isError);
  };

  TimeOff.prototype.renderBalances = function () {
    var s = this.state;
    var box = $(this.root, '[data-role="balances"]');
    var lead = $(this.root, '[data-role="lead"]');
    box.innerHTML = "";
    if (!s.hire) {
      lead.textContent = S.noHire || "";
      return;
    }
    var bits = [];
    if (s.cycle_label) {
      bits.push((S.leaveYear || "Leave year") + " " + s.cycle_label + ".");
    }
    if (s.usable_from && s.usable_from > s.today) {
      bits.push(fmt(S.usableFrom, niceDate(s.usable_from)));
    }
    if (s.one_bank) {
      bits.push(S.oneBank || "");
    }
    lead.textContent = bits.join(" ");
    var day = s.day_seconds || 28800;
    (s.banks || []).forEach(function (b) {
      var card = el("div", "css-tc-to__bank css-tc-to__bank--" + b.bank);
      card.appendChild(el("strong", "css-tc-to__bankname", b.label));
      [
        [S.total, b.allowance],
        [S.used, b.used],
        [S.pending, b.pending],
        [S.remaining, b.left],
      ].forEach(function (pair, i) {
        var stat = el("span", "css-tc-to__stat" + (i === 3 ? " is-left" : ""));
        stat.appendChild(el("span", "", pair[0]));
        stat.appendChild(el("b", "", hours(pair[1])));
        if (i === 3) {
          stat.appendChild(el("em", "", Math.round((pair[1] / day) * 100) / 100 + " " + (S.days || "days")));
        }
        card.appendChild(stat);
      });
      box.appendChild(card);
    });
  };

  TimeOff.prototype.renderTypes = function () {
    var box = $(this.root, '[data-role="types"]');
    var self = this;
    box.querySelectorAll("label").forEach(function (n) {
      n.remove();
    });
    (this.state.types || []).forEach(function (t) {
      var lab = el("label", "css-tc-to__type css-tc-to__type--" + t.type);
      var input = document.createElement("input");
      input.type = "radio";
      input.name = "css_tc_to_type";
      input.value = t.type;
      input.checked = t.type === self.type;
      lab.appendChild(input);
      lab.appendChild(document.createTextNode(" " + t.label));
      box.appendChild(lab);
    });
  };

  TimeOff.prototype.renderHours = function () {
    var sel = $(this.root, '[data-role="hours"]');
    var keep = sel.value;
    var day = this.state.day_seconds || 28800;
    var step = this.state.step_seconds || 3600;
    sel.innerHTML = "";
    for (var s = day; s >= step; s -= step) {
      var o = document.createElement("option");
      o.value = String(s);
      o.textContent = s === day ? (S.fullDay || "Full day") + " (" + hours(s) + ")" : hours(s) + " " + (S.perDay || "");
      sel.appendChild(o);
    }
    if (keep && sel.querySelector('option[value="' + keep + '"]')) {
      sel.value = keep;
    }
  };

  // Why a date cannot be picked for the current type, or "" when it can.
  TimeOff.prototype.blocked = function (date) {
    var s = this.state;
    if (!s || !s.hire || date < s.hire || (s.usable_from && date < s.usable_from)) {
      return S.notYet || "Not available";
    }
    if (s.holidays && s.holidays[date]) {
      return (S.holiday || "Holiday") + ": " + s.holidays[date];
    }
    var st = s.status || {};
    if (st.status === "leave" && (!st.leave_from || date >= st.leave_from) && (!st.leave_to || date <= st.leave_to)) {
      return S.onLeave || "On leave";
    }
    if (st.status === "inactive" && st.last_day && date > st.last_day) {
      return S.inactive || "After your last day";
    }
    if (s.days && s.days[date]) {
      var d = s.days[date];
      return d.type.toUpperCase() + " " + d.hours + " " + (d.status === "pending" ? S.pendingDay : S.approvedDay);
    }
    if (this.type === "pto" && date < s.notice_from) {
      return fmt(S.notice, s.notice_days);
    }
    if (s.period_start && date < s.period_start) {
      return S.closed || "Closed";
    }
    return "";
  };

  TimeOff.prototype.shiftMonth = function (delta) {
    var m = new Date(Date.UTC(this.month.getUTCFullYear(), this.month.getUTCMonth() + delta, 1));
    var s = this.state;
    var min = parse(s.period_start || s.today);
    var max = parse(s.today);
    max = new Date(Date.UTC(max.getUTCFullYear() + 1, max.getUTCMonth(), 1));
    if (m < new Date(Date.UTC(min.getUTCFullYear(), min.getUTCMonth(), 1)) || m > max) {
      return;
    }
    this.month = m;
    this.renderCal();
  };

  TimeOff.prototype.renderCal = function () {
    var s = this.state;
    var grid = $(this.root, '[data-role="grid"]');
    var title = $(this.root, '[data-role="month"]');
    grid.innerHTML = "";
    try {
      title.textContent = this.month.toLocaleDateString(cfg.locale || undefined, { month: "long", year: "numeric", timeZone: "UTC" });
    } catch (e) {
      title.textContent = ymd(this.month).slice(0, 7);
    }
    var monday = parse("2024-01-01");
    for (var i = 0; i < 7; i++) {
      var dow = new Date(monday.getTime() + i * 86400000);
      var h = el("div", "css-tc-to__dow");
      try {
        h.textContent = dow.toLocaleDateString(cfg.locale || undefined, { weekday: "short", timeZone: "UTC" });
      } catch (e) {
        h.textContent = "";
      }
      grid.appendChild(h);
    }
    var first = this.month;
    var lead = (first.getUTCDay() + 6) % 7;
    for (var b = 0; b < lead; b++) {
      grid.appendChild(el("div", "css-tc-to__blank"));
    }
    var y = first.getUTCFullYear();
    var mo = first.getUTCMonth();
    var days = new Date(Date.UTC(y, mo + 1, 0)).getUTCDate();
    for (var d = 1; d <= days; d++) {
      var date = y + "-" + pad(mo + 1) + "-" + pad(d);
      var why = this.blocked(date);
      var btn = el("button", "css-tc-to__day");
      btn.type = "button";
      var wk = new Date(Date.UTC(y, mo, d)).getUTCDay();
      if (wk === 0 || wk === 6) {
        btn.classList.add("is-weekend");
      }
      if (date === s.today) {
        btn.classList.add("is-today");
      }
      btn.appendChild(el("span", "css-tc-to__num", String(d)));
      if (s.holidays && s.holidays[date]) {
        btn.classList.add("is-holiday");
        btn.appendChild(el("span", "css-tc-to__tag", s.holidays[date]));
      } else if (s.days && s.days[date]) {
        btn.classList.add("is-" + s.days[date].type, "is-" + s.days[date].status);
        btn.appendChild(el("span", "css-tc-to__tag", s.days[date].type.toUpperCase() + " " + s.days[date].hours));
      }
      if (why) {
        btn.disabled = true;
        btn.title = why;
        btn.setAttribute("aria-label", niceDate(date) + " — " + why);
      } else {
        btn.setAttribute("data-date", date);
        btn.setAttribute("aria-pressed", this.selected[date] ? "true" : "false");
        btn.setAttribute("aria-label", niceDate(date));
        if (this.selected[date]) {
          btn.classList.add("is-selected");
        }
      }
      grid.appendChild(btn);
    }
  };

  TimeOff.prototype.toggle = function (date) {
    if (this.selected[date]) {
      delete this.selected[date];
    } else {
      this.selected[date] = true;
    }
    this.renderCal();
    this.renderPicked();
  };

  TimeOff.prototype.renderPicked = function (keepError) {
    var dates = Object.keys(this.selected).sort();
    var sec = parseInt($(this.root, '[data-role="hours"]').value || "0", 10);
    var p = $(this.root, '[data-role="picked"]');
    if (!dates.length) {
      p.textContent = S.pick || "";
    } else {
      p.textContent = fmt(S.picked, dates.length, hours(sec * dates.length)) + ": " + dates.map(niceDate).join(", ");
    }
    $(this.root, '[data-action="send"]').disabled = !dates.length || !this.type || this.busy;
    if (!keepError) {
      $(this.root, '[data-role="error"]').hidden = true;
    }
  };

  TimeOff.prototype.send = function () {
    var self = this;
    var dates = Object.keys(this.selected).sort();
    if (!dates.length || this.busy) {
      return;
    }
    var btn = $(this.root, '[data-action="send"]');
    var err = $(this.root, '[data-role="error"]');
    this.busy = true;
    btn.disabled = true;
    btn.textContent = S.sending || "Sending…";
    post("css_tc_leave_request", {
      type: this.type,
      dates: dates.join(","),
      seconds: $(this.root, '[data-role="hours"]').value,
      note: $(this.root, '[data-role="note"]').value,
    })
      .then(function (data) {
        self.selected = {};
        $(self.root, '[data-role="note"]').value = "";
        self.msg(data.message, false);
        self.apply(data.state);
      })
      .catch(function (e) {
        err.textContent = e.message;
        err.hidden = false;
      })
      .then(function () {
        self.busy = false;
        btn.textContent = S.send || "Send request";
        self.renderPicked(true);
      });
  };

  TimeOff.prototype.renderMine = function () {
    var box = $(this.root, '[data-role="mine"]');
    var list = this.state.requests || [];
    box.innerHTML = "";
    if (!list.length) {
      box.appendChild(el("p", "css-tc-to__empty", S.none || ""));
      return;
    }
    var ul = el("ul", "css-tc-to__list");
    list.forEach(function (r) {
      var li = el("li", "css-tc-to__req is-" + r.status);
      var head = el("div", "css-tc-to__reqhead");
      head.appendChild(el("span", "css-tc-to__pill css-tc-to__pill--" + r.type, r.label));
      head.appendChild(el("strong", "", r.when));
      head.appendChild(el("span", "css-tc-to__hrs", r.hours));
      head.appendChild(el("span", "css-tc-to__status", (S.status && S.status[r.status]) || r.status));
      li.appendChild(head);
      var extra = [];
      if (r.source === "manager") {
        extra.push(S.addedByManager || "");
      }
      if (r.note) {
        extra.push("“" + r.note + "”");
      }
      if (r.reason) {
        extra.push((S.reason || "Reason:") + " " + r.reason);
      }
      if (extra.length) {
        li.appendChild(el("p", "css-tc-to__reqnote", extra.join(" · ")));
      }
      if (r.can_cancel) {
        var c = el("button", "css-tc-to__cancel", S.cancel || "Cancel");
        c.type = "button";
        c.setAttribute("data-cancel", r.group);
        li.appendChild(c);
      }
      ul.appendChild(li);
    });
    box.appendChild(ul);
  };

  // Two taps to cancel, instead of a browser confirm dialog.
  TimeOff.prototype.cancel = function (btn) {
    var self = this;
    if (btn.getAttribute("data-armed") !== "1") {
      btn.setAttribute("data-armed", "1");
      btn.textContent = S.cancelSure || "Tap again";
      window.setTimeout(function () {
        if (btn.isConnected) {
          btn.removeAttribute("data-armed");
          btn.textContent = S.cancel || "Cancel";
        }
      }, 4000);
      return;
    }
    btn.disabled = true;
    post("css_tc_leave_cancel", { group: btn.getAttribute("data-cancel") })
      .then(function (data) {
        self.msg(data.message, false);
        self.apply(data.state);
      })
      .catch(function (e) {
        self.msg(e.message, true);
        btn.disabled = false;
      });
  };

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("[data-css-tc-timeoff]").forEach(function (root) {
      new TimeOff(root);
    });
  });
})();
