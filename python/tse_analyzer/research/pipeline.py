"""One command for the whole research run: ``tse-analyzer research run``.

1. checks the connection to every data source and logs what answered;
2. picks the universe (the most traded shares of the market, plus portfolio.csv / watchlist.txt);
3. brings the local price store up to date (resumable: fresh symbols are skipped on a re-run),
   the index, and the macro drivers;
4. tests trading shares, one session to four weeks, after costs and queues, and a
   low-turnover portfolio (shortterm.py);
5. tests moving between the stock market, gold, the dollar and a fixed-income fund through
   traded funds (allocation.py), and sets everything against inflation;
6. scores every symbol at every month end with data up to that day, and measures the score,
   its factors, the «احتمال رشد» figure and the candidate ideas (calibrate.py);
7. replays the site's buy rule (signals.py) and the market-level studies (market.py);
8. writes a folder with the full log, a plain-language report with the accuracy summary first,
   machine-readable results, the per-observation data, and weights.json only if it passed the
   out-of-sample test.

``short_only`` stops after step 5 (a few minutes instead of the whole run).
"""

from __future__ import annotations

import csv
import json
import logging
import math
import os
import platform
import sys
import time
import traceback
from datetime import datetime
from pathlib import Path

import pandas as pd

from ..client import TEDPIX, TseGatewayClient, TsetmcClient, TsetmcError
from ..store import PriceStore
from . import allocation, calibrate, macro, market, shortterm, signals
from .common import MIN_HISTORY, load_index, load_universe
from .universe import market_universe

log = logging.getLogger("research")


# ----------------------------------------------------------------------- run

def run(top: int = 200, db: str = "prices.sqlite", out: str = "research-output", drivers_dir: str = "drivers",
        workers: int | None = None, skip_update: bool = False, step: int = signals.STEP, cash_rate: float = 0.25,
        timeout: float = 30.0, extra_files: tuple[str, ...] = ("portfolio.csv", "watchlist.txt"),
        max_age_hours: float = 20.0, short_only: bool = False, inflation: float = shortterm.INFLATION) -> int:
    folder = Path(out) / datetime.now().strftime("%Y-%m-%d_%H%M")
    folder.mkdir(parents=True, exist_ok=True)
    _setup_logging(folder / "research.log")
    workers = workers or max(1, (os.cpu_count() or 2) - 1)
    started = time.time()
    log.info("پوشه‌ی خروجی: %s", folder.resolve())
    log.info("Python %s · %s · pandas %s · %d هسته‌ی پردازشی", platform.python_version(), platform.platform(),
             pd.__version__, workers)
    try:
        return _run(folder, top, db, drivers_dir, workers, skip_update, step, cash_rate, timeout, extra_files,
                    max_age_hours, started, short_only, inflation)
    except KeyboardInterrupt:
        log.warning("متوقف شد. داده‌های دریافت‌شده در %s مانده‌اند؛ اجرای دوباره از همان‌جا ادامه می‌دهد.", db)
        return 130
    except Exception:  # noqa: BLE001 - the log must hold the whole story
        log.error("خطای پیش‌بینی‌نشده:\n%s", traceback.format_exc())
        log.error("این پوشه را (به‌خصوص research.log) برای بررسی بفرستید: %s", folder.resolve())
        return 1


def _run(folder: Path, top: int, db: str, drivers_dir: str, workers: int, skip_update: bool, step: int,
         cash_rate: float, timeout: float, extra_files: tuple[str, ...], max_age_hours: float, started: float,
         short_only: bool = False, inflation: float = shortterm.INFLATION) -> int:
    client = TsetmcClient(timeout=timeout, max_retries=3)
    gateway = TseGatewayClient(timeout=timeout, max_retries=2)

    if skip_update:
        _step(1, "بدون دریافت داده (--skip-update): فقط داده‌های موجود در " + db)
        with PriceStore(db) as store:
            symbols = [i["symbol"] for i in store.instruments() if i["symbol"]]
        log.info("  %d نماد در پایگاه داده", len(symbols))
    else:
        _step(1, "آزمایش اتصال")
        reach = _check_connections(client, gateway)
        if not reach["cdn"]:
            log.error("سرور داده‌ی بورس (cdn.tsetmc.com) پاسخ نداد. این سرور فقط از اینترنت ایران و بدون VPN پاسخ می‌دهد.")
            return 2
        _step(2, "انتخاب نمادها")
        symbols = _choose_symbols(gateway, top, extra_files, reach["gateway"], db)
    if not symbols:
        log.error("هیچ نمادی برای بررسی پیدا نشد.")
        return 3

    with PriceStore(db) as store:
        if not skip_update:
            _step(3, f"دریافت تاریخچه‌ی {len(symbols)} نماد، شاخص کل و محرک‌های کلان")
            _update_store(store, client, symbols, max_age_hours)
            series, fetched = macro.fetch_all(timeout, drivers_dir)
            macro.save(store, series)
            for f in fetched:
                status = (f"{f.points} روز از {f.first} تا {f.last} · منبع {f.source}"
                          + ("" if f.verified else " (منبع تأییدنشده)")) if f.source else "دریافت نشد"
                log.info("  %-12s %s", f.label, status)
                for e in f.errors:
                    log.debug("      %s", e)
        universe = load_universe(store, symbols, MIN_HISTORY)
        index = load_index(store)
        drivers = macro.load(store)

    if not universe:
        log.error("هیچ نمادی تاریخچه‌ی کافی (دست‌کم %d جلسه) ندارد.", MIN_HISTORY)
        return 4
    first = min(s.raw.index[0] for s in universe)
    last = max(s.raw.index[-1] for s in universe)
    log.info("داده‌ی قابل استفاده: %d نماد از %s تا %s · شاخص کل: %s · محرک‌ها: %s", len(universe),
             first.strftime("%Y-%m-%d"), last.strftime("%Y-%m-%d"),
             f"{len(index)} روز" if index is not None else "ندارد", "، ".join(macro.LABELS[k] for k in drivers) or "هیچ")

    _step(4, "آزمون معامله‌ی سهام (۱ جلسه تا ۴ هفته و سبد کم‌گردش، بعد از کارمزد و با رعایت صف)")
    t0 = time.time()
    short = shortterm.run(universe, index, inflation=inflation, progress=_progress("روش", 0, "روش خرید و فروش"))
    log.info("%d روز معاملاتی و %d سهم در %.0f ثانیه", short.days, short.symbols, time.time() - t0)
    _step(5, "جابه‌جایی بین بورس، طلا، دلار و درآمد ثابت")
    alloc = allocation.run(index, drivers, cash_rate, inflation,
                           pd.Timestamp(short.split) if short.split else None)
    log.info("  %d روش از %s تا %s", len(alloc.strategies), alloc.start or "—", alloc.end or "—")
    if short_only:
        _step(6, "نوشتن نتایج")
        summary = "\n".join(["═" * 70, "خلاصه‌ی سودآوری (روی داده‌ی واقعی، فقط با اطلاعات همان روز)", "═" * 70,
                             *verdict_lines(short, alloc, inflation), "", *shortterm.summary_lines(short), "",
                             *allocation.summary_lines(alloc), "═" * 70])
        report = "\n\n".join([summary, shortterm.report_text(short), allocation.report_text(alloc)])
        (folder / "report.txt").write_text(report + "\n", encoding="utf-8")
        _write_short(folder, short)
        (folder / "summary.json").write_text(json.dumps({
            "generated_at": datetime.now().isoformat(timespec="seconds"), "symbols": len(universe),
            "shortterm": short.to_json(), "allocation": alloc.to_json()},
            ensure_ascii=False, indent=2, default=_json_default), encoding="utf-8")
        log.info("\n%s", summary)
        log.info("پایان در %.0f دقیقه. گزارش کامل: %s", (time.time() - started) / 60, (folder / "report.txt").resolve())
        return 0

    _step(6, "ارزیابی ماهانه‌ی امتیاز و عامل‌ها (افق ۳ ماهه، بدون استفاده از داده‌ی آینده)")
    t0 = time.time()
    observations = calibrate.collect(universe, index, workers=workers, drivers=drivers or None,
                                     progress=_progress("ارزیابی", len(universe)))
    log.info("%d مشاهده در %.0f ثانیه", len(observations), time.time() - t0)
    if drivers:
        log.info("آزمون دارونما برای محرک‌های کلان (۱۹ نسخه‌ی جابه‌جاشده در زمان برای هر محرک):")
    cal = calibrate.calibrate(universe, index, workers=workers, observations=observations, drivers=drivers or None,
                              progress=_progress("دارونما", 0, "آزمون"))

    _step(7, "شبیه‌سازی قانون خرید سایت")
    t0 = time.time()
    sig = signals.run(universe, index, step=step, workers=workers, progress=_progress("شبیه‌سازی", len(universe)))
    log.info("%d معامله (قانون سایت)، %d (قانون قبلی) و %d (بدون فیلتر) در %.0f ثانیه", len(sig.trades), len(sig.plain),
             len(sig.unfiltered), time.time() - t0)

    _step(8, "مطالعات سطح بازار")
    studies = {
        "seasonality": market.seasonality(index),
        "flow_sentiment": market.flow_sentiment(universe, index),
        "volatility_targeting": market.volatility_targeting(index, cash_rate),
        "index_in_dollars": market.index_in_dollars(index, drivers.get("usd")),
        "macro_context": macro.market_context(index, drivers),
    }

    _step(9, "نوشتن نتایج")
    summary = accuracy_summary(cal, sig, studies, universe, first, last, drivers, short, alloc, inflation)
    report = "\n\n".join([summary, shortterm.report_text(short), allocation.report_text(alloc), calibrate.report_text(cal),
                           signals.report_text(sig), studies_text(studies)])
    (folder / "report.txt").write_text(report + "\n", encoding="utf-8")
    weights = folder / ("weights.json" if cal.accepted else "weights.rejected.json")
    cal.save(weights)
    _write_observations(folder / "observations.csv", cal.observations)
    _write_trades(folder / "trades.csv", sig.trades)
    _write_short(folder, short)
    (folder / "summary.json").write_text(json.dumps({
        "generated_at": datetime.now().isoformat(timespec="seconds"), "symbols": len(universe),
        "from": first.strftime("%Y-%m-%d"), "to": last.strftime("%Y-%m-%d"),
        "calibration": cal.to_json(), "signals": {"site": sig.site, "previous_rule": sig.baseline,
                                                 "no_filter": sig.no_filter, "by_regime": sig.by_regime},
        "studies": studies, "shortterm": short.to_json(), "allocation": alloc.to_json()}, ensure_ascii=False, indent=2,
        default=_json_default),
        encoding="utf-8")
    log.info("\n%s", summary)
    log.info("پایان در %.0f دقیقه. گزارش کامل: %s", (time.time() - started) / 60, (folder / "report.txt").resolve())
    if cal.accepted:
        log.info("وزن‌های جدید قبول شد: %s را در پوشه‌ی config برنامه‌ی سایت (با همان نام weights.json) بگذارید.",
                 weights.resolve())
    else:
        log.info("وزن‌های جدید قبول نشد؛ چیزی برای آپلود روی سایت نیست (جزئیات: %s).", weights.name)
    return 0


# -------------------------------------------------------------------- steps

def _check_connections(client: TsetmcClient, gateway: TseGatewayClient) -> dict[str, bool]:
    checks = {}
    try:
        checks["cdn"] = bool(client.search("فولاد"))
    except (TsetmcError, ValueError) as exc:
        log.debug("cdn: %s", exc)
        checks["cdn"] = False
    try:
        checks["gateway"] = len(gateway.market_watch("Cash")) > 50
    except (TsetmcError, ValueError) as exc:
        log.debug("gateway: %s", exc)
        checks["gateway"] = False
    log.info("  سرور داده‌ی بورس (cdn.tsetmc.com): %s", "در دسترس" if checks["cdn"] else "پاسخ نداد")
    log.info("  دیده‌بان رسمی (webgw.tse.ir): %s", "در دسترس" if checks["gateway"] else "پاسخ نداد")
    return checks


def _choose_symbols(gateway: TseGatewayClient, top: int, extra_files: tuple[str, ...], gateway_ok: bool,
                    db: str) -> list[str]:
    symbols: list[str] = []
    if gateway_ok and top > 0:
        try:
            chosen = market_universe(gateway, top)
            symbols = [c.symbol for c in chosen]
            log.info("  %d سهم پرمعامله‌ی امروز (بدون صندوق، اوراق، حق تقدم، اختیار و بازار پایه)", len(symbols))
            log.debug("  %s", "، ".join(symbols))
        except (TsetmcError, ValueError) as exc:
            log.warning("  دیده‌بان بازار خوانده نشد (%s)", exc)
    for path in extra_files:
        if Path(path).exists():
            from ..cli import _symbols_from_file
            added = [s for s in _symbols_from_file(path) if s not in symbols]
            symbols += added
            log.info("  %d نماد از %s", len(added), path)
    if not symbols:
        with PriceStore(db) as store:
            symbols = [i["symbol"] for i in store.instruments() if i["symbol"]]
        if symbols:
            log.info("  از %d نماد موجود در %s استفاده می‌شود", len(symbols), db)
    log.info("  توجه: فقط نمادهایی که امروز در بورس هستند بررسی می‌شوند (نمادهای حذف‌شده نیستند)؛ "
             "نتیجه کمی خوش‌بینانه است.")
    return list(dict.fromkeys(symbols))


def _update_store(store: PriceStore, client: TsetmcClient, symbols: list[str], max_age_hours: float) -> None:
    counts: dict[str, int] = {}
    t0 = time.time()
    for k, symbol in enumerate(symbols, 1):
        result = store.update(client, symbol, max_age_hours)
        counts[result.status] = counts.get(result.status, 0) + 1
        if result.status == "error":
            log.warning("  [%d/%d] %s: %s", k, len(symbols), symbol, result.error)
        else:
            log.debug("  [%d/%d] %s: %s، %d ردیف، تا %s", k, len(symbols), symbol, result.label, result.rows, result.last_date)
        if k % 10 == 0 or k == len(symbols):
            elapsed = time.time() - t0
            log.info("  %d از %d نماد · %.0f ثانیه · باقی‌مانده حدود %.0f دقیقه", k, len(symbols), elapsed,
                     elapsed / k * (len(symbols) - k) / 60)
    log.info("  نتیجه: %s", "، ".join(f"{_STATUS.get(s, s)} {n}" for s, n in counts.items()))
    idx = store.update_index(client, TEDPIX, max_age_hours)
    log.info("  شاخص کل: %s، تا %s %s", idx.label, idx.last_date, idx.error)


_STATUS = {"fresh": "به‌روز بود", "incremental": "به‌روزرسانی", "full": "دریافت کامل", "error": "خطا"}


def _progress(label: str, total: int, unit: str = "نماد"):
    t0 = time.time()

    def report(done: int, of: int) -> None:
        n = total or of
        if done % max(1, n // 10) == 0 or done == n:
            elapsed = time.time() - t0
            log.info("  %s: %d از %d %s · باقی‌مانده حدود %.0f دقیقه", label, done, n, unit,
                     elapsed / done * (n - done) / 60)
    return report


# ------------------------------------------------------------- the summary

def verdict_lines(short: shortterm.ShortTerm, alloc: allocation.Allocation, inflation: float) -> list[str]:
    """One comparison on the same years: the best share strategy, the best move between funds, the
    stock market itself and inflation."""
    best = short.best()
    if not short.split or (best is None and not alloc.strategies):
        return ["جمع‌بندی: داده برای مقایسه کافی نبود."]
    lines = [f"جمع‌بندی (سال‌های {shortterm._jd(short.split)} تا {shortterm._jd(short.last)}، که در انتخاب هیچ عاملی "
             f"استفاده نشده‌اند؛ تورم مقایسه {inflation * 100:.0f}٪ در سال):"]
    if best:
        label, m = best
        lines.append(f"   • بهترین روش سهام: «{label}»: رشد سالانه {shortterm._pct(m['cagr_pct'], 0)}، "
                     f"بیشترین افت {shortterm._pct(m['max_drawdown_pct'], 0)}")
        if m.get("index_cagr_pct") is not None:
            lines.append(f"   • شاخص کل در همان ماه‌ها: رشد سالانه {shortterm._pct(m['index_cagr_pct'], 0)}")
    recent = sorted(((s["label"], s["recent"]) for s in alloc.strategies.values() if s["recent"].get("cagr_pct") is not None),
                    key=lambda kv: -kv[1]["cagr_pct"])
    if recent:
        label, r = recent[0]
        lines.append(f"   • بهترین جابه‌جایی با صندوق‌ها: «{label}»: رشد سالانه {shortterm._pct(r['cagr_pct'], 0)}، "
                     f"بیشترین افت {shortterm._pct(r['max_drawdown_pct'], 0)}")
    rates = ([best[1]["cagr_pct"]] if best and best[1].get("cagr_pct") is not None else []) \
        + ([recent[0][1]["cagr_pct"]] if recent else [])
    if rates:
        lines.append("   → " + ("دست‌کم یک روش از تورم جلو زده است." if max(rates) > inflation * 100
                                else "هیچ روشی از تورم جلو نزده است."))
    return lines


def accuracy_summary(cal: calibrate.Calibration, sig: signals.SignalReport, studies: dict, universe, first, last,
                     drivers: dict, short: shortterm.ShortTerm | None = None, alloc: allocation.Allocation | None = None,
                     inflation: float = shortterm.INFLATION) -> str:
    a = cal.accuracy
    v = cal.validation
    test = v.get("default_test") or {}
    ic, test_ic = a["ic"], (test.get("ic") or {})
    lines = ["═" * 70, "خلاصه‌ی دقت (روی داده‌ی واقعی و فقط با اطلاعاتی که در همان روز در دسترس بود)", "═" * 70]
    if short is not None and alloc is not None:
        lines += verdict_lines(short, alloc, inflation) + [""] + shortterm.summary_lines(short) + [""] \
            + allocation.summary_lines(alloc) + ["", "─" * 70, "امتیاز پتانسیل و سیگنال‌های سایت (برای مرجع):"]
    elif short is not None:
        lines += shortterm.summary_lines(short) + ["", "─" * 70, "افق‌های بلندتر (برای مرجع):"]
    lines += [f"داده: {len(universe)} نماد، {first:%Y-%m-%d} تا {last:%Y-%m-%d}، {a['months']} ماه، "
              f"{a['observations']} مشاهده (هر نماد در پایان هر ماه شمسی).", ""]

    # 1. the score
    verdict = _score_verdict(ic, test_ic)
    lines.append(f"۱) امتیاز پتانسیل رشد (افق ۳ ماهه): {verdict}")
    lines.append(f"   • همبستگی رتبه‌ای امتیاز با بازده ۳ ماه بعد (IC ماهانه): {_n(ic['mean'], 3)} "
                 f"(t={_n(ic['t'], 1)}، مثبت در {_p(ic['positive_share'])} ماه‌ها)"
                 + (f"؛ در دوره‌ی آزمون جداگانه {_n(test_ic.get('mean'), 3)}" if test_ic else ""))
    hit, base = a["high_score_hit"], a["base_hit"]
    if hit["rate"] is not None:
        lines.append(f"   • وقتی امتیاز ۶۵ یا بیشتر بود ({hit['observations']} مورد): در {_p(hit['rate'])} موارد بازده ۳ ماه بعد "
                     f"از شاخص کل بیشتر بود (بازه‌ی ۹۵٪: {_p(hit['low'])} تا {_p(hit['high'])})؛ برای همه‌ی نمادها {_p(base['rate'])}.")
    spread = a["quintile_spread_pct"]
    if spread["mean"] is not None:
        lines.append(f"   • اختلاف بازده ۳ ماهه‌ی ۲۰٪ امتیاز بالا با ۲۰٪ امتیاز پایین: {_pct(spread['mean'])} "
                     f"(t={_n(spread['t'], 1)}).")
    lines.append("")

    # 2. probability
    pc = cal.probability
    lines.append(f"۲) «احتمال رشد ۳ ماهه»: {_probability_verdict(pc)}")
    if pc.get("brier") is not None:
        lines.append(f"   • {pc['n']} مورد؛ امتیاز Brier {pc['brier']:.3f} در برابر {pc['reference_brier']:.3f} برای "
                     f"«همیشه میانگین بازار» ({_p(pc['base_rate'])} رشد) → مهارت {_pct((pc['skill'] or 0) * 100)}")
        for b in pc["bins"]:
            lines.append(f"   • وقتی گفتیم {_p(b['predicted'])}: در واقع {_p(b['actual'])} رشد کردند ({b['n']} مورد)")
    lines.append("")

    # 3. signals
    s, old, free = sig.site, sig.baseline, sig.no_filter
    lines.append(f"۳) سیگنال خرید با قانون فعلی سایت (تا ۴۲ جلسه): {_signal_verdict(s, free)}")
    if s.get("trades"):
        ci = s["win_rate_ci"]
        lines.append(f"   • {s['trades']} معامله؛ سودده {_p(s['win_rate'])} (بازه‌ی ۹۵٪: {_p(ci[0])} تا {_p(ci[1])})، "
                     f"رسیدن به هدف {_p(s['target_rate'])}، حد ضرر {_p(s['stop_rate'])}؛ "
                     f"امید ریاضی {_pct(s['expectancy_pct'])} در هر معامله، میانگین {s['avg_days']:.0f} جلسه.")
    if s.get("excess_pct") is not None:
        lines.append(f"   • شاخص کل در همان روزها {_pct(s['index_pct'])}؛ بازده مازاد بر شاخص {_pct(s['excess_pct'])} "
                     f"در هر معامله (t={_n(s['excess_t'], 1)} روی {s['excess_months']} ماه).")
    if free.get("trades"):
        lines.append(f"   • بدون فیلتر امتیاز و احتمال: {free['trades']} معامله، سودده {_p(free['win_rate'])}، "
                     f"امید ریاضی {_pct(free['expectancy_pct'])}"
                     + (f"، مازاد بر شاخص {_pct(free['excess_pct'])}" if free.get("excess_pct") is not None else "") + ".")
    if old.get("trades"):
        lines.append(f"   • قانون ساده‌ی قبلی: {old['trades']} معامله، سودده {_p(old['win_rate'])}، "
                     f"امید ریاضی {_pct(old['expectancy_pct'])}.")
    lines.append("")

    # 4. weights
    lines.append(f"۴) وزن‌های کالیبره: {'قبول شد — weights.json ساخته شد' if cal.accepted else 'قبول نشد'} ({v['reason']}).")
    lines.append("")

    # 5. candidates
    passed = [x["label"] for x in cal.candidates.values() if x["passed"]]
    lines.append("۵) ایده‌های آزمایشی (بورس‌های دیگر و محرک‌های کلان): "
                 + (f"قبول شد: {'، '.join(passed)}" if passed else "هیچ‌کدام شواهد کافی نداشت"))
    best = sorted(cal.candidates.values(), key=lambda x: -(x["t"] if x["t"] is not None else -99))[:3]
    for x in best:
        lines.append(f"   • {x['label']}: IC افزوده {_n(x['mean'], 3)} (t={_n(x['t'], 1)})"
                     + (f"، دارونما p={x['placebo_p']:.2f}" if x.get("placebo_p") is not None else ""))
    if not drivers:
        lines.append("   • هیچ محرک کلانی دریافت نشد؛ باد موافق کلان آزموده نشد.")
    lines.append("")

    # 6. market
    lines.append("۶) بازار:")
    seasonal = [x for x in studies["seasonality"] if x["pattern"]]
    lines.append("   • فصلی بودن: " + ("، ".join(f"{x['label']} ({_pct(x['mean_pct'])} میانگین، t={_n(x['t_vs_average'], 1)})"
                                          for x in seasonal) or "الگوی معناداری پیدا نشد") + ".")
    fs = studies["flow_sentiment"]
    if fs.get("corr") is not None:
        lines.append(f"   • جریان پول حقیقی: همبستگی ماه با شاخص ماه بعد {_n(fs['corr'], 2)} (t={_n(fs['t'], 1)})؛ "
                     f"بعد از ورود قوی {_pct(fs['next_after_inflow_pct'])}، بعد از خروج قوی {_pct(fs['next_after_outflow_pct'])}.")
    vt = studies["volatility_targeting"]
    if vt:
        h, t = vt["hold"], vt["targeted"]
        lines.append(f"   • هدف‌گذاری نوسان: بیشترین افت {_pct(t['max_drawdown_pct'])} در برابر {_pct(h['max_drawdown_pct'])}، "
                     f"رشد سالانه {_pct(t['cagr_pct'])} در برابر {_pct(h['cagr_pct'])} "
                     f"(با فرض سود {vt['cash_rate'] * 100:.0f}٪ برای بخش نقد).")
    usd = studies["index_in_dollars"]
    if usd:
        lines.append(f"   • شاخص کل از {usd['from']} تا {usd['to']}: به ریال {_pct(usd['rial_pct'])}، به دلار {_pct(usd['usd_pct'])}.")
    lines += ["", "محدودیت‌ها: نمادهای حذف‌شده از بورس در داده نیستند؛ EPS تاریخی نداریم (عامل ارزش‌گذاری سنجیده نمی‌شود)؛",
              "ماشه‌ی لحظه‌ای سیگنال از داده‌ی روزانه قابل بازسازی نیست؛ گذشته تضمین آینده نیست.", "═" * 70]
    return "\n".join(lines)


def _score_verdict(ic: dict, test_ic: dict) -> str:
    m, t = ic.get("mean"), ic.get("t")
    if m is None:
        return "داده‌ی کافی نبود"
    if m > 0 and t is not None and t >= 2 and (test_ic.get("mean") is None or test_ic["mean"] > 0):
        return "قدرت پیش‌بینی معنادار دارد"
    if m > 0:
        return "نشانه‌ی مثبت دارد ولی از نظر آماری قطعی نیست"
    return "قدرت پیش‌بینی ندارد"


def _probability_verdict(pc: dict) -> str:
    if pc.get("skill") is None:
        return "داده‌ی کافی نبود"
    off = max((abs(b["predicted"] - b["actual"]) for b in pc["bins"] if b["n"] >= 30), default=None)
    if pc["skill"] > 0 and (off is None or off <= 0.1):
        return "قابل اتکاست (بهتر از میانگین ساده و بدون خطای بزرگ)"
    if pc["skill"] > 0:
        return "بهتر از میانگین ساده است ولی در برخی بازه‌ها بیش از ۱۰ واحد درصد خطا دارد"
    return "بهتر از «همیشه میانگین بازار» نیست؛ عدد آن را با احتیاط بخوانید"


def _signal_verdict(s: dict, free: dict | None = None) -> str:
    """Judged against TEDPIX over the same sessions (inflation lifts almost every long trade) and
    against taking every valid plan without the score/probability filter."""
    if not s.get("trades"):
        return "معامله‌ای پیدا نشد"
    if s["trades"] < 30:
        return "تعداد معاملات برای قضاوت کم است"
    e, t = s.get("excess_pct"), s.get("excess_t")
    if e is None:
        return ("امید ریاضی مثبت بوده، ولی بدون شاخص کل نمی‌شود گفت از خرید بازار بهتر بوده"
                if s["expectancy_pct"] > 0 else "سودده نبوده است")
    if e > 0 and t is not None and t >= 2:
        verdict = "از شاخص کل در همان روزها بهتر بوده و این برتری معنادار است"
    elif e > 0:
        verdict = "کمی بهتر از شاخص کل در همان روزها، ولی از نظر آماری قطعی نیست"
    else:
        verdict = "از خرید شاخص کل در همان روزها بهتر نبوده" + ("، هرچند سود داشته" if s["expectancy_pct"] > 0 else "")
    if free and free.get("trades") and free.get("excess_pct") is not None:
        verdict += ("؛ فیلتر امتیاز و احتمال کمک کرده" if e > free["excess_pct"]
                    else "؛ فیلتر امتیاز و احتمال کمکی نکرده")
    return verdict


def studies_text(studies: dict) -> str:
    lines = ["مطالعات سطح بازار", ""]
    seasonal = studies["seasonality"]
    if seasonal:
        lines.append("بازده شاخص کل به تفکیک ماه شمسی (t در برابر میانگین همه‌ی ماه‌ها؛ الگو فقط با |t| ≥ ۲٫۵):")
        for x in seasonal:
            lines.append(f"  {x['label']:<10} {x['years']:>3} سال  میانگین {_pct(x['mean_pct']):>8}  "
                         f"مثبت {_p(x['positive_share']):>5}  t={_n(x['t_vs_average'], 1):>5}"
                         + ("  ← الگو" if x["pattern"] else ""))
    ctx = studies["macro_context"]
    if ctx:
        lines += ["", "شاخص کل و محرک‌های کلان (بتا و همبستگی هفتگی؛ پیش‌بینی ماه بعد شاخص از ماه قبل محرک):"]
        for x in ctx:
            lines.append(f"  {x['label']:<10} بتا {_n(x['beta'], 2):>6}  همبستگی {_n(x['corr'], 2):>6}  "
                         f"ماه قبل محرک → ماه بعد شاخص: {_n(x['lead_corr'], 2)} (t={_n(x['lead_t'], 1)}، {x['lead_months']} ماه)")
    return "\n".join(lines)


# ------------------------------------------------------------------- output

def _write_observations(path: Path, observations: list) -> None:
    names = sorted({k for o in observations for k in o.candidates})
    with path.open("w", newline="", encoding="utf-8-sig") as fh:
        w = csv.writer(fh)
        w.writerow(["date", "symbol", "ins_code", "score", "prob_up", "fwd_pct", "excess_pct", *names])
        for o in observations:
            w.writerow([o.date.strftime("%Y-%m-%d"), o.symbol, o.ins_code, f"{o.score:.2f}",
                        "" if o.prob_up is None else f"{o.prob_up:.3f}", f"{o.fwd_pct:.3f}",
                        "" if o.excess_pct is None else f"{o.excess_pct:.3f}",
                        *("" if o.candidates.get(n) is None else f"{o.candidates[n]:.5f}" for n in names)])


def _write_short(folder: Path, short: shortterm.ShortTerm) -> None:
    """The test-period picks of every short-term method: what would have been bought, and how it went."""
    if short.picks is None or short.picks.empty:
        return
    picks = short.picks.copy()
    picks["date"] = picks["date"].dt.strftime("%Y-%m-%d")
    picks["return_pct"] = (picks.pop("return") * 100).round(2)
    picks["score"] = picks["score"].round(3)
    picks[["target", "date", "symbol", "score", "return_pct"]].to_csv(folder / "shortterm_picks.csv", index=False,
                                                                      encoding="utf-8-sig")


def _write_trades(path: Path, trades: list) -> None:
    with path.open("w", newline="", encoding="utf-8-sig") as fh:
        w = csv.writer(fh)
        w.writerow(["date", "symbol", "score", "stop_pct", "target_pct", "reward_risk", "odds_win", "regime", "outcome",
                    "return_pct", "days", "index_pct"])
        for t in trades:
            w.writerow([t.date.strftime("%Y-%m-%d"), t.symbol, f"{t.score:.1f}", f"{t.stop_pct:.2f}", f"{t.target_pct:.2f}",
                        f"{t.reward_risk:.2f}", f"{t.odds_win:.3f}", t.regime or "", t.outcome,
                        "" if t.return_pct is None else f"{t.return_pct:.2f}", t.days,
                        "" if t.index_pct is None else f"{t.index_pct:.2f}"])


def _setup_logging(path: Path) -> None:
    for stream in (sys.stdout, sys.stderr):
        try:
            stream.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):
            pass
    log.setLevel(logging.DEBUG)
    log.handlers.clear()
    file = logging.FileHandler(path, encoding="utf-8")
    file.setLevel(logging.DEBUG)
    file.setFormatter(logging.Formatter("%(asctime)s %(levelname)-7s %(message)s", "%Y-%m-%d %H:%M:%S"))
    console = logging.StreamHandler(sys.stdout)
    console.setLevel(logging.INFO)
    console.setFormatter(logging.Formatter("%(message)s"))
    log.addHandler(file)
    log.addHandler(console)
    log.propagate = False


def _step(n: int, title: str) -> None:
    log.info("")
    log.info("── مرحله‌ی %d: %s", n, title)


def _json_default(x):
    if isinstance(x, pd.Timestamp):
        return x.strftime("%Y-%m-%d")
    if hasattr(x, "item"):
        return x.item()
    if isinstance(x, float) and math.isnan(x):
        return None
    raise TypeError(type(x))


def _n(x, digits: int) -> str:
    return "-" if x is None else f"{x:+.{digits}f}"


def _p(x) -> str:
    return "-" if x is None else f"{x * 100:.0f}٪"


def _pct(x) -> str:
    return "-" if x is None else f"{x:+.1f}٪"
