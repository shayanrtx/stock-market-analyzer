"""Command line entry point.

``tse-analyzer {search,analyze,potential,market,screen,portfolio,performance,funds,rates,news,live,
store,research,doctor,serve}``
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from dataclasses import asdict
from pathlib import Path

from . import disclosures, doctor, funds, portfolio, potential, rates, report, screener
from .analysis import fetch_and_analyze
from .asset_class import classify
from .client import FLOW_BOURSE, FLOW_FARABOURSE, TseGatewayClient, TsetmcClient, TsetmcError
from .fx import TgjuClient, usd_sensitivity
from .regime import market_regime


def cmd_search(client: TsetmcClient, args: argparse.Namespace) -> int:
    results = client.search(args.query)
    if args.json:
        print(json.dumps(results, ensure_ascii=False, indent=2))
        return 0
    for item in results:
        kind = classify(item.get("cIsin"), item.get("lVal30", "")).label
        print(f"{item.get('lVal18AFC', ''):<14} {item.get('insCode', ''):<20} "
              f"{item.get('flowTitle', ''):<10} {kind:<16} {item.get('lVal30', '')}")
    return 0 if results else 1


def cmd_analyze(client: TsetmcClient, args: argparse.Namespace) -> int:
    status = 0
    for symbol in args.symbols:
        try:
            result, _ = fetch_and_analyze(client, symbol, args.days, on_warning=_warn)
        except TsetmcError as exc:
            print(f"error: {exc}", file=sys.stderr)
            status = 1
            continue
        if args.json:
            print(json.dumps(report.stock_dict(result), ensure_ascii=False, indent=2))
        else:
            print(report.stock_text(result))
            print("-" * 60)
    return status


def cmd_potential(client: TsetmcClient, args: argparse.Namespace) -> int:
    status = 0
    rf = _risk_free(client, args)
    usd = _usd(args)
    multipliers = potential.load_multipliers(args.weights) if args.weights else None
    for symbol in args.symbols:
        try:
            a, pot, history = potential.fetch_potential(client, symbol, on_warning=_warn, risk_free=rf.rate,
                                                        multipliers=multipliers)
        except TsetmcError as exc:
            print(f"error: {exc}", file=sys.stderr)
            status = 1
            continue
        kind = classify(a.isin, a.name, a.symbol, pot.sector, pot.sub_sector)
        nav = funds.fetch_nav(client, a.ins_code, a.name, kind) if kind.is_fund else None
        premium = funds.nav_premium(a.last_close, nav)
        sens = usd_sensitivity(history["close"], usd)
        news = disclosures.fetch(client, a.ins_code, 10, on_warning=_warn)
        if args.json:
            print(json.dumps({"analysis": report.stock_dict(a), "potential": asdict(pot),
                              "asset_class": kind.to_dict(), "usd_sensitivity": sens, "nav": nav,
                              "nav_premium_pct": premium, "disclosures": news, "risk_free": rf.rate},
                             ensure_ascii=False, indent=2, default=str))
        else:
            print(report.stock_text(a))
            print()
            print(report.potential_text(a.symbol, pot))
            extra = report.extras_text(sens, nav, premium, kind.label)
            if extra:
                print("\n" + extra)
            if news:
                print("\n" + report.disclosures_text(news, 8))
            print("=" * 60)
    return status


def cmd_market(client: TsetmcClient, args: argparse.Namespace) -> int:
    flow = FLOW_FARABOURSE if args.farabourse else FLOW_BOURSE
    indices = client.indices(flow, selected_only=not args.all)
    effects = _optional(client.index_effect, flow, args.top) or []
    history = _optional(client.index_history)
    regime = market_regime(history if len(history) else None)
    if args.json:
        print(json.dumps({"indices": indices, "effects": effects, "regime": regime.to_dict()},
                         ensure_ascii=False, indent=2))
    else:
        print(report.indices_text(indices, effects))
        print("\n" + report.regime_text(regime))
    return 0


def cmd_screen(client: TsetmcClient, args: argparse.Namespace) -> int:
    gw = TseGatewayClient(timeout=args.timeout, cache_dir=args.cache_dir or None)
    if args.movers:
        rows = screener.top_movers(gw, by=args.by, top=args.top, ascending=args.losers)
    else:
        rows = screener.smart_money(client, top=args.top, min_power=args.min_power)
    if args.json:
        print(json.dumps([asdict(r) for r in rows], ensure_ascii=False, indent=2))
    else:
        print(report.regime_text(screener.regime(client, gw)) + "\n")
        print(report.screen_text(rows))
    return 0


def cmd_portfolio(client: TsetmcClient, args: argparse.Namespace) -> int:
    from .ledger import LedgerError, load_ledger

    holdings = portfolio.load_holdings(args.file)
    try:
        txs = load_ledger(args.ledger) if args.ledger else []
    except LedgerError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    if not holdings and txs:
        from .ledger import Ledger
        holdings = Ledger(txs).holdings()
    if not holdings:
        print(f"پرتفو خالی است. فایل {args.file} را بسازید، هر خط: نماد تعداد [میانگین خرید به ریال]\n"
              "مثال:\nفملی\t17673\nخودران\t10000\t25000", file=sys.stderr)
        return 1
    rf = _risk_free(client, args)
    result = portfolio.analyze_portfolio(client, holdings, risk_free=rf.rate, usd=_usd(args))
    perf = _performance(client, txs, rf.rate) if txs else None
    if args.json:
        data = asdict(result)
        data["performance"] = perf.summary() if perf else None
        print(json.dumps(data, ensure_ascii=False, indent=2, default=_json_default))
    else:
        print(report.portfolio_text(result, perf))
    return 0


def cmd_performance(client: TsetmcClient, args: argparse.Namespace) -> int:
    from .ledger import LedgerError, load_ledger

    try:
        txs = load_ledger(args.ledger)
    except LedgerError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1
    if not txs:
        print(f"دفتر معاملات {args.ledger} خالی است یا وجود ندارد. هر خط: تاریخ نوع نماد تعداد قیمت [کارمزد]\n"
              "مثال:\n1403/01/15\tواریز\t500000000\n1403/01/16\tخرید\tفملی\t10000\t5000", file=sys.stderr)
        return 1
    perf = _performance(client, txs, _risk_free(client, args).rate)
    if perf is None:
        return 1
    if args.json:
        print(json.dumps(perf.summary(), ensure_ascii=False, indent=2, default=str))
    else:
        print(report.performance_text(perf))
    return 0


def cmd_funds(client: TsetmcClient, args: argparse.Namespace) -> int:
    rows = funds.fund_registry(client, args.type)
    rows.sort(key=lambda f: f.returns.get(args.sort) if f.returns.get(args.sort) is not None else -1e9,
              reverse=True)
    rows = rows[:args.top]
    if args.json:
        print(json.dumps([asdict(r) for r in rows], ensure_ascii=False, indent=2))
    else:
        print(report.funds_text(rows, funds.FUND_TYPES.get(args.type, str(args.type))))
    return 0


def cmd_rates(client: TsetmcClient, args: argparse.Namespace) -> int:
    rf = rates.risk_free_rate(client, on_warning=_warn)
    if args.json:
        print(json.dumps({"rate": rf.rate, "source": rf.source, "bills": [asdict(b) for b in rf.bills]},
                         ensure_ascii=False, indent=2, default=str))
    else:
        print(report.risk_free_text(rf))
    return 0 if rf.source == "treasury" else 1


def cmd_news(client: TsetmcClient, args: argparse.Namespace) -> int:
    inst = client.resolve(args.symbol)
    items = disclosures.fetch(client, str(inst["insCode"]), args.top, on_warning=_warn)
    if args.json:
        print(json.dumps(items, ensure_ascii=False, indent=2))
    else:
        print(report.disclosures_text(items, args.top))
    return 0


def cmd_live(client: TsetmcClient, args: argparse.Namespace) -> int:
    from .live import LiveEngine

    symbols = args.symbols or [h.symbol for h in portfolio.load_holdings(args.file)
                               if h.symbol.lower() not in portfolio.CASH_NAMES]
    if not symbols:
        print("نمادی برای رصد نیست: نماد بدهید یا فایل پرتفو را پر کنید.", file=sys.stderr)
        return 1
    # Live data must never come from the disk cache.
    live_client = TsetmcClient(timeout=args.timeout, cache_dir=None, max_retries=1)
    gateway = TseGatewayClient(timeout=args.timeout, cache_dir=None, max_retries=1)
    engine = LiveEngine(live_client, symbols, interval=args.interval, gateway=gateway)
    marks = {1: "▲", -1: "▼", 0: "•"}
    print(f"رصد {len(symbols)} نماد هر {args.interval:.0f} ثانیه (Ctrl+C برای خروج)")
    try:
        while True:
            for ins in reversed(engine.tick()):
                level = "!" * ins.severity
                print(f"{ins.at:%H:%M:%S} {marks[ins.direction]} {level:<3} {ins.symbol:<10} {ins.title}: {ins.detail}",
                      flush=True)
            state = engine.state()
            print(f"[{state['updated']}] {state['phase_label']}", file=sys.stderr, flush=True)
            time.sleep(args.interval if engine.phase.is_live else max(args.interval, 300))
    except KeyboardInterrupt:
        return 0


def cmd_store(client: TsetmcClient, args: argparse.Namespace) -> int:
    from .store import USD_SERIES, PriceStore

    with PriceStore(args.db) as store:
        if args.action == "list":
            rows = store.instruments()
            if args.json:
                print(json.dumps(rows, ensure_ascii=False, indent=2))
            else:
                for r in rows:
                    print(f"{r['symbol']:<14} {r['ins_code']:<20} {r['rows']:>6} جلسه  آخرین تاریخ {r['last_date']}")
                index = store.index()
                print(f"شاخص کل: {len(index)} روز" + (f" تا {index.index[-1]:%Y-%m-%d}" if len(index) else ""))
            return 0
        symbols = list(args.symbols)
        files = [f for f in (args.portfolio, args.watchlist) if f]
        if not symbols and not files:
            files = [f for f in ("portfolio.csv", "watchlist.txt") if Path(f).exists()]
        for f in files:
            symbols += _symbols_from_file(f)
        if args.market:
            from .research.universe import market_universe
            gw = TseGatewayClient(timeout=args.timeout, cache_dir=args.cache_dir or None)
            symbols += [c.symbol for c in market_universe(gw, args.top)]
        symbols = list(dict.fromkeys(symbols))
        results = [store.update(client, s, args.max_age, args.force, flows=not args.no_flows) for s in symbols]
        if not args.no_index:
            results.append(store.update_index(client, max_age_hours=args.max_age, force=args.force))
        if args.usd:
            fx = TgjuClient(timeout=args.timeout, cache_dir=args.cache_dir or None)
            results.append(store.update_series(USD_SERIES, fx.history, args.max_age, args.force))
        if args.macro:
            from .research import macro
            series, fetched = macro.fetch_all(args.timeout, args.drivers)
            macro.save(store, series)
            for f in fetched:
                print(f"{f.label:<12} {f.source or 'دریافت نشد'}  {f.points} روز  {f.first}–{f.last}"
                      + ("" if f.verified or not f.source else "  (منبع تأییدنشده)"))
    if args.json:
        print(json.dumps([asdict(r) for r in results], ensure_ascii=False, indent=2))
    else:
        for r in results:
            print(f"{r.symbol:<20} {r.label:<20} {r.rows:>6} ردیف  {r.last_date}  {r.error}")
        if not symbols:
            print("نمادی داده نشد: نماد بنویسید یا --portfolio / --watchlist بدهید.", file=sys.stderr)
    return 0 if all(r.status != "error" for r in results) else 1


def cmd_research(client: TsetmcClient, args: argparse.Namespace) -> int:
    from .research import calibrate, load_index, load_universe, macro, pipeline, signals
    from .store import PriceStore

    workers = args.workers or max(1, (os.cpu_count() or 2) - 1)
    if args.action == "run":
        return pipeline.run(top=args.top, db=args.db, out=args.output, drivers_dir=args.drivers, workers=workers,
                            skip_update=args.skip_update, step=args.step, cash_rate=args.cash_rate, timeout=args.timeout,
                            short_only=args.short_only, inflation=args.inflation)
    with PriceStore(args.db) as store:
        universe = load_universe(store, args.symbols or None, args.min_history)
        index = load_index(store)
        drivers = macro.load(store)
    if not universe:
        print(f"داده‌ای در {args.db} نیست؛ ابتدا «tse-analyzer store update ...» را اجرا کنید.", file=sys.stderr)
        return 1
    if args.action == "calibrate":
        result = calibrate.calibrate(universe, index, args.horizon or potential.HORIZON, args.min_history,
                                     workers, drivers=drivers or None)
        if args.out:
            result.save(args.out)
        if args.json:
            print(json.dumps(result.to_json(), ensure_ascii=False, indent=2))
        else:
            print(calibrate.report_text(result))
            if args.out:
                print(f"\nضرایب کالیبره در {args.out} ذخیره شد (برای اپ PHP: config/weights.json).")
        return 0
    result = signals.run(universe, index, args.step, args.horizon or signals.HORIZON, args.min_history, workers)
    if args.trades:
        _write_trades(args.trades, result.trades)
    if args.json:
        print(json.dumps({"site": result.site, "previous_rule": result.baseline, "by_regime": result.by_regime,
                          "universe": result.universe, "trades": [asdict(t) for t in result.trades]},
                         ensure_ascii=False, indent=2, default=str))
    else:
        print(signals.report_text(result))
    return 0


def cmd_doctor(client: TsetmcClient, args: argparse.Namespace) -> int:
    results = doctor.run(args.symbol, args.out, args.timeout, etf_symbol=args.etf)
    print(doctor.format_results(results))
    print(f"\nپاسخ‌های نمونه در پوشه‌ی {args.out}/ ذخیره شد.")
    return 0 if all(r.status == "OK" for r in results) else 1


def cmd_serve(client: TsetmcClient, args: argparse.Namespace) -> int:
    from . import web

    web.serve(args.host, args.port, client,
              TseGatewayClient(timeout=args.timeout, cache_dir=args.cache_dir or None),
              args.portfolio, args.watchlist, args.live_interval, ledger_path=args.ledger,
              fx=TgjuClient(timeout=args.timeout, cache_dir=args.cache_dir or None),
              risk_free=args.risk_free)
    return 0


# ----------------------------------------------------------------- helpers

def _risk_free(client: TsetmcClient, args: argparse.Namespace) -> rates.RiskFree:
    return rates.resolve(args.risk_free, client, on_warning=_warn, cache_dir=args.cache_dir or None)


def _usd(args: argparse.Namespace):
    """Free-market dollar history from tgju (optional: None when unreachable)."""
    try:
        series = TgjuClient(timeout=args.timeout, cache_dir=args.cache_dir or None, max_retries=1).history()
        return series if len(series) else None
    except (TsetmcError, ValueError) as exc:
        _warn(f"tgju: {exc}")
        return None


def _performance(client: TsetmcClient, txs, risk_free: float):
    from .ledger import LedgerError
    from .performance import fetch_performance

    try:
        return fetch_performance(client, txs, risk_free, on_warning=_warn)
    except LedgerError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return None


def _symbols_from_file(path: str) -> list[str]:
    """Symbols of a holdings file (portfolio) or a whitespace-separated watchlist."""
    p = Path(path)
    if not p.exists():
        _warn(f"{path} پیدا نشد")
        return []
    held = [h.symbol for h in portfolio.load_holdings(p) if h.symbol.lower() not in portfolio.CASH_NAMES]
    if held:
        return held
    text = p.read_text(encoding="utf-8").replace("،", " ").replace(",", " ")
    return [w for w in text.split() if w and not w.startswith("#")]


def _write_trades(path: str, trades) -> None:
    import csv

    with open(path, "w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh)
        writer.writerow(["date", "symbol", "ins_code", "score", "stop_pct", "target_pct", "reward_risk",
                         "odds_win", "regime", "outcome", "return_pct", "days"])
        for t in trades:
            writer.writerow([f"{t.date:%Y-%m-%d}", t.symbol, t.ins_code, f"{t.score:.1f}", f"{t.stop_pct:.2f}",
                             f"{t.target_pct:.2f}", f"{t.reward_risk:.2f}", f"{t.odds_win:.3f}", t.regime or "",
                             t.outcome, "" if t.return_pct is None else f"{t.return_pct:.2f}", t.days])


def _optional(fn, *args):
    """Supplementary data must not abort the command."""
    try:
        return fn(*args)
    except TsetmcError as exc:
        _warn(str(exc))
        return {}


def _warn(message: str) -> None:
    print(f"warning: {message}", file=sys.stderr)


def _json_default(obj):
    if hasattr(obj, "to_json"):  # pandas Series/DataFrame (date keys become ISO strings)
        return json.loads(obj.to_json(date_format="iso", force_ascii=False))
    if hasattr(obj, "to_dict"):
        return obj.to_dict()
    if hasattr(obj, "isoformat"):
        return obj.isoformat()
    return str(obj)


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="tse-analyzer", description="تحلیلگر بورس تهران (TSETMC)")
    p.add_argument("--json", action="store_true", help="خروجی JSON")
    p.add_argument("--cache-dir", default=".cache", help="پوشه‌ی کش پاسخ‌ها ('' برای غیرفعال)")
    p.add_argument("--timeout", type=float, default=20.0)
    p.add_argument("--risk-free", default="auto",
                   help="نرخ بدون ریسک سالانه: auto (بازده اسناد خزانه) یا عدد مثل 30 یا 0.3")
    sub = p.add_subparsers(dest="command", required=True)

    s = sub.add_parser("search", help="جستجوی نماد")
    s.add_argument("query")
    s.set_defaults(func=cmd_search)

    a = sub.add_parser("analyze", help="تحلیل تکنیکال و حقیقی/حقوقی یک یا چند نماد")
    a.add_argument("symbols", nargs="+", help="نماد (مثلاً فولاد) یا insCode")
    a.add_argument("--days", type=int, default=400, help="تعداد روزهای تاریخچه (0 = همه)")
    a.set_defaults(func=cmd_analyze)

    pt = sub.add_parser("potential", aliases=["deep"],
                        help="بررسی کامل گذشته‌نگر و ارزیابی پتانسیل رشد")
    pt.add_argument("symbols", nargs="+", help="نماد یا insCode")
    pt.add_argument("--weights", default="", help="فایل ضرایب کالیبره (خروجی research calibrate)")
    pt.set_defaults(func=cmd_potential)

    m = sub.add_parser("market", help="وضعیت شاخص‌ها، نمادهای اثرگذار و رژیم بازار")
    m.add_argument("--farabourse", action="store_true", help="فرابورس به‌جای بورس")
    m.add_argument("--all", action="store_true", help="همه‌ی شاخص‌ها شامل صنعتی")
    m.add_argument("--top", type=int, default=7)
    m.set_defaults(func=cmd_market)

    sc = sub.add_parser("screen", help="اسکنر کل بازار")
    sc.add_argument("--movers", action="store_true",
                    help="بیشترین رشد/افت از دیده‌بان رسمی webgw (پیش‌فرض: ورود پول هوشمند)")
    sc.add_argument("--by", choices=["close_pct", "last_pct", "trade_value"], default="close_pct")
    sc.add_argument("--losers", action="store_true", help="بیشترین افت به‌جای بیشترین رشد")
    sc.add_argument("--min-power", type=float, default=1.5, help="حداقل قدرت خریدار")
    sc.add_argument("--top", type=int, default=20)
    sc.set_defaults(func=cmd_screen)

    pf = sub.add_parser("portfolio", help="تحلیل پرتفو (و عملکرد واقعی با --ledger)")
    pf.add_argument("--file", default="portfolio.csv", help="فایل پرتفو (هر خط: نماد تعداد [میانگین خرید])")
    pf.add_argument("--ledger", default="", help="دفتر معاملات برای بازده زمان‌موزون و پول‌موزون")
    pf.set_defaults(func=cmd_portfolio)

    pr = sub.add_parser("performance", help="بازده واقعی سبد از دفتر معاملات (TWR، XIRR، مقایسه با شاخص و تورم)")
    pr.add_argument("--ledger", default="ledger.csv", help="هر خط: تاریخ نوع نماد تعداد قیمت [کارمزد]")
    pr.set_defaults(func=cmd_performance)

    fd = sub.add_parser("funds", help="فهرست صندوق‌ها با NAV و بازده")
    fd.add_argument("--type", type=int, default=6, choices=sorted(funds.FUND_TYPES),
                    help="؛ ".join(f"{k}={v}" for k, v in funds.FUND_TYPES.items()))
    fd.add_argument("--sort", type=int, default=365, help="مرتب‌سازی بر اساس بازده چند روزه")
    fd.add_argument("--top", type=int, default=30)
    fd.set_defaults(func=cmd_funds)

    rt = sub.add_parser("rates", help="نرخ بدون ریسک از بازده تا سررسید اسناد خزانه (اخزا)")
    rt.set_defaults(func=cmd_rates)

    nw = sub.add_parser("news", help="پیام‌های ناظر و اطلاعیه‌های کدال یک نماد")
    nw.add_argument("symbol")
    nw.add_argument("--top", type=int, default=20)
    nw.set_defaults(func=cmd_news)

    lv = sub.add_parser("live", help="رصد زنده‌ی بازار و پیش‌گشایش (هشدار صف، پول هوشمند، حجم مشکوک و ...)")
    lv.add_argument("symbols", nargs="*", help="نمادها (پیش‌فرض: نمادهای پرتفو)")
    lv.add_argument("--file", default="portfolio.csv")
    lv.add_argument("--interval", type=float, default=45.0, help="فاصله‌ی به‌روزرسانی به ثانیه")
    lv.set_defaults(func=cmd_live)

    st = sub.add_parser("store", help="پایگاه داده‌ی محلی قیمت‌ها برای پژوهش (SQLite)")
    st.add_argument("action", choices=["update", "list"])
    st.add_argument("symbols", nargs="*", help="نمادها (پیش‌فرض: نمادهای portfolio.csv و watchlist.txt)")
    st.add_argument("--db", default="prices.sqlite")
    st.add_argument("--portfolio", default="", help="افزودن نمادهای این فایل پرتفو")
    st.add_argument("--watchlist", default="", help="افزودن نمادهای این فایل دیده‌بان")
    st.add_argument("--max-age", type=float, default=12.0, help="نمادی که کمتر از این ساعت پیش به‌روز شده دریافت نمی‌شود")
    st.add_argument("--force", action="store_true", help="دریافت دوباره حتی اگر به‌روز باشد")
    st.add_argument("--no-flows", action="store_true", help="بدون تاریخچه‌ی حقیقی/حقوقی")
    st.add_argument("--no-index", action="store_true", help="بدون تاریخچه‌ی شاخص کل")
    st.add_argument("--usd", action="store_true", help="ذخیره‌ی تاریخچه‌ی دلار آزاد (tgju)")
    st.add_argument("--market", action="store_true", help="افزودن سهم‌های پرمعامله‌ی کل بازار (دیده‌بان رسمی)")
    st.add_argument("--top", type=int, default=200, help="--market: تعداد سهم‌ها")
    st.add_argument("--macro", action="store_true", help="ذخیره‌ی محرک‌های کلان (دلار، تتر، طلا، سکه، بیت‌کوین، نفت)")
    st.add_argument("--drivers", default="drivers", help="--macro: پوشه‌ی CSV محرک‌های دلخواه")
    st.set_defaults(func=cmd_store)

    rs = sub.add_parser("research", help="پژوهش: کالیبراسیون امتیاز پتانسیل و آزمون تاریخی سیگنال‌ها")
    rs.add_argument("action", choices=["run", "calibrate", "signals"],
                    help="run: کل مسیر (دریافت داده، سنجش، آزمون، گزارش دقت) با لاگ کامل")
    rs.add_argument("--db", default="prices.sqlite")
    rs.add_argument("--top", type=int, default=200, help="run: تعداد سهم‌های پرمعامله‌ی بازار")
    rs.add_argument("--output", default="research-output", help="run: پوشه‌ی نتایج")
    rs.add_argument("--drivers", default="drivers", help="پوشه‌ی CSV محرک‌های کلان دلخواه (key.csv: date,value)")
    rs.add_argument("--skip-update", action="store_true", help="run: بدون دریافت داده، فقط با پایگاه داده‌ی موجود")
    rs.add_argument("--cash-rate", type=float, default=0.25,
                    help="run: سود سالانه‌ی صندوق درآمد ثابت (جابه‌جایی دارایی‌ها و هدف‌گذاری نوسان)")
    rs.add_argument("--short-only", action="store_true",
                    help="run: فقط آزمون‌های سودآوری (معامله‌ی سهام و جابه‌جایی بین بورس، طلا، دلار و درآمد ثابت)؛ "
                         "چند دقیقه به جای کل مسیر")
    rs.add_argument("--inflation", type=float, default=0.89, help="run: تورم سالانه برای مقایسه (پیش‌فرض 0.89)")
    rs.add_argument("--symbols", nargs="*", default=[], help="زیرمجموعه‌ای از نمادهای پایگاه داده")
    rs.add_argument("--horizon", type=int, default=0, help="افق به جلسه (پیش‌فرض: ۶۳ کالیبراسیون، ۴۲ سیگنال)")
    rs.add_argument("--min-history", type=int, default=260, help="حداقل جلسات تاریخچه پیش از ارزیابی")
    rs.add_argument("--out", default="", help="calibrate: فایل JSON ضرایب (مثلاً weights.json)")
    rs.add_argument("--workers", type=int, default=0, help="تعداد پردازش موازی (پیش‌فرض: هسته‌ها منهای یک)")
    rs.add_argument("--step", type=int, default=5, help="signals: فاصله‌ی بررسی قانون خرید به جلسه")
    rs.add_argument("--trades", default="", help="signals: ذخیره‌ی فهرست معاملات در CSV")
    rs.set_defaults(func=cmd_research)

    d = sub.add_parser("doctor", help="تست اتصال و ساختار پاسخ همه‌ی endpointها")
    d.add_argument("--symbol", default="فولاد")
    d.add_argument("--etf", default="اهرم", help="نماد یک صندوق برای بررسی NAV")
    d.add_argument("--out", default="diagnostics")
    d.set_defaults(func=cmd_doctor)

    w = sub.add_parser("serve", help="داشبورد وب محلی")
    w.add_argument("--host", default="127.0.0.1")
    w.add_argument("--port", type=int, default=8000)
    w.add_argument("--portfolio", default="portfolio.csv", help="فایل پرتفو")
    w.add_argument("--watchlist", default="watchlist.txt", help="فایل دیده‌بان رصد زنده")
    w.add_argument("--ledger", default="ledger.csv", help="دفتر معاملات (برای بازده واقعی)")
    w.add_argument("--live-interval", type=float, default=45.0, help="فاصله‌ی به‌روزرسانی رصد زنده (ثانیه)")
    w.set_defaults(func=cmd_serve)
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    client = TsetmcClient(timeout=args.timeout, cache_dir=args.cache_dir or None)
    try:
        return args.func(client, args)
    except TsetmcError as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
