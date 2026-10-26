/**
 * Preview math must match the PHP payday service on the owner schedule.
 * Run: node bin/check-payday-js.js
 */
"use strict";

var calc = require("../admin/js/payday-calc.js");
var failed = 0;

function check(cond, label) {
  if (cond) {
    console.log("ok  " + label);
    return;
  }
  failed++;
  console.log("FAIL " + label);
}

var owner = {
  frequency: "engine",
  engine_length: "biweekly",
  engine_anchor: "2026-09-07",
  week_start: 1,
  payday_mode: "weekday",
  payday_weekday: 5,
  payday_days: 5,
  payday_anchor: "2026-10-23",
  shift: "previous",
  holidays: "",
};

var expect = [
  ["2026-10-05", "2026-10-18", "2026-10-23"],
  ["2026-10-19", "2026-11-01", "2026-11-06"],
  ["2026-11-02", "2026-11-15", "2026-11-20"],
  ["2026-11-16", "2026-11-29", "2026-12-04"],
  ["2026-11-30", "2026-12-13", "2026-12-18"],
];

["weekday", "days"].forEach(function (mode) {
  var config = Object.assign({}, owner, { payday_mode: mode });
  expect.forEach(function (row) {
    var period = calc.periodFor(config, row[0]);
    check(
      period && period.start === row[0] && period.end === row[1] && period.payday === row[2] && period.length === 14,
      mode + " " + row[0] + " pays " + row[2]
    );
  });
  var next = calc.upcoming(config, "2026-10-26", 4);
  check(next.length === 4 && next[0].start === "2026-10-19" && next[0].payday === "2026-11-06", mode + " Oct 19–Nov 1 pays Nov 6");
  check(next[1].start === "2026-11-02" && next[1].end === "2026-11-15" && next[1].payday === "2026-11-20", mode + " Nov 2–15 pays Nov 20");
  check(next[2].start === "2026-11-16" && next[2].end === "2026-11-29" && next[2].payday === "2026-12-04", mode + " Nov 16–29 pays Dec 4");
  check(calc.nextPayday(config, "2026-10-26") === "2026-11-06", mode + " next payday on Oct 26 is Nov 6");
});

var series = ["2026-10-23", "2026-11-06", "2026-11-20", "2026-12-04", "2026-12-18"];
series.forEach(function (day, i) {
  check(calc.seriesOnOrAfter("2026-10-23", i === 0 ? "2026-10-23" : series[i - 1] === day ? day : day, 14) === day || calc.seriesOnOrAfter("2026-10-23", day, 14) === day, day + " is on the series");
});
check(calc.seriesOnOrAfter("2026-10-23", "2026-10-26", 14) === "2026-11-06", "series from Oct 26 is Nov 6");
check(calc.nextPayday(Object.assign({}, owner, { payday_mode: "anchor", engine_anchor: "2026-08-31" }), "2026-10-26") === "2026-11-06", "timecard anchor does not move the series");
check(calc.periodFor(Object.assign({}, owner, { payday_mode: "weekday" }), "2026-12-31").start === "2026-12-28" && calc.periodFor(owner, "2026-12-31").end === "2027-01-10" && calc.periodFor(owner, "2026-12-31").payday === "2027-01-15", "year-boundary period pays Jan 15");
check(calc.differs(Object.assign({}, owner, { frequency: "biweekly", anchor: "2026-10-18" }), "2026-10-26") && !calc.differs(owner, "2026-10-26"), "a Sunday anchor differs and the timecard schedule does not");
check(calc.addDays("2026-03-08", 1) === "2026-03-09" && calc.addDays("2026-11-01", 14) === "2026-11-15", "DST dates stay on the calendar");
check(calc.addDays("2026-12-31", 1) === "2027-01-01", "year boundary");

var semi = calc.periodFor({ frequency: "semimonthly_1_15", anchor: "2026-01-01", payday_mode: "on_end", shift: "keep" }, "2026-02-20");
check(semi && semi.start === "2026-02-15" && semi.end === "2026-02-28", "semi-monthly February");
var monthly = calc.periodFor({ frequency: "monthly", anchor: "2026-01-31", payday_mode: "on_end", shift: "keep" }, "2026-02-15");
check(monthly && monthly.start === "2026-01-31" && monthly.end === "2026-02-27", "monthly day 31");

if (failed > 0) {
  console.log(failed + " failed");
  process.exit(1);
}
console.log("all ok");
