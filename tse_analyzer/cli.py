"""Command line entry point: ``tse-analyzer {search,analyze,market}``."""

from __future__ import annotations

import argparse
import json
import sys

from . import report
from .analysis import analyze
from .client import FLOW_BOURSE, FLOW_FARABOURSE, TsetmcClient, TsetmcError


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
    for symbol in args.symbols:
        inst = client.resolve(symbol)
        ins_code = str(inst["insCode"])
        history = client.daily_history(ins_code, args.days)
        if history.empty:
            print(f"{symbol}: تاریخچه‌ی قیمت پیدا نشد", file=sys.stderr)
            continue
        price_info = _optional(client.closing_price_info, ins_code)
        client_type = _optional(client.client_type, ins_code)
        result = analyze(
            inst.get("lVal18AFC", symbol), inst.get("lVal30", ""), history, client_type, price_info
        )
        if args.json:
            print(json.dumps(report.stock_dict(result), ensure_ascii=False, indent=2))
        else:
            print(report.stock_text(result))
            print("-" * 60)
    return 0


def cmd_market(client: TsetmcClient, args: argparse.Namespace) -> int:
    flow = FLOW_FARABOURSE if args.farabourse else FLOW_BOURSE
    indices = client.indices(flow, selected_only=not args.all)
    effects = _optional(client.index_effect, flow, args.top) or []
    if args.json:
        print(json.dumps({"indices": indices, "effects": effects}, ensure_ascii=False, indent=2))
    else:
        print(report.indices_text(indices, effects))
    return 0


def _optional(fn, *args):
    """Supplementary data must not abort the analysis (e.g. halted symbols return 500)."""
    try:
        return fn(*args)
    except TsetmcError as exc:
        print(f"warning: {exc}", file=sys.stderr)
        return {}


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
