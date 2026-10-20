/* Sabadgardan front-end: charts, tables, toasts, help & tour, symbol search, transaction drawer,
   Jalali date picker, async sections and the live monitor. No dependencies besides ECharts. */
(function () {
  "use strict";

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
  var LRM = "‎";  // keeps +/- in front of numbers inside right-to-left canvas text
  var nf0 = new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 });
  var nf1 = new Intl.NumberFormat("en-US", { maximumFractionDigits: 1 });
  var nf2 = new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var CSRF = (document.querySelector('meta[name="csrf"]') || {}).content || "";
  var BASE = (document.querySelector('meta[name="base"]') || {}).content || "";
  var GLOSSARY = window.GLOSSARY || {};

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function toEn(s) { return String(s).replace(/[۰-۹]/g, function (d) { return "۰۱۲۳۴۵۶۷۸۹".indexOf(d); }).replace(/[٠-٩]/g, function (d) { return "٠١٢٣٤٥٦٧٨٩".indexOf(d); }); }
  function parseNum(s) { var v = parseFloat(toEn(s).replace(/[,٬\s]/g, "")); return isNaN(v) ? null : v; }
  function compact(v) {
    if (v == null || isNaN(v)) return "-";
    var a = Math.abs(v);
    if (a >= 1e12) return nf1.format(v / 1e12) + "T";
    if (a >= 1e9) return nf1.format(v / 1e9) + "B";
    if (a >= 1e6) return nf1.format(v / 1e6) + "M";
    if (a >= 1e4) return nf1.format(v / 1e3) + "K";
    return nf0.format(v);
  }
  function signed(v, f) { return v == null ? "-" : LRM + (v > 0 ? "+" : "") + (f || nf1).format(v); }
  function toman(v) {  // v already in toman
    if (v == null || isNaN(v)) return "-";
    var a = Math.abs(v);
    if (a >= 1e12) return LRM + nf1.format(v / 1e12) + " هزار میلیارد تومان";
    if (a >= 1e9) return LRM + nf2.format(v / 1e9) + " میلیارد تومان";
    if (a >= 1e6) return LRM + nf1.format(v / 1e6) + " میلیون تومان";
    return LRM + nf0.format(v) + " تومان";
  }
  function val(p) { return p && p.value !== undefined ? p.value : p; }

  /* ------------------------------------------------------------- charts */
  var FORMATTERS = {
    compact: function (v) { return compact(v); },
    signed: function (p) { return signed(typeof p === "object" ? val(p) : p); },
    pct: function (v) { return v == null ? "-" : LRM + nf2.format(v) + "٪"; },
    pct0: function (p) { return LRM + nf0.format(val(p)) + "٪"; },
    pct1: function (p) { return LRM + nf1.format(val(p)) + "٪"; },
    toman: function (v) { return toman(v); },
    tomanAxis: function (v) { return compact(v); },
    tomanLabel: function (p) { return compact(val(p)); },  // values already in toman
    points: function (ps) { var p = ps[0]; return p.name + "<br>" + signed(p.value) + " امتیاز"; },
    endlabel: function (p) { return p.seriesName + " " + compact(p.value); },
    corr: function (p) { var o = p.data || p.value; return o[2] == null ? "-" : "همبستگی: " + nf2.format(o[2]); },
    corrlabel: function (p) { var o = p.data || p.value; return o[2] == null ? "" : nf1.format(Math.abs(o[2]) < 0.05 ? 0 : o[2]); },
    riskreturn: function (p) {
      return "<b>" + esc(p.name) + "</b><br>نوسان سالانه " + nf1.format(p.value[0]) + "٪<br>بازده ۱ سال " + signed(p.value[1]) +
        "٪<br>وزن در سبد " + nf1.format(p.value[2]) + "٪";
    },
    bubble: function (v) { return 10 + Math.sqrt(Math.max(v[2], 0)) * 7; },
    treemap: function (p) { return "<b>" + esc(p.name) + "</b><br>تغییر وزنی " + signed(p.value[1], nf2) + "٪<br>ارزش معاملات " + toman(p.value[0] / 10); },
    treemaplabel: function (p) { return p.name + "\n" + signed(p.value[1], nf2) + "٪"; }
  };
  function hydrate(node) {
    if (Array.isArray(node)) return node.map(hydrate);
    if (node && typeof node === "object") { var o = {}; Object.keys(node).forEach(function (k) { o[k] = hydrate(node[k]); }); return o; }
    if (typeof node === "string" && node.indexOf("fn:") === 0) return FORMATTERS[node.slice(3)] || node;
    return node;
  }
  var charts = [];
  function initCharts(root) {
    if (!window.echarts) return;
    $$("[data-chart]", root).forEach(function (el) {
      if (el._chart) return;
      var src = document.getElementById(el.getAttribute("data-chart"));
      if (!src) return;
      var chart = echarts.init(el, null, { renderer: "canvas" });
      chart.setOption(narrow(hydrate(JSON.parse(src.textContent)), el.clientWidth));
      el._chart = chart;
      charts.push(chart);
    });
  }
  /* Phones: label gutters sized for desktop (category labels beside bars) would leave no room for the bars. */
  function narrow(opt, width) {
    if (width >= 520) return opt;
    [].concat(opt.grid || []).forEach(function (g) {
      if (g.right > 110) g.right = 104;
      if (g.left > 110) g.left = 104;
    });
    [].concat(opt.yAxis || []).forEach(function (a) {
      if (a.axisLabel && a.axisLabel.width > 100) a.axisLabel.width = 96;
    });
    return opt;
  }
  var resizeT;
  window.addEventListener("resize", function () { clearTimeout(resizeT); resizeT = setTimeout(function () { charts.forEach(function (c) { c.resize(); }); }, 120); });

  /* ------------------------------------------------------------- tables */
  function sortable(root) {
    $$("table.sortable", root).forEach(function (table) {
      var heads = $$("th[data-sort]", table);
      heads.forEach(function (th) {
        th.addEventListener("click", function () {
          var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
          var asc = !th.classList.contains("sorted-asc");
          heads.forEach(function (h) { h.classList.remove("sorted-asc", "sorted-desc"); });
          th.classList.add(asc ? "sorted-asc" : "sorted-desc");
          var numeric = th.getAttribute("data-sort") === "num";
          var body = table.tBodies[0];
          Array.prototype.slice.call(body.rows).sort(function (a, b) {
            var x = a.cells[idx] ? (a.cells[idx].getAttribute("data-v") || a.cells[idx].textContent) : "";
            var y = b.cells[idx] ? (b.cells[idx].getAttribute("data-v") || b.cells[idx].textContent) : "";
            if (numeric) { x = parseFloat(x); y = parseFloat(y); x = isNaN(x) ? -1e18 : x; y = isNaN(y) ? -1e18 : y; }
            return (x > y ? 1 : x < y ? -1 : 0) * (asc ? 1 : -1);
          }).forEach(function (r) { body.appendChild(r); });
        });
      });
    });
  }

  /* -------------------------------------------------------------- toasts */
  function toast(message, type) {
    var box = $(".toasts") || document.body.appendChild(Object.assign(document.createElement("div"), { className: "toasts" }));
    var t = document.createElement("div");
    t.className = "toast " + (type || "success");
    t.setAttribute("role", "status");
    t.textContent = message;
    box.appendChild(t);
    setTimeout(function () { t.style.opacity = "0"; t.style.transition = "opacity .3s"; setTimeout(function () { t.remove(); }, 300); }, type === "error" ? 7000 : 4000);
  }
  window.toast = toast;

  /* -------------------------------------------------------- shell & misc */
  function shell() {
    var sidebar = $(".sidebar"), overlay = $(".overlay");
    $$("[data-menu]").forEach(function (b) {
      b.addEventListener("click", function () { sidebar.classList.toggle("open"); overlay.classList.toggle("open", sidebar.classList.contains("open")); });
    });
    var top = $(".topbar");
    if (top) window.addEventListener("scroll", function () { top.classList.toggle("scrolled", window.scrollY > 4); }, { passive: true });
    document.addEventListener("submit", function (e) {
      var msg = e.target.getAttribute("data-confirm");
      if (msg && !window.confirm(msg)) e.preventDefault();
    });
    $$("[data-flash]").forEach(function (el) { toast(el.getAttribute("data-flash"), el.getAttribute("data-type")); });
  }

  /* --------------------------------------------------------- help popover */
  var helpPop;
  function closeHelp() { if (helpPop) { helpPop.remove(); helpPop = null; } }
  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".help-btn");
    if (!btn) { if (helpPop && !e.target.closest(".help-pop")) closeHelp(); return; }
    e.preventDefault();
    var entry = GLOSSARY[btn.getAttribute("data-help-key")];
    closeHelp();
    if (!entry) return;
    helpPop = document.createElement("div");
    helpPop.className = "help-pop";
    helpPop.innerHTML = "<b>" + esc(entry[0]) + "</b>" + esc(entry[1]);
    document.body.appendChild(helpPop);
    var r = btn.getBoundingClientRect();
    var left = Math.min(Math.max(12, r.left + window.scrollX - helpPop.offsetWidth + 24), window.innerWidth - helpPop.offsetWidth - 12);
    helpPop.style.left = left + "px";
    helpPop.style.top = (r.bottom + window.scrollY + 8) + "px";
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") { closeHelp(); closeDrawer(); } });

  /* ----------------------------------------------------------------- tour */
  function startTour() {
    var steps = $$("[data-help]").filter(function (el) { return el.offsetParent !== null && GLOSSARY[el.getAttribute("data-help")]; });
    if (!steps.length) { toast("برای این صفحه هنوز راهنمایی ثبت نشده است.", "error"); return; }
    var mask = document.body.appendChild(Object.assign(document.createElement("div"), { className: "tour-mask" }));
    var pop = document.body.appendChild(Object.assign(document.createElement("div"), { className: "tour-pop" }));
    pop.setAttribute("role", "dialog");
    var i = 0;
    function end() { mask.remove(); pop.remove(); document.removeEventListener("keydown", keys); try { localStorage.setItem("tour:" + location.pathname, "1"); } catch (e) {} }
    function keys(e) { if (e.key === "Escape") end(); if (e.key === "ArrowLeft") go(i + 1); if (e.key === "ArrowRight") go(i - 1); }
    function place() {
      var el = steps[i], r = el.getBoundingClientRect(), pad = 6;
      mask.style.top = (r.top - pad) + "px"; mask.style.left = (r.left - pad) + "px";
      mask.style.width = (r.width + pad * 2) + "px"; mask.style.height = Math.min(r.height + pad * 2, window.innerHeight - 20) + "px";
      var ph = pop.offsetHeight, pw = pop.offsetWidth;
      var top = r.bottom + 14 + ph < window.innerHeight ? r.bottom + 14 : Math.max(12, r.top - ph - 14);
      if (top + ph > window.innerHeight) top = Math.max(12, window.innerHeight - ph - 12);
      pop.style.top = top + "px";
      pop.style.left = Math.min(Math.max(12, r.right - pw), window.innerWidth - pw - 12) + "px";
    }
    function go(n) {
      if (n < 0) return;
      if (n >= steps.length) { end(); return; }
      i = n;
      var e = GLOSSARY[steps[i].getAttribute("data-help")];
      var dots = steps.map(function (_, k) { return "<i class='" + (k === i ? "on" : "") + "'></i>"; }).join("");
      pop.innerHTML = "<h3>" + esc(e[0]) + "</h3><p>" + esc(e[1]) + "</p><footer><div><div class='step'>" + (i + 1) + " از " + steps.length +
        "</div><div class='tour-dots'>" + (steps.length <= 14 ? dots : "") + "</div></div><div style='display:flex;gap:6px'>" +
        (i > 0 ? "<button class='btn secondary sm' data-t='prev'>قبلی</button>" : "<button class='btn ghost sm' data-t='end'>بستن</button>") +
        "<button class='btn sm' data-t='next'>" + (i === steps.length - 1 ? "پایان" : "بعدی") + "</button></div></footer>";
      steps[i].scrollIntoView({ block: "center", behavior: "smooth" });
      setTimeout(place, 320);
    }
    pop.addEventListener("click", function (e) {
      var t = e.target.getAttribute("data-t");
      if (t === "next") go(i + 1); else if (t === "prev") go(i - 1); else if (t === "end") end();
    });
    document.addEventListener("keydown", keys);
    window.addEventListener("resize", place);
    go(0);
  }
  function tourButtons() {
    $$("[data-tour-start]").forEach(function (b) { b.addEventListener("click", startTour); });
    var auto = $("[data-tour-auto]");
    var seen = false;
    try { seen = localStorage.getItem("tour:" + location.pathname) === "1"; } catch (e) {}
    if (auto && !seen) {
      setTimeout(function () {
        var t = document.createElement("div");
        t.className = "toast";
        t.innerHTML = "اولین بار است این صفحه را می‌بینید؟ <button class='btn sm' style='margin-inline-start:8px'>آموزش صفحه</button>";
        ($(".toasts") || document.body).appendChild(t);
        t.querySelector("button").addEventListener("click", function () { t.remove(); startTour(); });
        setTimeout(function () { t.remove(); }, 9000);
        try { localStorage.setItem("tour:" + location.pathname, "1"); } catch (e) {}
      }, 1200);
    }
  }

  /* --------------------------------------------------------------- jalali */
  var J = {
    toJ: function (gy, gm, gd) {
      var g = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334], gy2 = gm > 2 ? gy + 1 : gy;
      var days = 355666 + 365 * gy + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) + gd + g[gm - 1];
      var jy = -1595 + 33 * Math.floor(days / 12053); days %= 12053; jy += 4 * Math.floor(days / 1461); days %= 1461;
      if (days > 365) { jy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      return days < 186 ? [jy, 1 + Math.floor(days / 31), 1 + days % 31] : [jy, 7 + Math.floor((days - 186) / 30), 1 + (days - 186) % 30];
    },
    toG: function (jy, jm, jd) {
      jy += 1595;
      var days = -355668 + 365 * jy + Math.floor(jy / 33) * 8 + Math.floor((jy % 33 + 3) / 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
      var gy = 400 * Math.floor(days / 146097); days %= 146097;
      if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
      gy += 4 * Math.floor(days / 1461); days %= 1461;
      if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
      var gd = days + 1, leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
      var ml = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31], gm = 1;
      while (gm <= 12 && gd > ml[gm]) { gd -= ml[gm]; gm++; }
      return [gy, gm, gd];
    },
    monthLen: function (jy, jm) {
      if (jm <= 6) return 31; if (jm <= 11) return 30;
      var g = J.toG(jy, 12, 30), back = J.toJ(g[0], g[1], g[2]);
      return back[1] === 12 && back[2] === 30 ? 30 : 29;
    },
    today: function () { var d = new Date(); return J.toJ(d.getFullYear(), d.getMonth() + 1, d.getDate()); },
    fmt: function (j) { return j[0] + "/" + String(j[1]).padStart(2, "0") + "/" + String(j[2]).padStart(2, "0"); },
    parse: function (s) { var m = toEn(s || "").match(/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/); return m ? [+m[1], +m[2], +m[3]] : null; },
    MONTHS: ["فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"]
  };

  var picker;
  function closePicker() { if (picker) { picker.remove(); picker = null; } }
  function openPicker(input) {
    closePicker();
    var today = J.today(), sel = J.parse(input.value) || today, view = [sel[0], sel[1]];
    picker = document.createElement("div");
    picker.className = "jdp";
    function cmp(a, b) { return a[0] - b[0] || a[1] - b[1] || a[2] - b[2]; }
    function render() {
      var first = J.toG(view[0], view[1], 1), wd = (new Date(first[0], first[1] - 1, first[2]).getDay() + 1) % 7;  // Saturday = 0
      var html = "<div class='jdp-head'><button type='button' class='icon-btn' data-m='-1'>›</button><span>" + J.MONTHS[view[1] - 1] + " " + view[0] +
        "</span><button type='button' class='icon-btn' data-m='1'>‹</button></div><div class='jdp-grid'>";
      ["ش", "ی", "د", "س", "چ", "پ", "ج"].forEach(function (d) { html += "<span class='wd'>" + d + "</span>"; });
      for (var k = 0; k < wd; k++) html += "<span></span>";
      for (var d = 1; d <= J.monthLen(view[0], view[1]); d++) {
        var j = [view[0], view[1], d], cls = (cmp(j, sel) === 0 ? "sel " : "") + (cmp(j, today) === 0 ? "today" : "");
        html += "<button type='button' class='" + cls + "' data-d='" + d + "'" + (cmp(j, today) > 0 ? " disabled" : "") + ">" + d + "</button>";
      }
      picker.innerHTML = html + "</div>";
    }
    picker.addEventListener("click", function (e) {
      e.stopPropagation();
      var m = e.target.getAttribute("data-m"), d = e.target.getAttribute("data-d");
      if (m) { view[1] += +m; if (view[1] < 1) { view[1] = 12; view[0]--; } if (view[1] > 12) { view[1] = 1; view[0]++; } render(); }
      if (d) { input.value = J.fmt([view[0], view[1], +d]); input.dispatchEvent(new Event("input", { bubbles: true })); closePicker(); }
    });
    render();
    input.parentNode.style.position = "relative";
    input.parentNode.appendChild(picker);
    picker.style.top = (input.offsetTop + input.offsetHeight + 6) + "px";
    picker.style.right = "0";
  }
  document.addEventListener("click", function (e) {
    var input = e.target.closest("[data-jdate]");
    if (input) { openPicker(input); return; }
    if (picker && !e.target.closest(".jdp")) closePicker();
    var chip = e.target.closest("[data-jdate-chip]");
    if (chip) {
      var target = document.getElementById(chip.getAttribute("data-target")), back = +chip.getAttribute("data-jdate-chip");
      var dt = new Date(); dt.setDate(dt.getDate() - back);
      target.value = J.fmt(J.toJ(dt.getFullYear(), dt.getMonth() + 1, dt.getDate()));
      target.dispatchEvent(new Event("input", { bubbles: true }));
    }
  });
  function initDates(root) { $$("[data-jdate]", root).forEach(function (el) { if (!el.value) el.value = J.fmt(J.today()); el.setAttribute("autocomplete", "off"); }); }

  /* --------------------------------------------------------- autocomplete */
  function api(path, params) {
    var q = params ? "?" + new URLSearchParams(params).toString() : "";
    return fetch(BASE + path + q, { headers: { Accept: "application/json" }, credentials: "same-origin" }).then(function (r) { return r.json(); });
  }
  function symbolSearch(root) {
    $$("[data-symbol-search]", root).forEach(function (box) {
      var input = $("input[type=search], input[type=text]", box), list = $(".ac-list", box), hidden = $("input[name=ins_code]", box);
      var chosen = $(".chosen", box), items = [], active = -1, timer, seq = 0;
      function choose(it) {
        hidden.value = it.ins_code;
        input.value = "";
        list.classList.remove("open");
        if (chosen) {
          chosen.hidden = false; input.parentNode.hidden = true;
          $("b", chosen).textContent = it.symbol;
          $("span", chosen).textContent = it.name + (it["class"] ? " · " + it["class"] : "") + (it.market ? " · " + it.market : "");
        }
        box.dispatchEvent(new CustomEvent("symbol:chosen", { detail: it, bubbles: true }));
        if (box.hasAttribute("data-navigate")) location.href = BASE + "/stock?ins=" + encodeURIComponent(it.ins_code);
        if (box.hasAttribute("data-submit")) box.closest("form").submit();
      }
      function render() {
        if (!items.length) { list.innerHTML = "<div class='ac-empty'>نمادی پیدا نشد.</div>"; return; }
        list.innerHTML = items.map(function (it, k) {
          return "<div class='ac-item" + (k === active ? " active" : "") + "' data-k='" + k + "'><b>" + esc(it.symbol) + "</b><span>" + esc(it.name) +
            "</span>" + (it["class"] ? "<em class='tag " + esc(it.class_tone || "") + "'>" + esc(it["class"]) + "</em>" : "") + "</div>";
        }).join("");
      }
      input.addEventListener("input", function () {
        clearTimeout(timer);
        var term = input.value.trim();
        if (term.length < 2) { list.classList.remove("open"); return; }
        timer = setTimeout(function () {
          var my = ++seq;
          list.innerHTML = "<div class='ac-empty'>در حال جستجو…</div>"; list.classList.add("open");
          api("/api/search", { q: term }).then(function (res) {
            if (my !== seq) return;
            items = res.results || []; active = items.length ? 0 : -1; render();
            if (res.error) list.innerHTML = "<div class='ac-empty'>" + esc(res.error) + "</div>";
          }).catch(function () { list.innerHTML = "<div class='ac-empty'>ارتباط برقرار نشد.</div>"; });
        }, 250);
      });
      input.addEventListener("keydown", function (e) {
        if (!list.classList.contains("open")) return;
        if (e.key === "ArrowDown") { active = Math.min(items.length - 1, active + 1); render(); e.preventDefault(); }
        if (e.key === "ArrowUp") { active = Math.max(0, active - 1); render(); e.preventDefault(); }
        if (e.key === "Enter" && items[active]) { choose(items[active]); e.preventDefault(); }
      });
      list.addEventListener("mousedown", function (e) { var it = e.target.closest(".ac-item"); if (it) { e.preventDefault(); choose(items[+it.getAttribute("data-k")]); } });
      input.addEventListener("blur", function () { setTimeout(function () { list.classList.remove("open"); }, 150); });
      if (chosen) $("button", chosen).addEventListener("click", function () {
        hidden.value = ""; chosen.hidden = true; input.parentNode.hidden = false; input.focus();
        box.dispatchEvent(new CustomEvent("symbol:cleared", { bubbles: true }));
      });
    });
  }

  /* --------------------------------------------------- transaction drawer */
  var drawer, overlay;
  function closeDrawer() { if (drawer) { drawer.classList.remove("open"); overlay.classList.remove("open"); } }
  function initDrawer() {
    drawer = $("#tx-drawer"); overlay = $(".overlay");
    if (!drawer) return;
    var form = $("form", drawer), err = $(".form-error", drawer), fees = JSON.parse(drawer.getAttribute("data-fees") || "{}");
    var feeClass = "stock";  // stock | fund | bond | option (AssetClass::feeClass)
    overlay.addEventListener("click", function () { closeDrawer(); $(".sidebar") && $(".sidebar").classList.remove("open"); });
    $$("[data-close-drawer]", drawer).forEach(function (b) { b.addEventListener("click", closeDrawer); });
    function type() { return (form.querySelector("input[name=type]:checked") || {}).value; }
    function sync() {
      var t = type();
      $$("[data-for]", form).forEach(function (el) { el.hidden = el.getAttribute("data-for").split(" ").indexOf(t) < 0; });
      calc();
    }
    function calc() {
      var t = type(), qty = parseNum(form.quantity.value), price = parseNum(form.price.value), out = $(".calc", form);
      if (!out) return;
      if ((t !== "buy" && t !== "sell") || !qty || !price) { out.hidden = true; return; }
      var gross = qty * price, cls = feeClass, rate = fees["fee_" + cls + "_" + t] || 0;
      var feeAuto = Math.round(gross * rate), feeInput = parseNum(form.fee.value), fee = feeInput == null ? feeAuto : feeInput;
      form.fee.placeholder = "خودکار: " + nf0.format(feeAuto);
      out.hidden = false;
      out.innerHTML = "<div><span>ارزش معامله</span><b class='num'>" + nf0.format(gross) + " ریال</b></div><div><span>کارمزد" +
        (feeInput == null ? " (" + nf2.format(rate * 100) + "٪)" : "") + "</span><b class='num'>" + nf0.format(fee) + " ریال</b></div><div><span>" +
        (t === "buy" ? "مبلغ کل پرداختی" : "مبلغ خالص دریافتی") + "</span><b class='num'>" + nf0.format(t === "buy" ? gross + fee : gross - fee) +
        " ریال</b></div><div class='muted'><span>معادل</span><span>" + toman((t === "buy" ? gross + fee : gross - fee) / 10) + "</span></div>";
    }
    $$("input[name=type]", form).forEach(function (r) { r.addEventListener("change", sync); });
    ["quantity", "price", "fee"].forEach(function (n) { form[n] && form[n].addEventListener("input", calc); });
    $$("input.num-input", form).forEach(function (el) {
      el.addEventListener("blur", function () { var v = parseNum(el.value); if (v != null && el.name !== "fee") el.value = nf0.format(v); });
    });
    drawer.addEventListener("symbol:chosen", function (e) {
      feeClass = e.detail.fee_class || "stock";
      var hint = $(".price-hint", form);
      api("/api/price", { ins: e.detail.ins_code }).then(function (p) {
        if (p.fee_class) feeClass = p.fee_class;
        if (hint && p.close) {
          hint.innerHTML = "قیمت پایانی: <a href='#' data-p='" + p.close + "'>" + nf0.format(p.close) + "</a>" +
            (p.last ? " · آخرین: <a href='#' data-p='" + p.last + "'>" + nf0.format(p.last) + "</a>" : "");
        }
        if (!form.price.value && p.close) form.price.value = nf0.format(p.close);
        calc();
      });
    });
    form.addEventListener("click", function (e) {
      var p = e.target.getAttribute("data-p");
      if (p) { e.preventDefault(); form.price.value = nf0.format(+p); calc(); }
    });
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var btn = $("button[type=submit]", form);
      btn.disabled = true; err.hidden = true;
      fetch(form.action, { method: "POST", body: new FormData(form), headers: { Accept: "application/json", "X-CSRF-Token": CSRF }, credentials: "same-origin" })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.ok) { location.reload(); return; }
          err.textContent = res.error || "ثبت ناموفق بود."; err.hidden = false; btn.disabled = false;
        })
        .catch(function () { err.textContent = "ارتباط با سرور برقرار نشد."; err.hidden = false; btn.disabled = false; });
    });
    $$("[data-open-drawer]").forEach(function (b) {
      b.addEventListener("click", function (e) {
        e.preventDefault();
        form.reset(); err.hidden = true;
        var data = b.getAttribute("data-tx") ? JSON.parse(b.getAttribute("data-tx")) : {};
        var pick = $("#tx-portfolio", form);
        if (pick) { form.action = pick.value; pick.closest(".field").hidden = !!data.id; }
        form.id.value = data.id || "";
        $("h2", drawer).textContent = data.id ? "ویرایش تراکنش" : "ثبت تراکنش";
        var t = data.type || b.getAttribute("data-type") || "buy";
        var radio = form.querySelector("input[name=type][value=" + t + "]"); if (radio) radio.checked = true;
        ["quantity", "price", "fee", "amount", "note", "date"].forEach(function (k) { if (data[k] != null && form[k]) form[k].value = data[k]; });
        var box = $("[data-symbol-search]", form), chosen = $(".chosen", box);
        if (data.ins_code || b.getAttribute("data-ins")) {
          form.ins_code.value = data.ins_code || b.getAttribute("data-ins");
          chosen.hidden = false; $("input[type=search]", box).parentNode.hidden = true;
          $("b", chosen).textContent = data.symbol || b.getAttribute("data-symbol"); $("span", chosen).textContent = "";
          box.dispatchEvent(new CustomEvent("symbol:chosen", { detail: { ins_code: form.ins_code.value, symbol: $("b", chosen).textContent }, bubbles: true }));
        } else {
          form.ins_code.value = ""; chosen.hidden = true; $("input[type=search]", box).parentNode.hidden = false;
        }
        initDates(form);
        sync();
        drawer.classList.add("open"); overlay.classList.add("open");
        setTimeout(function () { var s = $("input[type=search]", form); if (s && !s.parentNode.hidden) s.focus(); }, 260);
      });
    });
    sync();
  }

  /* ------------------------------------------------------- async sections */
  function asyncSections() {
    $$("[data-async]").forEach(function (box) {
      fetch(box.getAttribute("data-async"), { credentials: "same-origin" })
        .then(function (r) { if (!r.ok) throw new Error(r.status); return r.text(); })
        .then(function (html) {
          box.outerHTML = html;
          var root = document.querySelector(".content");
          sortable(root); initCharts(root);
        })
        .catch(function () { box.innerHTML = "<div class='card s12'><div class='notice error'>بارگذاری این بخش ناموفق بود؛ صفحه را دوباره باز کنید.</div></div>"; });
    });
  }

  /* --------------------------------------------------------------- live */
  function live(root) {
    var seen = {}, first = true, filter = "important", last = null;
    var FILTERS = { important: function (i) { return i.severity >= 2; }, up: function (i) { return i.direction > 0; },
                    down: function (i) { return i.direction < 0; }, all: function () { return true; } };
    var notifyBtn = $("#notify-btn");
    if (notifyBtn) {
      if (!("Notification" in window)) notifyBtn.hidden = true;
      else if (Notification.permission === "granted") notifyBtn.textContent = "اعلان مرورگر فعال است";
      notifyBtn.addEventListener("click", function () {
        Notification.requestPermission().then(function (p) { notifyBtn.textContent = p === "granted" ? "اعلان مرورگر فعال است" : "اعلان مسدود است"; });
      });
    }
    $$("#live-chips .chip").forEach(function (c) {
      c.addEventListener("click", function () {
        filter = c.getAttribute("data-f");
        $$("#live-chips .chip").forEach(function (x) { x.classList.toggle("on", x === c); });
        if (last) render(last);
      });
    });
    function pct(v) { return v == null ? "<span class='faint'>-</span>" : "<span class='num " + (v > 0 ? "up" : v < 0 ? "down" : "") + "'>" + signed(v, nf2) + "٪</span>"; }
    function num(v, f) { return v == null ? "<span class='faint'>-</span>" : "<span class='num'>" + (f || nf0).format(v) + "</span>"; }
    function set(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
    function render(s) {
      last = s;
      set("live-phase", esc(s.phase_label));
      var status = $(".live-status"); if (status) status.classList.toggle("closed", s.phase === "closed");
      set("live-clock", "ساعت تهران " + esc(s.clock) + (s.updated ? " · آخرین بررسی " + esc(s.updated) : ""));
      var all = s.insights || [], items = all.filter(FILTERS[filter]);
      set("live-high", String(all.filter(function (i) { return i.severity >= 3; }).length));
      set("live-count", String((s.symbols || []).length));
      if (s.regime) {
        set("live-regime", "<span class='" + esc(s.regime.tone) + "'>" + esc(s.regime.label) + "</span>");
        set("live-regime-sub", s.regime.state === "bear" ? "سیگنال خرید با معیار سخت‌گیرانه‌تر" : esc((s.regime.reasons || [])[0] || ""));
      }
      set("live-sigcount", String((s.signals || []).length));
      set("live-feed", items.length ? items.map(function (i) {
        var cls = i.direction > 0 ? "up" : i.direction < 0 ? "down" : "flat";
        return "<li><span class='pts " + cls + "'>" + (i.direction > 0 ? "▲" : i.direction < 0 ? "▼" : "•") + (i.severity >= 3 ? "!" : "") + "</span><div><span class='name'>" +
          esc(i.symbol) + " · " + esc(i.title) + "</span><span class='cat'>" + esc(i.at) + "</span><div class='detail'>" + esc(i.detail) + "</div></div></li>";
      }).join("") : "<li class='empty'>" + (s.phase === "closed" ? "بازار بسته است. از ۸:۴۵ صبح روزهای معاملاتی، رویدادهای مهم نمادهای شما اینجا ظاهر می‌شوند." :
        "فعلاً رویداد مهمی ثبت نشده است.") + "</li>");
      set("live-signals", (s.signals || []).length ? s.signals.map(function (g) {
        return "<li><span class='pts " + (g.side === "buy" ? "up" : "down") + "'>" + esc(g.label) + "</span><div><span class='name'>" + esc(g.symbol) +
          "</span><span class='cat'>" + esc(g.at) + "</span><div class='detail'>ورود " + nf0.format(g.entry) + (g.target ? " · هدف " + nf0.format(g.target) : "") +
          (g.stop ? " · حد ضرر " + nf0.format(g.stop) : "") + (g.probability != null ? " · احتمال " + Math.round(g.probability * 100) + "٪ (" + g.samples + " نمونه)" : "") +
          "</div></div></li>";
      }).join("") : "<li class='empty'>امروز سیگنالی صادر نشده است.</li>");
      set("live-table", (s.symbols || []).length ? "<table><thead><tr><th>نماد</th><th>آخرین</th><th>تغییر</th><th>پایانی</th><th>قدرت خریدار</th><th>خرید/فروش تابلو</th><th>صف</th></tr></thead><tbody>" +
        s.symbols.map(function (r) {
          var q = r.queue === "buy" ? "<span class='pill buy'>صف خرید</span>" : r.queue === "sell" ? "<span class='pill sell'>صف فروش</span>" : "";
          return "<tr><td><a href='" + BASE + "/stock?ins=" + encodeURIComponent(r.ins_code) + "'>" + esc(r.symbol) + "</a>" +
            (r["class"] ? " <span class='tag " + esc(r.class_tone || "") + "'>" + esc(r["class"]) + "</span>" : "") + (r.held ? " <span class='pill accent'>سبد</span>" : "") +
            "</td><td>" + num(r.price) + "</td><td>" + pct(r.change) + "</td><td>" + pct(r.close_change) + "</td><td>" + num(r.buyer_power, nf2) + "</td><td>" + num(r.imbalance, nf2) +
            "</td><td>" + q + "</td></tr>";
        }).join("") + "</tbody></table>" : "<div class='empty'>نمادی برای رصد ندارید. سبد خود را تکمیل کنید یا نماد به دیده‌بان اضافه کنید.</div>");
      all.forEach(function (i) {
        var key = i.at + i.symbol + i.rule + i.direction;
        if (seen[key]) return;
        seen[key] = true;
        if (!first && i.severity >= 3 && window.Notification && Notification.permission === "granted") new Notification(i.symbol + " · " + i.title, { body: i.detail, lang: "fa", dir: "rtl" });
      });
      first = false;
    }
    function poll() {
      api("/api/live").then(render).catch(function () { set("live-clock", "اتصال به سرور قطع است؛ دوباره تلاش می‌شود…"); })
        .then(function () { setTimeout(poll, last && last.phase !== "closed" ? 15000 : 60000); });
    }
    poll();
  }

  /* ---------------------------------------------------------------- boot */
  function start() {
    shell(); sortable(document); initDates(document); symbolSearch(document); initDrawer(); tourButtons(); asyncSections();
    var liveRoot = $("#live-root"); if (liveRoot) live(liveRoot);
    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    ready.then(function () { initCharts(document); }, function () { initCharts(document); });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start); else start();
})();
