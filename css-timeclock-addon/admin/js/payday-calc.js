/**
 * Pay & Payday preview math. Mirrors includes/class-payday.php.
 * Dates are UTC calendar days so DST cannot skip or repeat one.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }
  root.cssTcPaydayCalc = api;
})(typeof window !== "undefined" ? window : global, function () {
  var INTERVAL = 14;

  function pad(n) {
    n = String(n);
    return n.length < 2 ? "0" + n : n;
  }

  function isYmd(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || "");
    if (!m) {
      return false;
    }
    var y = parseInt(m[1], 10);
    var mo = parseInt(m[2], 10);
    var d = parseInt(m[3], 10);
    if (y < 2000 || y > 2100) {
      return false;
    }
    return d >= 1 && d <= daysInMonth(y, mo);
  }

  function daysInMonth(year, month) {
    if (month < 1 || month > 12) {
      return 0;
    }
    return new Date(Date.UTC(year, month, 0)).getUTCDate();
  }

  function utc(ymd) {
    if (!isYmd(ymd)) {
      return null;
    }
    var p = ymd.split("-");
    return new Date(Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10)));
  }

  function addDays(ymd, days) {
    var dt = utc(ymd);
    if (!dt) {
      return "";
    }
    dt.setUTCDate(dt.getUTCDate() + days);
    return dt.getUTCFullYear() + "-" + pad(dt.getUTCMonth() + 1) + "-" + pad(dt.getUTCDate());
  }

  function diffDays(from, to) {
    var a = utc(from);
    var b = utc(to);
    if (!a || !b) {
      return null;
    }
    return Math.round((b.getTime() - a.getTime()) / 86400000);
  }

  function intdiv(n, d) {
    return n < 0 ? Math.ceil(n / d) : Math.floor(n / d);
  }

  function shiftMonth(year, month, delta) {
    var index = year * 12 + (month - 1) + delta;
    var y = Math.floor(index / 12);
    var m = index % 12;
    if (m < 0) {
      m += 12;
    }
    return [y, m + 1];
  }

  function ymd(year, month, day) {
    var shifted = shiftMonth(year, month, 0);
    year = shifted[0];
    month = shifted[1];
    if (year < 2000 || year > 2100) {
      return "";
    }
    var dim = daysInMonth(year, month);
    if (day < 1) {
      day = 1;
    }
    if (day > dim) {
      day = dim;
    }
    return year + "-" + pad(month) + "-" + pad(day);
  }

  function parseHolidays(text) {
    var exact = {};
    var annual = {};
    String(text || "").split(/\r?\n/).forEach(function (line) {
      line = line.trim();
      if (!line || line.charAt(0) === "#") {
        return;
      }
      var full = /^(\d{4}-\d{2}-\d{2})\b/.exec(line);
      if (full && isYmd(full[1])) {
        exact[full[1]] = true;
        return;
      }
      var md = /^(\d{2})-(\d{2})\b/.exec(line);
      if (md && parseInt(md[2], 10) <= daysInMonth(2024, parseInt(md[1], 10))) {
        annual[md[1] + "-" + md[2]] = true;
      }
    });
    return { exact: exact, annual: annual };
  }

  function normalize(input) {
    input = input || {};
    var freqs = { engine: 1, weekly: 1, biweekly: 1, semimonthly_1_15: 1, semimonthly_15_eom: 1, monthly: 1 };
    var freq = freqs[input.frequency] ? input.frequency : "engine";
    var mode = input.payday_mode;
    if (mode !== "weekday" && mode !== "days" && mode !== "on_end" && mode !== "anchor") {
      mode = "weekday";
    }
    var fixed = freq === "semimonthly_1_15" || freq === "semimonthly_15_eom" || freq === "monthly";
    if (mode === "on_end" && !fixed) {
      mode = "weekday";
    }
    var week = parseInt(input.week_start, 10);
    if (isNaN(week) || week < 0 || week > 6) {
      week = 1;
    }
    var dow = parseInt(input.payday_weekday, 10);
    if (isNaN(dow) || dow < 0 || dow > 6) {
      dow = 5;
    }
    var days;
    if (input.payday_days === undefined || input.payday_days === null || input.payday_days === "") {
      days = 5;
    } else {
      days = parseInt(input.payday_days, 10);
    }
    if (isNaN(days) || days < 0) {
      days = 0;
    }
    if (days > 90) {
      days = 90;
    }
    var shift = input.shift;
    if (shift !== "previous" && shift !== "next" && shift !== "keep") {
      shift = "previous";
    }
    var engineLength = input.engine_length === "weekly" ? "weekly" : "biweekly";
    return {
      frequency: freq,
      anchor: isYmd(input.anchor) ? input.anchor : "",
      engine_length: engineLength,
      engine_anchor: isYmd(input.engine_anchor) ? input.engine_anchor : "2026-09-07",
      week_start: week,
      payday_mode: mode,
      payday_weekday: dow,
      payday_days: days,
      payday_anchor: isYmd(input.payday_anchor) ? input.payday_anchor : "",
      shift: shift,
      holidays: parseHolidays(input.holidays),
    };
  }

  function isHoliday(config, date) {
    if (config.holidays.exact[date]) {
      return true;
    }
    return !!config.holidays.annual[date.slice(5, 10)];
  }

  function isBlocked(config, date) {
    var dt = utc(date);
    if (!dt) {
      return false;
    }
    var dow = dt.getUTCDay();
    return dow === 0 || dow === 6 || isHoliday(config, date);
  }

  function shiftPayday(date, config) {
    if (!isYmd(date)) {
      return "";
    }
    if (config.shift === "keep") {
      return date;
    }
    var step = config.shift === "next" ? 1 : -1;
    var cursor = date;
    for (var i = 0; i < 21; i++) {
      if (!isBlocked(config, cursor)) {
        return cursor;
      }
      cursor = addDays(cursor, step);
    }
    return cursor;
  }

  function seriesOnOrAfter(anchor, date, interval) {
    interval = interval || INTERVAL;
    var diff = diffDays(anchor, date);
    if (diff === null || !isYmd(anchor)) {
      return "";
    }
    var k = diff >= 0 ? intdiv(diff + interval - 1, interval) : intdiv(diff, interval);
    return addDays(anchor, k * interval);
  }

  function rawPayday(config, end) {
    if (config.payday_mode === "on_end") {
      return end;
    }
    if (config.payday_mode === "days") {
      return addDays(end, config.payday_days);
    }
    if (config.payday_mode === "anchor") {
      return seriesOnOrAfter(config.payday_anchor, addDays(end, 1), INTERVAL);
    }
    var cursor = addDays(end, 1);
    for (var i = 0; i < 7; i++) {
      var dt = utc(cursor);
      if (dt && dt.getUTCDay() === config.payday_weekday) {
        return cursor;
      }
      cursor = addDays(cursor, 1);
    }
    return cursor;
  }

  function engineBounds(input, date) {
    var config = normalize(input);
    if (!isYmd(date)) {
      return null;
    }
    var anchor = config.engine_anchor;
    var anchorDay = utc(anchor);
    if (!anchorDay || anchorDay.getUTCDay() !== 1) {
      anchor = "2026-09-07";
    }
    var len = config.engine_length === "weekly" ? 7 : 14;
    var index = Math.floor(diffDays(anchor, date) / len);
    var start = addDays(anchor, index * len);
    return {
      start: start,
      end: addDays(start, len - 1),
      length: len,
      index: index,
    };
  }

  function periodFor(input, date) {
    var config = normalize(input);
    if (!isYmd(date)) {
      return null;
    }
    if (config.frequency === "engine") {
      var bounds = engineBounds(config, date);
      if (!bounds) {
        return null;
      }
      var rawEngine = rawPayday(config, bounds.end);
      return {
        start: bounds.start,
        end: bounds.end,
        length: bounds.length,
        raw_payday: rawEngine,
        payday: shiftPayday(rawEngine, config),
      };
    }
    if (!config.anchor) {
      return null;
    }
    var start;
    var end;
    var freq = config.frequency;
    if (freq === "weekly" || freq === "biweekly") {
      var len = freq === "weekly" ? 7 : 14;
      var index = Math.floor(diffDays(config.anchor, date) / len);
      start = addDays(config.anchor, index * len);
      end = addDays(start, len - 1);
    } else if (freq === "semimonthly_1_15" || freq === "semimonthly_15_eom") {
      var dt = utc(date);
      var y = dt.getUTCFullYear();
      var m = dt.getUTCMonth() + 1;
      var day = dt.getUTCDate();
      var split = freq === "semimonthly_1_15" ? 14 : 15;
      if (day <= split) {
        start = ymd(y, m, 1);
        end = ymd(y, m, split);
      } else if (freq === "semimonthly_1_15") {
        start = ymd(y, m, 15);
        end = ymd(y, m, 31);
      } else {
        start = ymd(y, m, 16);
        end = ymd(y, m, 31);
      }
    } else {
      var anchorDay = utc(config.anchor).getUTCDate();
      var when = utc(date);
      var yy = when.getUTCFullYear();
      var mm = when.getUTCMonth() + 1;
      var startThis = ymd(yy, mm, anchorDay);
      var next;
      if (date >= startThis) {
        start = startThis;
        var nm = shiftMonth(yy, mm, 1);
        next = ymd(nm[0], nm[1], anchorDay);
      } else {
        var pm = shiftMonth(yy, mm, -1);
        start = ymd(pm[0], pm[1], anchorDay);
        next = startThis;
      }
      end = addDays(next, -1);
    }
    var raw = rawPayday(config, end);
    return {
      start: start,
      end: end,
      length: diffDays(start, end) + 1,
      raw_payday: raw,
      payday: shiftPayday(raw, config),
    };
  }

  function upcoming(input, date, count) {
    count = count || 4;
    var first = periodFor(input, date);
    if (!first) {
      return [];
    }
    var list = [first];
    var cursor = first;
    for (var i = 1; i < count; i++) {
      var next = periodFor(input, addDays(cursor.end, 1));
      if (!next || next.start === cursor.start) {
        break;
      }
      list.push(next);
      cursor = next;
    }
    return list;
  }

  function nextPayday(input, today) {
    var config = normalize(input);
    if (!isYmd(today)) {
      return "";
    }
    if (config.payday_mode === "anchor") {
      var raw = seriesOnOrAfter(config.payday_anchor, today, INTERVAL);
      var pay = shiftPayday(raw, config);
      if (pay && pay >= today) {
        return pay;
      }
      if (!raw) {
        return "";
      }
      return shiftPayday(seriesOnOrAfter(config.payday_anchor, addDays(raw, 1), INTERVAL), config);
    }
    var period = periodFor(config, today);
    if (!period) {
      return "";
    }
    if (period.payday && period.payday >= today) {
      return period.payday;
    }
    var next = periodFor(config, addDays(period.end, 1));
    return next ? next.payday : "";
  }

  function differs(input, date) {
    var config = normalize(input);
    if (config.frequency === "engine") {
      return false;
    }
    var display = periodFor(input, date);
    var engine = engineBounds(input, date);
    if (!display || !engine) {
      return false;
    }
    return display.start !== engine.start || display.end !== engine.end;
  }

  return {
    INTERVAL: INTERVAL,
    isYmd: isYmd,
    addDays: addDays,
    diffDays: diffDays,
    seriesOnOrAfter: seriesOnOrAfter,
    periodFor: periodFor,
    upcoming: upcoming,
    nextPayday: nextPayday,
    differs: differs,
    normalize: normalize,
  };
});
