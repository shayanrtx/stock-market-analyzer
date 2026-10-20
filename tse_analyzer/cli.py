"""Command line entry point: ``tse-analyzer {search,analyze,market,screen,portfolio,doctor,serve}``."""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import asdict

from . import doctor, portfolio, report, screener
from .analysis import fetch_and_analyze
from .client import FLOW_BOURSE, FLOW_FARABOURSE, TseGatewayClient, TsetmcClient, TsetmcError


def cmd_search(client: TsetmcClient, args: argparse.Namespace) -> int:
    results = client.search(args.query)
    if args.json:
        print(json.dumps(results, ensure_ascii=False, indent=2))
        return 0
    for item in results:
        print(f"{item.get('lVal18AFC', ''):<14} {item.get('insCode', ''):<20} "
              f"{item.get('flowTitle', ''):<10} {item.get('lVal30', '')}")
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


def cmd_market(client: TsetmcClient, args: argparse.Namespace) -> int:
    flow = FLOW_FARABOURSE if args.farabourse else FLOW_BOURSE
    indices = client.indices(flow, selected_only=not args.all)
    effects = _optional(client.index_effect, flow, args.top) or []
    if args.json:
        print(json.dumps({"indices": indices, "effects": effects}, ensure_ascii=False, indent=2))
    else:
        print(report.indices_text(indices, effects))
    return 0


def cmd_screen(client: TsetmcClient, args: argparse.Namespace) -> int:
    if args.movers:
        gw = TseGatewayClient(timeout=args.timeout, cache_dir=args.cache_dir or None)
        rows = screener.top_movers(gw, by=args.by, top=args.top, ascending=args.losers)
    else:
        rows = screener.smart_money(client, top=args.top, min_power=args.min_power)
    if args.json:
        print(json.dumps([asdict(r) for r in rows], ensure_ascii=False, indent=2))
    else:
        print(report.screen_text(rows))
    return 0


def cmd_portfolio(client: TsetmcClient, args: argparse.Namespace) -> int:
    holdings = portfolio.load_holdings(args.file)
    if not holdings:
        print(f"پرتفو خالی است. فایل {args.file} را بسازید، هر خط: نماد تعداد [میانگین خرید به ریال]\n"
              "مثال:\nفملی\t17673\nخودران\t10000\t25000", file=sys.stderr)
        return 1
    result = portfolio.analyze_portfolio(client, holdings)
    if args.json:
        print(json.dumps(asdict(result), ensure_ascii=False, indent=2))
    else:
        print(report.portfolio_text(result))
    return 0


def cmd_doctor(client: TsetmcClient, args: argparse.Namespace) -> int:
    results = doctor.run(args.symbol, args.out, args.timeout)
    print(doctor.format_results(results))
    print(f"\nپاسخ‌های نمونه در پوشه‌ی {args.out}/ ذخیره شد.")
    return 0 if all(r.status == "OK" for r in results) else 1


def cmd_serve(client: TsetmcClient, args: argparse.Namespace) -> int:
    from . import web

    web.serve(args.host, args.port, client,
              TseGatewayClient(timeout=args.timeout, cache_dir=args.cache_dir or None),
              args.portfolio)
    return 0


def _optional(fn, *args):
    """Supplementary data must not abort the command."""
    try:
        return fn(*args)
    except TsetmcError as exc:
        _warn(str(exc))
        return {}


def _warn(message: str) -> None:
    print(f"warning: {message}", file=sys.stderr)


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="tse-analyzer", description="تحلیلگر بورس تهران (TSETMC)")
    p.add_argument("--json", action="store_true", help="خروجی JSON")
    p.add_argument("--cache-dir", default=".cache", help="پوشه‌ی کش پاسخ‌ها ('' برای غیرفعال)")
    p.add_argument("--timeout", type=float, default=20.0)
    sub = p.add_subparsers(dest="command", required=True)

    s = sub.add_parser("search", help="جستجوی نماد")
    s.add_argument("query")
    s.set_defaults(func=cmd_search)

    a = sub.add_parser("analyze", help="تحلیل تکنیکال و حقیقی/حقوقی یک یا چند نماد")
    a.add_argument("symbols", nargs="+", help="نماد (مثلاً فولاد) یا insCode")
    a.add_argument("--days", type=int, default=400, help="تعداد روزهای تاریخچه (0 = همه)")
    a.set_defaults(func=cmd_analyze)

    m = sub.add_parser("market", help="وضعیت شاخص‌ها و نمادهای اثرگذار")
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

    pf = sub.add_parser("portfolio", help="تحلیل پرتفو")
    pf.add_argument("--file", default="portfolio.csv", help="فایل پرتفو (هر خط: نماد تعداد [میانگین خرید])")
    pf.set_defaults(func=cmd_portfolio)

    d = sub.add_parser("doctor", help="تست اتصال و ساختار پاسخ همه‌ی endpointها")
    d.add_argument("--symbol", default="فولاد")
    d.add_argument("--out", default="diagnostics")
    d.set_defaults(func=cmd_doctor)

    w = sub.add_parser("serve", help="داشبورد وب محلی")
    w.add_argument("--host", default="127.0.0.1")
    w.add_argument("--port", type=int, default=8000)
    w.add_argument("--portfolio", default="portfolio.csv", help="فایل پرتفو")
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
