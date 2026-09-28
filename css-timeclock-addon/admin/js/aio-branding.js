/**
 * Remove Codebangers chrome if the admin HTML buffer did not run.
 * Sends the Help tab (support banner only) back to Settings.
 */
(function () {
  "use strict";

  var root = document.querySelector(".aio_admin_wrapper");
  if (root) {
    var logos = root.querySelectorAll(
      'a[href="https://codebangers.com"], a[href="https://codebangers.com/"], a[href="http://codebangers.com"], a[href="http://codebangers.com/"]'
    );
    Array.prototype.forEach.call(logos, function (el) {
      if (el.querySelector("img[src*='logo.png']")) {
        var next = el.nextElementSibling;
        el.remove();
        if (next && next.tagName === "HR") {
          next.remove();
        }
      }
    });

    Array.prototype.forEach.call(root.querySelectorAll("h1"), function (h1) {
      var text = (h1.textContent || "").replace(/\s+/g, " ").trim();
      if (/^all in one time clock lite$/i.test(text)) {
        h1.textContent = "USOTC";
      }
    });

    var support = root.querySelector(".aio-support-section");
    if (support) {
      support.remove();
    }
    Array.prototype.forEach.call(root.querySelectorAll('a.nav-tab[href*="tab=help"]'), function (tab) {
      tab.remove();
    });
  }

  var params;
  try {
    params = new URLSearchParams(window.location.search);
  } catch (err) {
    return;
  }
  if ((params.get("page") || "") === "aio-tc-lite" && (params.get("tab") || "") === "help") {
    params.set("tab", "general_settings");
    window.location.replace(window.location.pathname + "?" + params.toString());
  }
})();
