/* Chart bootstrapping, number formatters and sortable tables. */
(function () {
  "use strict";

  var nf0 = new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 });
  var nf1 = new Intl.NumberFormat("en-US", { maximumFractionDigits: 1 });
  var nf2 = new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function compact(v) {
    if (v === null || v === undefined || isNaN(v)) return "-";
    var a = Math.abs(v);
    if (a >= 1e12) return nf1.format(v / 1e12) + "T";
    if (a >= 1e9) return nf1.format(v / 1e9) + "B";
    if (a >= 1e6) return nf1.format(v / 1e6) + "M";
    if (a >= 1e4) return nf1.format(v / 1e3) + "K";
    return nf0.format(v);
  }
  // U+200E (LRM) keeps "+"/"-" in front of the number inside right-to-left canvas text.
  var LRM = "\u200e";
  function signed(v, f) { if (v === null || v === undefined) return "-"; return LRM + (v > 0 ? "+" : "") + (f || nf1).format(v); }
  function val(p) { return p && p.value !== undefined ? (Array.isArray(p.value) ? p.value : p.value) : p; }

  var FORMATTERS = {
    compact: function (v) { return compact(v); },
    signed: function (p) { return signed(typeof p === "object" ? val(p) : p); },
    pct: function (v) { return v === null || v === undefined ? "-" : LRM + nf2.format(v) + "٪"; },
    pct0: function (p) { return LRM + nf0.format(val(p)) + "٪"; },
    pct1: function (p) { return LRM + nf1.format(val(p)) + "٪"; },
    points: function (ps) { var p = ps[0]; return p.name + "<br>" + signed(p.value) + " امتیاز"; },
    endlabel: function (p) { return p.seriesName + " " + compact(p.value); },
    corr: function (p) {
      var o = p.data || p.value; return o[2] === null ? "-" : "همبستگی: " + nf2.format(o[2]);
    },
    corrlabel: function (p) { var o = p.data || p.value; return o[2] === null ? "" : nf1.format(o[2]); },
    riskreturn: function (p) {
      return "<b>" + p.name + "</b><br>نوسان سالانه " + nf1.format(p.value[0]) + "٪<br>بازده ۱ سال " +
        signed(p.value[1]) + "٪<br>وزن در سبد " + nf1.format(p.value[2]) + "٪";
    },
    bubble: function (v) { return 10 + Math.sqrt(Math.max(v[2], 0)) * 7; },
    treemap: function (p) {
      return "<b>" + p.name + "</b><br>تغییر وزنی " + signed(p.value[1], nf2) + "٪<br>ارزش معاملات " +
        compact(p.value[0] / 10) + " تومان";
    },
    treemaplabel: function (p) { return p.name + "\n" + signed(p.value[1], nf2) + "٪"; }
  };

  function hydrate(node) {
    if (Array.isArray(node)) return node.map(hydrate);
    if (node && typeof node === "object") {
      var out = {};
      Object.keys(node).forEach(function (k) { out[k] = hydrate(node[k]); });
      return out;
    }
    if (typeof node === "string" && node.indexOf("fn:") === 0) {
      return FORMATTERS[node.slice(3)] || node;
    }
    return node;
  }

  var charts = [];
  function initCharts() {
    if (!window.echarts) return;
    document.querySelectorAll("[data-chart]").forEach(function (el) {
      var src = document.getElementById(el.getAttribute("data-chart"));
      if (!src) return;
      var option = hydrate(JSON.parse(src.textContent));
      var chart = echarts.init(el, null, { renderer: "canvas" });
      chart.setOption(option);
      charts.push(chart);
    });
    var t;
    window.addEventListener("resize", function () {
      clearTimeout(t); t = setTimeout(function () { charts.forEach(function (c) { c.resize(); }); }, 120);
    });
  }

  function sortable() {
    document.querySelectorAll("table.sortable").forEach(function (table) {
      var heads = table.querySelectorAll("th[data-sort]");
      heads.forEach(function (th) {
        th.addEventListener("click", function () {
          var idx = Array.prototype.indexOf.call(th.parentNode.children, th);
          var asc = !th.classList.contains("sorted-asc");
          heads.forEach(function (h) { h.classList.remove("sorted-asc", "sorted-desc"); });
          th.classList.add(asc ? "sorted-asc" : "sorted-desc");
          var body = table.tBodies[0];
          var rows = Array.prototype.slice.call(body.rows);
          var numeric = th.getAttribute("data-sort") === "num";
          rows.sort(function (a, b) {
            var x = a.cells[idx].getAttribute("data-v") || a.cells[idx].textContent;
            var y = b.cells[idx].getAttribute("data-v") || b.cells[idx].textContent;
            if (numeric) { x = parseFloat(x); y = parseFloat(y); x = isNaN(x) ? -1e18 : x; y = isNaN(y) ? -1e18 : y; }
            return (x > y ? 1 : x < y ? -1 : 0) * (asc ? 1 : -1);
          });
          rows.forEach(function (r) { body.appendChild(r); });
        });
      });
    });
  }

  /* ---------------------------------------------------------- live page */

  function esc(s) {
    return String(s === null || s === undefined ? "" : s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  }
  function pctHtml(v, d) {
    if (v === null || v === undefined) return '<span class="faint">-</span>';
    var cls = v > 0 ? "up" : v < 0 ? "down" : "";
    return '<span class="num ' + cls + '">' + (v > 0 ? "+" : "") + v.toFixed(d === undefined ? 2 : d) + "٪</span>";
  }
  function numHtml(v, d) {
    if (v === null || v === undefined) return '<span class="faint">-</span>';
    return '<span class="num">' + (d ? nf2 : nf0).format(v) + "</span>";
  }

  function live(root) {
    var api = root.getAttribute("data-api");
    var seen = {};
    var first = true;
    var notifyBtn = document.getElementById("notify-btn");
    if (notifyBtn) {
      if (!("Notification" in window)) notifyBtn.style.display = "none";
      else if (Notification.permission === "granted") notifyBtn.textContent = "اعلان فعال است";
      notifyBtn.addEventListener("click", function () {
        Notification.requestPermission().then(function (p) {
          notifyBtn.textContent = p === "granted" ? "اعلان فعال است" : "اعلان مسدود است";
        });
      });
    }

    var FILTERS = {
      important: function (i) { return i.severity >= 2; },
      up: function (i) { return i.direction > 0; },
      down: function (i) { return i.direction < 0; },
      all: function () { return true; }
    };
    var filter = "important";
    var last = null;
    document.querySelectorAll("#live-chips button").forEach(function (b) {
      b.addEventListener("click", function () {
        filter = b.getAttribute("data-f");
        document.querySelectorAll("#live-chips button").forEach(function (x) { x.classList.toggle("on", x === b); });
        if (last) render(last);
      });
    });

    function render(state) {
      last = state;
      var set = function (id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; };
      set("live-phase", esc(state.phase_label));
      set("live-clock", state.updated ? "آخرین به‌روزرسانی " + esc(state.updated) : "در انتظار داده");
      set("live-status", "به‌روزرسانی هر " + Math.round(state.interval) + " ثانیه در ساعات بازار · " +
        (state.updated ? "آخرین: " + esc(state.updated) : "در حال اتصال…"));
      if (state.market && state.market.index) {
        set("live-index", numHtml(state.market.index));
        set("live-index-chg", pctHtml(state.market.change));
      }
      var all = state.insights || [];
      var items = all.filter(FILTERS[filter]);
      set("live-high", String(all.filter(function (i) { return i.severity >= 3; }).length));
      set("live-count", String((state.symbols || []).length));

      set("live-feed", items.length ? items.map(function (i) {
        var cls = i.direction > 0 ? "up" : i.direction < 0 ? "down" : "flat";
        var mark = (i.direction > 0 ? "▲" : i.direction < 0 ? "▼" : "•") + (i.severity >= 3 ? "!" : "");
        return '<li><span class="pts ' + cls + '">' + mark + '</span><div><span class="name">' +
          esc(i.symbol) + " · " + esc(i.title) + '</span><span class="cat">' + esc(i.at) +
          '</span><div class="detail">' + esc(i.detail) + "</div></div></li>";
      }).join("") : '<li class="empty">هنوز بینشی ثبت نشده است. در ساعات بازار، رویدادهای مهم اینجا ظاهر می‌شوند.</li>');

      var rows = (state.symbols || []).map(function (s) {
        var q = s.queue === "buy" ? '<span class="pill buyq">صف خرید</span>' :
          s.queue === "sell" ? '<span class="pill sellq">صف فروش</span>' : "";
        return "<tr><td><a href=\"/stock?symbol=" + encodeURIComponent(s.symbol) + "\">" + esc(s.symbol) + "</a></td>" +
          "<td>" + numHtml(s.price) + "</td><td>" + pctHtml(s.change) + "</td><td>" + pctHtml(s.close_change) +
          "</td><td>" + numHtml(s.buyer_power, 2) + "</td><td>" + (s.queue ? '<span class="faint">-</span>' :
          numHtml(s.imbalance, 2)) + "</td><td>" + q +
          (s.error ? ' <span class="down" title="' + esc(s.error) + '">خطا</span>' : "") + "</td></tr>";
      }).join("");
      set("live-table", '<table><thead><tr><th>نماد</th><th>آخرین</th><th>تغییر</th><th>پایانی</th>' +
        "<th>قدرت خریدار</th><th>خرید/فروش تابلو</th><th>صف</th></tr></thead><tbody>" + rows + "</tbody></table>");

      all.forEach(function (i) {
        var key = i.at + i.symbol + i.rule + i.direction;
        if (seen[key]) return;
        seen[key] = true;
        if (!first && i.severity >= 3 && window.Notification && Notification.permission === "granted") {
          new Notification(i.symbol + " · " + i.title, { body: i.detail, lang: "fa", dir: "rtl" });
        }
      });
      first = false;
    }

    function poll() {
      fetch(api, { cache: "no-store" }).then(function (r) { return r.json(); }).then(render)
        .catch(function () {
          var el = document.getElementById("live-status");
          if (el) el.textContent = "اتصال به سرور برنامه قطع است؛ دوباره تلاش می‌شود…";
        })
        .then(function () { setTimeout(poll, 10000); });
    }
    poll();
  }

  function start() {
    sortable();
    var liveRoot = document.getElementById("live-root");
    if (liveRoot) live(liveRoot);
    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    ready.then(initCharts, initCharts);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start); else start();
})();
