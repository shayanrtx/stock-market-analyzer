"""Connectivity and schema check against the live APIs.

Run from an Iranian connection: ``tse-analyzer doctor``. It calls every endpoint the
analyzer depends on, checks that the fields the code reads are present, and saves a
small sample of each raw response under ``diagnostics/`` so a mismatch can be fixed
without access to the API.
"""

from __future__ import annotations

import json
from dataclasses import asdict, dataclass, field as dc_field
from datetime import date
from pathlib import Path
from typing import Any, Callable
from urllib.parse import quote

from .client import FLOW_BOURSE, TEDPIX, TseGatewayClient, TsetmcClient, TsetmcError
from .fx import USD, TgjuClient

SAMPLE_ITEMS = 3


@dataclass
class Probe:
    name: str
    fetch: Callable[[], Any]
    expected: list[str]  # "a|b": either name satisfies the check
    list_key: str | None = None  # key holding the record list, if not top-level


@dataclass
class ProbeResult:
    name: str
    status: str  # OK, EMPTY, MISSING_FIELDS, ERROR
    records: int = 0
    missing: list[str] = dc_field(default_factory=list)
    error: str = ""


def build_probes(cdn: TsetmcClient, gw: TseGatewayClient, symbol: str, fx: TgjuClient | None = None,
                 etf_symbol: str = "اهرم") -> list[Probe]:
    state: dict[str, str] = {}

    def ins_code() -> str:
        if "ins" not in state:
            state["ins"] = str(cdn.resolve(symbol)["insCode"])
        return state["ins"]

    def etf_code() -> str:
        if "etf" not in state:
            state["etf"] = str(cdn.resolve(etf_symbol)["insCode"])
        return state["etf"]

    extra = [Probe("tgju_usd", lambda: fx.get(f"market/indicator/summary-table-data/{USD}"), [], "data")] if fx else []
    return [
        Probe("cdn_search", lambda: cdn.get(f"Instrument/GetInstrumentSearch/{quote(symbol)}"),
              ["insCode", "lVal18AFC", "lVal30"], "instrumentSearch"),
        Probe("cdn_daily_history", lambda: cdn.get(f"ClosingPrice/GetClosingPriceDailyList/{ins_code()}/30"),
              ["dEven", "priceFirst", "priceMax", "priceMin", "pClosing", "pDrCotVal",
               "priceYesterday", "qTotTran5J", "qTotCap", "zTotTran"], "closingPriceDaily"),
        Probe("cdn_closing_price_info", lambda: cdn.get(f"ClosingPrice/GetClosingPriceInfo/{ins_code()}"),
              ["pClosing", "pDrCotVal", "instrumentState"], "closingPriceInfo"),
        Probe("cdn_client_type", lambda: cdn.get(f"ClientType/GetClientType/{ins_code()}/1/0"),
              ["buy_I_Volume", "buy_CountI", "sell_I_Volume", "sell_CountI",
               "buy_N_Volume", "sell_N_Volume"], "clientType"),
        Probe("cdn_client_type_all", lambda: cdn.get("ClientType/GetClientTypeAll"),
              ["insCode", "buy_I_Volume", "buy_CountI", "sell_I_Volume", "sell_CountI"],
              "clientTypeAllDto"),
        Probe("cdn_instrument_info", lambda: cdn.get(f"Instrument/GetInstrumentInfo/{ins_code()}"),
              ["lVal18AFC", "lVal30", "sector", "eps", "zTitad"], "instrumentInfo"),
        Probe("cdn_client_type_history", lambda: cdn.get(f"ClientType/GetClientTypeHistory/{ins_code()}"),
              ["recDate", "buy_I_Volume", "buy_I_Count|buy_CountI", "sell_I_Volume", "sell_I_Count|sell_CountI",
               "buy_N_Volume"], "clientType"),
        Probe("cdn_best_limits", lambda: cdn.get(f"BestLimits/{ins_code()}"),
              ["number", "pMeDem", "pMeOf", "zOrdMeDem", "zOrdMeOf", "qTitMeDem", "qTitMeOf"],
              "bestLimits"),
        Probe("cdn_indices", lambda: cdn.get(f"Index/GetIndexB1LastAll/SelectedIndexes/{FLOW_BOURSE}"),
              ["insCode", "lVal30", "xDrNivJIdx004", "xVarIdxJRfV"], "indexB1"),
        Probe("cdn_index_effect", lambda: cdn.get(f"Index/GetInstEffect/0/{FLOW_BOURSE}/7"),
              ["insCode", "instrument", "instEffectValue"], "instEffect"),
        Probe("cdn_index_history", lambda: cdn.get(f"Index/GetIndexB2History/{TEDPIX}"),
              ["dEven", "xNivInuClMresIbs"], "indexB2"),
        Probe("cdn_static_threshold",
              lambda: cdn.get(f"MarketData/GetStaticThreshold/{ins_code()}/{date.today():%Y%m%d}"),
              ["psGelStaMax", "psGelStaMin"], "staticThreshold"),
        Probe("cdn_supervisor_msg", lambda: cdn.get(f"Msg/GetMsgByInsCode/{ins_code()}"),
              ["dEven", "hEven", "tseTitle", "tseDesc"], "msg"),
        Probe("cdn_codal", lambda: cdn.get(f"Codal/GetPreparedDataByInsCode/20/{ins_code()}"),
              ["title|letterTitle", "symbol", "publishDateTime_Gregorian|publishDateTime|sentDateTime", "tracingNo"],
              "preparedData"),
        Probe("cdn_etf_nav", lambda: cdn.get(f"Fund/GetETFByInsCode/{etf_code()}"),
              ["pRedTran|navRed|redemptionNav", "pSubTran|navSub|subscriptionNav"],
              "etf"),
        Probe("cdn_funds", lambda: cdn.get("Fund/GetFunds/6"),
              ["mfName", "regNo", "navRed", "navSub", "navStat", "netAsset", "day30Return", "day365Return",
               "portfolioStock", "portfolioBond", "portfolioDeposit", "portfolioCash"], "funds"),
        Probe("cdn_treasury_search", lambda: cdn.get(f"Instrument/GetInstrumentSearch/{quote('اخزا')}"),
              ["insCode", "lVal18AFC", "lVal30", "cIsin"], "instrumentSearch"),
        Probe("gw_market_date", lambda: gw.get("api/v1/PublicData/MarketDate/fa"),
              ["stockTypeId", "isOpen"]),
        Probe("gw_market_summary", lambda: gw.get("InstrumentProvider/api/v1/Instrument/MarketSummary/fa"),
              ["allShareIndex", "allShareIndexChangePercent", "tradeValue"]),
        Probe("gw_market_watch_cash",
              lambda: gw.get("InstrumentProvider/api/v1/MarketWatch/MarketWatchCash/fa"),
              ["instrumentId", "instrumentName", "closingPriceChangePercent|closingPricePercent",
               "lastPriceChangePercent|lastPricePercent", "tradeValue", "industryName", "securitiesname"], "Items"),
    ] + extra


def run_probe(probe: Probe, out_dir: Path | None) -> ProbeResult:
    try:
        data = probe.fetch()
    except (TsetmcError, ValueError) as exc:
        return ProbeResult(probe.name, "ERROR", error=str(exc))

    payload = data.get(probe.list_key) if probe.list_key and isinstance(data, dict) else data
    records = payload if isinstance(payload, list) else ([payload] if payload else [])
    if out_dir:
        sample = records[:SAMPLE_ITEMS]
        (out_dir / f"{probe.name}.json").write_text(
            json.dumps({"records": len(records), "sample": sample, "top_level_keys":
                        list(data) if isinstance(data, dict) else None},
                       ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
    if not records:
        return ProbeResult(probe.name, "EMPTY")
    if not isinstance(records[0], dict):  # e.g. tgju rows are plain lists
        return ProbeResult(probe.name, "OK" if not probe.expected else "EMPTY", len(records))
    keys = {k.lower() for k in records[0]}
    missing = [f for f in probe.expected if not any(alt.lower() in keys for alt in f.split("|"))]
    return ProbeResult(probe.name, "MISSING_FIELDS" if missing else "OK", len(records), missing)


def run(symbol: str = "فولاد", out_dir: str | Path = "diagnostics", timeout: float = 20.0,
        etf_symbol: str = "اهرم") -> list[ProbeResult]:
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    cdn = TsetmcClient(timeout=timeout, max_retries=1)
    gw = TseGatewayClient(timeout=timeout, max_retries=1)
    fx = TgjuClient(timeout=timeout, max_retries=1)
    results = [run_probe(p, out) for p in build_probes(cdn, gw, symbol, fx, etf_symbol)]
    (out / "report.json").write_text(
        json.dumps([asdict(r) for r in results], ensure_ascii=False, indent=2), encoding="utf-8"
    )
    return results


def format_results(results: list[ProbeResult]) -> str:
    lines = []
    for r in results:
        extra = r.error or (f"missing: {', '.join(r.missing)}" if r.missing else "")
        lines.append(f"{r.status:<15} {r.name:<26} {r.records:>6}  {extra}")
    ok = sum(r.status == "OK" for r in results)
    lines.append(f"\n{ok}/{len(results)} OK")
    return "\n".join(lines)
