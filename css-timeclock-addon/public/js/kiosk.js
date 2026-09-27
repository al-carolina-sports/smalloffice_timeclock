(function () {
  "use strict";

  var cfg = window.cssTcKiosk || {};
  var strings = cfg.strings || {};

  function $(root, sel) {
    return root.querySelector(sel);
  }

  function show(el, on) {
    if (!el) {
      return;
    }
    el.hidden = !on;
  }

  function text(el, value) {
    if (el) {
      el.textContent = value || "";
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
          var message =
            (json && json.data && json.data.message) || strings.network || "Request failed.";
          var err = new Error(message);
          err.status = res.status;
          throw err;
        }
        return json.data || {};
      });
    });
  }

  function liveClock(el) {
    if (!el) {
      return;
    }
    function tick() {
      el.textContent = new Date().toLocaleString();
    }
    tick();
    window.setInterval(tick, 1000);
  }

  function renderDots(el, length) {
    if (!el) {
      return;
    }
    el.textContent = length ? new Array(length + 1).join("•") : "○";
  }

  // Input types that are not free-text. Digits must still reach the PIN pad
  // when one of these controls (or a pad button) has focus.
  var NON_TEXT_INPUT = {
    button: true,
    submit: true,
    reset: true,
    checkbox: true,
    radio: true,
    file: true,
    hidden: true,
    image: true,
    range: true,
    color: true,
  };

  function isTextEntryTarget(el) {
    var node = el;
    while (node && node !== document.body && node !== document.documentElement) {
      if (node.isContentEditable) {
        return true;
      }
      var tag = node.tagName ? node.tagName.toLowerCase() : "";
      if (tag === "textarea" || tag === "select") {
        return true;
      }
      if (tag === "input") {
        var type = (node.getAttribute("type") || "text").toLowerCase();
        return !NON_TEXT_INPUT[type];
      }
      node = node.parentElement;
    }
    return false;
  }

  // NumLock-off numpad keys report these names and must not fill the PIN.
  var NUMPAD_NAV_KEYS = {
    Insert: true,
    End: true,
    ArrowDown: true,
    PageDown: true,
    ArrowLeft: true,
    Clear: true,
    ArrowRight: true,
    Home: true,
    ArrowUp: true,
    PageUp: true,
    Delete: true,
  };

  function digitFromKeyEvent(event) {
    if (event.ctrlKey || event.metaKey || event.altKey) {
      return "";
    }
    var key = event.key || "";
    if (key.length === 1 && key >= "0" && key <= "9") {
      return key;
    }
    var code = event.code || "";
    // Shifted top-row digits (key "!" and so on) still enter that digit.
    if (code.length === 6 && code.indexOf("Digit") === 0) {
      var fromDigit = code.charAt(5);
      if (fromDigit >= "0" && fromDigit <= "9") {
        return fromDigit;
      }
    }
    if (code.length === 7 && code.indexOf("Numpad") === 0 && !NUMPAD_NAV_KEYS[key]) {
      var fromPad = code.charAt(6);
      if (fromPad >= "0" && fromPad <= "9") {
        return fromPad;
      }
    }
    return "";
  }

  function isEnterKey(event) {
    return event.key === "Enter" || event.code === "Enter" || event.code === "NumpadEnter";
  }

  function isEraseKey(event) {
    return (
      event.key === "Backspace" ||
      event.key === "Delete" ||
      event.code === "Backspace" ||
      event.code === "Delete"
    );
  }

  function fillRosterList(list, people, inClass) {
    if (!list) {
      return;
    }
    list.innerHTML = "";
    (people || []).forEach(function (person) {
      var li = document.createElement("li");
      li.className = "css-tc-board__person " + (inClass || "css-tc-board__person--out");
      var name = document.createElement("span");
      name.className = "css-tc-board__name";
      name.textContent = person.name || "";
      li.appendChild(name);
      if (person.clock_in_time) {
        var time = document.createElement("span");
        time.className = "css-tc-board__time";
        time.textContent = person.clock_in_time;
        li.appendChild(time);
      }
      list.appendChild(li);
    });
  }

  function renderBoard(root, data) {
    var working = data.working || [];
    var out = data.out || [];
    var workingCount = $(root, '[data-role="working-count"]');
    var outCount = $(root, '[data-role="out-count"]');
    var workingEmpty = $(root, '[data-role="working-empty"]');
    var outEmpty = $(root, '[data-role="out-empty"]');
    var updated = $(root, '[data-role="board-updated"]');
    var error = $(root, '[data-role="board-error"]');

    fillRosterList($(root, '[data-role="working-list"]'), working, "css-tc-board__person--in");
    fillRosterList($(root, '[data-role="out-list"]'), out, "css-tc-board__person--out");
    text(workingCount, String(data.working_count != null ? data.working_count : working.length));
    text(outCount, String(data.out_count != null ? data.out_count : out.length));
    show(workingEmpty, working.length === 0);
    show(outEmpty, out.length === 0);
    show(error, false);
    if (data.generated_at) {
      text(updated, (strings.updatedAt || "Updated") + " " + data.generated_at);
    }
  }

  function StatusBoard(root) {
    this.root = root;
    this.loading = false;
    this.queued = false;
    this.timer = null;
    if (!$(root, '[data-role="board"]')) {
      return;
    }
    this.refresh();
    var interval = cfg.boardRefreshMs || 20000;
    if (interval < 15000) {
      interval = 15000;
    }
    if (interval > 30000) {
      interval = 30000;
    }
    this.timer = window.setInterval(this.refresh.bind(this), interval);
  }

  StatusBoard.prototype.apply = function (data) {
    if (!$(this.root, '[data-role="board"]')) {
      return;
    }
    renderBoard(this.root, data || {});
  };

  StatusBoard.prototype.refresh = function () {
    var self = this;
    if (!$(this.root, '[data-role="board"]')) {
      return;
    }
    if (this.loading) {
      this.queued = true;
      return;
    }
    this.loading = true;
    post("css_tc_roster", { kiosk: this.root.getAttribute("data-kiosk") || "pin" })
      .then(function (data) {
        self.loading = false;
        self.apply(data || {});
        if (self.queued) {
          self.queued = false;
          self.refresh();
        }
      })
      .catch(function (err) {
        self.loading = false;
        var error = $(self.root, '[data-role="board-error"]');
        if (err && err.status === 429) {
          return;
        }
        text(error, (err && err.message) || strings.boardError || strings.network);
        show(error, true);
        if (self.queued) {
          self.queued = false;
        }
      });
  };

  function Kiosk(root, board) {
    this.root = root;
    this.board = board || null;
    this.mode = root.getAttribute("data-kiosk") || "pin";
    this.pin = "";
    this.userId = 0;
    this.busy = false;
    this.resetTimer = null;
    this.selectedName = "";

    this.stage = $(root, '[data-role="stage"]');
    this.screens = {
      list: $(root, '[data-screen="list"]'),
      pin: $(root, '[data-screen="pin"]'),
      action: $(root, '[data-screen="action"]'),
      success: $(root, '[data-screen="success"]'),
    };

    liveClock($(root, '[data-role="live-clock"]'));
    this.bind();
    this.reset();
    if (this.mode === "name") {
      this.loadEmployees();
    }
  }

  Kiosk.prototype.bind = function () {
    var self = this;

    this.root.addEventListener("click", function (event) {
      var button = event.target.closest("button");
      if (!button || !self.root.contains(button) || self.busy) {
        return;
      }

      var digit = button.getAttribute("data-digit");
      var action = button.getAttribute("data-action");
      var employee = button.getAttribute("data-employee");

      if (digit) {
        self.addDigit(digit);
      } else if (action === "clear") {
        self.clearPin();
      } else if (action === "back") {
        self.removeLastDigit();
      } else if (action === "submit-pin") {
        self.resolvePin();
      } else if (action === "cancel") {
        self.reset();
      } else if (action === "clock_in" || action === "clock_out") {
        self.punch(action);
      } else if (employee) {
        self.chooseEmployee(parseInt(employee, 10), button.getAttribute("data-name") || "");
      }
    });

    var search = $(this.root, '[data-role="search"]');
    if (search) {
      search.addEventListener("input", function () {
        self.filterNames(search.value);
      });
    }

    // Capture on document so a USB keyboard or numpad works when focus is on
    // the page body, not only after a pad button has been clicked.
    document.addEventListener(
      "keydown",
      function (event) {
        self.onKeyDown(event);
      },
      true
    );
  };

  Kiosk.prototype.ownsKeyEvent = function (event) {
    if (!this.root || this.root.getAttribute("data-enabled") !== "1") {
      return false;
    }
    var target = event.target;
    if (target && typeof target.closest === "function") {
      var host = target.closest(".css-tc-kiosk");
      if (host) {
        return host === this.root;
      }
    }
    var enabled = document.querySelectorAll('.css-tc-kiosk[data-enabled="1"]');
    return enabled.length === 1 && enabled[0] === this.root;
  };

  Kiosk.prototype.activeScreen = function () {
    var found = "";
    var self = this;
    ["list", "pin", "action", "success"].forEach(function (key) {
      var el = self.screens[key];
      if (el && !el.hidden) {
        found = key;
      }
    });
    return found;
  };

  // Escape on the PIN screen clears digits and stays on that screen (same as
  // Clear). It does not act as Cancel, including on the name kiosk, where the
  // on-screen Cancel button still returns to the name list.
  // Escape on the Clock in / Clock out screen calls the same reset as Cancel.
  // The success screen has no Cancel button; Escape uses that same reset so
  // the kiosk returns to idle immediately instead of waiting out the timer.
  Kiosk.prototype.handleEscape = function () {
    var screen = this.activeScreen();
    if (screen === "pin") {
      this.clearPin();
      return true;
    }
    if (screen === "action" || screen === "success") {
      this.reset();
      return true;
    }
    return false;
  };

  Kiosk.prototype.onKeyDown = function (event) {
    if (!event || event.defaultPrevented || !this.ownsKeyEvent(event)) {
      return;
    }
    if (isTextEntryTarget(event.target)) {
      return;
    }
    // Staff links live in the kiosk header. Leave Enter and digits alone while
    // one of them is focused so login still works and the pad is unchanged.
    if (event.target && event.target.closest && event.target.closest("[data-css-tc-staff-nav]")) {
      return;
    }
    if (this.busy) {
      return;
    }

    if ((event.key || "") === "Escape") {
      if (event.repeat) {
        return;
      }
      if (this.handleEscape()) {
        event.preventDefault();
      }
      return;
    }

    if (this.activeScreen() !== "pin") {
      return;
    }

    var digit = digitFromKeyEvent(event);
    if (digit) {
      event.preventDefault();
      this.addDigit(digit);
      return;
    }

    if (isEraseKey(event)) {
      event.preventDefault();
      this.removeLastDigit();
      return;
    }

    if (isEnterKey(event)) {
      if (event.repeat) {
        return;
      }
      event.preventDefault();
      this.resolvePin();
    }
  };

  Kiosk.prototype.showScreen = function (name) {
    Object.keys(this.screens).forEach(function (key) {
      show(this.screens[key], key === name);
    }, this);
  };

  Kiosk.prototype.updateDots = function () {
    renderDots($(this.root, '[data-role="pin-dots"]'), this.pin.length);
  };

  Kiosk.prototype.clearPin = function () {
    this.pin = "";
    this.updateDots();
  };

  Kiosk.prototype.removeLastDigit = function () {
    this.pin = this.pin.slice(0, -1);
    this.updateDots();
  };

  Kiosk.prototype.addDigit = function (digit) {
    var max = cfg.pinMax || 8;
    if (this.pin.length >= max) {
      return;
    }
    this.pin += digit;
    this.updateDots();
    text($(this.root, '[data-role="error"]'), "");
    show($(this.root, '[data-role="error"]'), false);
    if (this.pin.length >= (cfg.pinMax || 8)) {
      this.resolvePin();
    }
  };

  Kiosk.prototype.resolvePin = function () {
    var self = this;
    var min = cfg.pinMin || 4;
    if (this.busy) {
      return;
    }
    if (this.pin.length < min) {
      text($(this.root, '[data-role="error"]'), strings.enterPin || "Enter your PIN");
      show($(this.root, '[data-role="error"]'), true);
      return;
    }
    this.busy = true;
    var payload = { pin: this.pin, kiosk: this.mode };
    if (this.userId) {
      payload.user_id = this.userId;
    }

    post("css_tc_resolve_pin", payload)
      .then(function (data) {
        self.userId = data.user_id;
        self.busy = false;
        self.showAction(data);
      })
      .catch(function (err) {
        self.busy = false;
        self.pin = "";
        self.updateDots();
        text($(self.root, '[data-role="error"]'), err.message || strings.badPin);
        show($(self.root, '[data-role="error"]'), true);
      });
  };

  Kiosk.prototype.showAction = function (data) {
    this.showScreen("action");
    var name = data.name || this.selectedName;
    text($(this.root, '[data-role="hello"]'), (strings.hello || "Hello") + ", " + name);
    if (data.is_clocked_in && data.clock_in_time) {
      text(
        $(this.root, '[data-role="status"]'),
        (strings.workingSince || "Clocked in since") + " " + data.clock_in_time
      );
    } else {
      text($(this.root, '[data-role="status"]'), "");
    }
    show($(this.root, '[data-role="action-error"]'), false);

    var inBtn = $(this.root, '[data-action="clock_in"]');
    var outBtn = $(this.root, '[data-action="clock_out"]');
    if (inBtn) {
      inBtn.disabled = !!data.is_clocked_in;
    }
    if (outBtn) {
      outBtn.disabled = !data.is_clocked_in;
    }
  };

  Kiosk.prototype.punch = function (clockAction) {
    var self = this;
    if (this.busy) {
      return;
    }
    this.busy = true;
    var payload = {
      pin: this.pin,
      kiosk: this.mode,
      clock_action: clockAction,
    };
    if (this.userId) {
      payload.user_id = this.userId;
    }

    post("css_tc_punch", payload)
      .then(function (data) {
        self.busy = false;
        self.showSuccess(data);
        if (self.board && data.board && typeof self.board.apply === "function") {
          self.board.apply(data.board);
        } else if (self.board && typeof self.board.refresh === "function") {
          self.board.refresh();
        }
      })
      .catch(function (err) {
        self.busy = false;
        text($(self.root, '[data-role="action-error"]'), err.message || strings.network);
        show($(self.root, '[data-role="action-error"]'), true);
      });
  };

  Kiosk.prototype.showSuccess = function (data) {
    this.showScreen("success");
    var title =
      data.action === "clock_out" ? strings.successOut || "You are clocked out." : strings.successIn || "You are clocked in.";
    text($(this.root, '[data-role="success-title"]'), title);
    var detail = data.name || "";
    if (data.time_total) {
      detail += (detail ? " · " : "") + (strings.shiftTotal || "Shift time") + " " + data.time_total;
    } else if (data.clock_in_time) {
      detail += (detail ? " · " : "") + (strings.workingSince || "Clocked in since") + " " + data.clock_in_time;
    }
    text($(this.root, '[data-role="success-detail"]'), detail);
    this.scheduleReset();
  };

  Kiosk.prototype.scheduleReset = function () {
    var self = this;
    window.clearTimeout(this.resetTimer);
    this.resetTimer = window.setTimeout(function () {
      self.reset();
    }, cfg.idleResetMs || 8000);
  };

  Kiosk.prototype.reset = function () {
    window.clearTimeout(this.resetTimer);
    this.pin = "";
    this.userId = 0;
    this.busy = false;
    this.selectedName = "";
    this.updateDots();
    show($(this.root, '[data-role="error"]'), false);
    show($(this.root, '[data-role="action-error"]'), false);
    var search = $(this.root, '[data-role="search"]');
    if (search) {
      search.value = "";
      this.filterNames("");
    }
    if (this.mode === "name") {
      this.showScreen("list");
    } else {
      this.showScreen("pin");
    }
  };

  Kiosk.prototype.chooseEmployee = function (userId, name) {
    this.userId = userId;
    this.selectedName = name;
    this.pin = "";
    this.updateDots();
    text($(this.root, '[data-role="selected-name"]'), name);
    text($(this.root, '[data-role="pin-prompt"]'), strings.confirmPin || "Confirm with your PIN");
    show($(this.root, '[data-role="error"]'), false);
    this.showScreen("pin");
  };

  Kiosk.prototype.loadEmployees = function () {
    var self = this;
    var list = $(this.root, '[data-role="names"]');
    var error = $(this.root, '[data-role="list-error"]');
    if (!list) {
      return;
    }

    post("css_tc_employees", { kiosk: "name" })
      .then(function (data) {
        var employees = data.employees || [];
        list.innerHTML = "";
        if (!employees.length) {
          text(error, strings.noEmployees || "");
          show(error, true);
          return;
        }
        show(error, false);
        employees.forEach(function (emp) {
          var button = document.createElement("button");
          button.type = "button";
          button.className = "css-tc-name";
          button.setAttribute("data-employee", String(emp.id));
          button.setAttribute("data-name", emp.greeting || emp.name || "");
          var strong = document.createElement("strong");
          strong.textContent = emp.name || "";
          button.appendChild(strong);
          if (emp.department) {
            var span = document.createElement("span");
            span.textContent = emp.department;
            button.appendChild(span);
          }
          list.appendChild(button);
        });
      })
      .catch(function (err) {
        text(error, err.message || strings.network);
        show(error, true);
      });
  };

  Kiosk.prototype.filterNames = function (query) {
    var q = (query || "").toLowerCase();
    this.root.querySelectorAll(".css-tc-name").forEach(function (button) {
      var label = (button.textContent || "").toLowerCase();
      button.hidden = q !== "" && label.indexOf(q) === -1;
    });
  };

  // StatusBoard no-ops without [data-role="board"] (included by pin/name kiosks).
  // Pass that instance into Kiosk so punch success can apply data.board immediately.
  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".css-tc-kiosk").forEach(function (root) {
      var board = new StatusBoard(root);
      if (root.getAttribute("data-enabled") === "1") {
        new Kiosk(root, board);
      }
    });
  });
})();
