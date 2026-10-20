"""Macro drivers, candidate ideas, market studies, the research universe and the one-command run."""

import json

import numpy as np
import pandas as pd
import pytest

from tse_analyzer.research import calibrate, macro, market, pipeline
from tse_analyzer.research.candidates import price_candidates
from tse_analyzer.research.common import SymbolData
from tse_analyzer.research.universe import market_universe

from test_research import build_store


def _frame(close: np.ndarray, days: pd.DatetimeIndex) -> pd.DataFrame:
    prev = np.concatenate([[close[0]], close[:-1]])
    return pd.DataFrame({"open": prev, "high": np.maximum(close, prev) * 1.003, "low": np.minimum(close, prev) * 0.997,
                         "last": close, "close": close, "yesterday": prev, "volume": 1e6, "value": 1e10, "trades": 100.0},
                        index=pd.DatetimeIndex(days, name="date"))


def lagged_market(lag_days: int | None, seed: int = 3, symbols: int = 20, n: int = 1300):
    """Shares that follow the dollar ``lag_days`` later (None: unrelated to it)."""
    rng = np.random.default_rng(seed)
    days = pd.bdate_range("2019-01-01", periods=n)
    usd_r = 0.01 * rng.standard_normal(n)
    usd = pd.Series(500_000 * np.cumprod(1 + usd_r), index=days)
    universe = []
    for k in range(symbols):
        beta = rng.uniform(-0.5, 1.5)
        driven = np.zeros(n) if lag_days is None else np.concatenate([np.zeros(lag_days), usd_r[:-lag_days]])
        r = 0.6 * beta * driven + 0.012 * rng.standard_normal(n)
        universe.append(SymbolData(str(k), f"s{k}", _frame(10_000 * np.cumprod(1 + r), days), []))
    index = pd.Series(10_000 * np.cumprod(1 + 0.008 * rng.standard_normal(n)), index=days)
    return universe, index, usd


# ------------------------------------------------------------------------ macro

def test_a_delayed_reaction_to_the_dollar_is_found_and_beats_the_placebos():
    universe, index, usd = lagged_market(15)
    cal = calibrate.calibrate(universe, index, drivers={"usd": usd})
    x = cal.candidates["macro:usd"]
    assert x["mean"] > 0 and x["t"] >= calibrate.CANDIDATE_T and x["stable"]
    assert x["placebo_p"] <= 0.05 and x["passed"]


def test_an_unrelated_driver_is_rejected():
    universe, index, usd = lagged_market(None)
    cal = calibrate.calibrate(universe, index, drivers={"usd": usd})
    assert not cal.candidates["macro:usd"]["passed"]
    assert not cal.candidates["macro:total"]["passed"]


def test_pending_effect_is_zero_for_an_instant_reaction_and_positive_after_a_jump():
    days = pd.bdate_range("2020-01-01", periods=900)
    rng = np.random.default_rng(1)
    usd_r = 0.01 * rng.standard_normal(900)
    usd_r[-10:] = 0.03  # the dollar has just jumped
    usd = pd.Series(np.cumprod(1 + usd_r) * 100, index=days)
    instant = pd.Series(np.cumprod(1 + usd_r + 0.001 * rng.standard_normal(900)) * 100, index=days)
    lag = np.concatenate([np.zeros(10), usd_r[:-10]])
    delayed = pd.Series(np.cumprod(1 + lag + 0.001 * rng.standard_normal(900)) * 100, index=days)
    at = days[-1]
    now = macro.symbol_macro(instant, {"usd": usd}).at({"usd": usd}, at)["macro:usd"]
    later = macro.symbol_macro(delayed, {"usd": usd}).at({"usd": usd}, at)["macro:usd"]
    assert abs(now) < 2.0  # nothing left to catch up (percent)
    assert later > 5.0  # a two-week lag: most of the jump is still to come
    # Point in time: data after ``at`` doesn't change the value at ``at``.
    cut = days[600]
    full = macro.symbol_macro(delayed, {"usd": usd}).at({"usd": usd}, cut)
    short = macro.symbol_macro(delayed.loc[:cut], {"usd": usd.loc[:cut]}).at({"usd": usd.loc[:cut]}, cut)
    assert full["macro:usd"] == pytest.approx(short["macro:usd"])


def test_placebo_rotation_keeps_the_moves_but_not_the_timing():
    s = pd.Series(np.cumprod(1 + 0.01 * np.random.default_rng(2).standard_normal(800)) * 100,
                  index=pd.bdate_range("2020-01-01", periods=800))
    r = s.pct_change().dropna().to_numpy()
    rot = macro.shifted(s, 400).pct_change().dropna().to_numpy()
    assert sorted(np.round(r, 12)) == sorted(np.round(rot, 12))
    assert not np.allclose(r, rot)
    shifts = macro.placebo_shifts(s)
    assert len(shifts) == macro.PLACEBOS and min(shifts) >= macro.MIN_SHIFT_DAYS


def test_driver_csv_accepts_jalali_and_gregorian_dates(tmp_path):
    path = tmp_path / "oil.csv"
    path.write_text("date,value\n1404/07/28,۶۵٫۵\n2025-10-21,66\n20251022,\"1,067\"\nbad,1\n", encoding="utf-8")
    s = macro.read_csv(path)
    assert list(s.index.strftime("%Y-%m-%d")) == ["2025-10-20", "2025-10-21", "2025-10-22"]
    assert list(s) == [65.5, 66.0, 1067.0]


def test_fetch_all_prefers_csv_and_reports_failures(tmp_path, monkeypatch):
    days = pd.bdate_range("2023-01-01", periods=100)
    (tmp_path / "oil.csv").write_text("\n".join(f"{d:%Y-%m-%d},{70 + i * 0.1}" for i, d in enumerate(days)),
                                      encoding="utf-8")

    def fake(provider, ident, timeout=20.0, cache_dir=None):
        if (provider, ident) == ("tgju", "price_dollar_rl"):
            return pd.Series(np.arange(100.0) + 1, index=days)
        raise ValueError("unreachable")
    monkeypatch.setattr(macro, "fetch", fake)
    series, results = macro.fetch_all(drivers_dir=tmp_path)
    by_key = {r.key: r for r in results}
    assert set(series) == {"usd", "oil"}
    assert by_key["oil"].source.startswith("csv:") and by_key["oil"].verified
    assert by_key["usd"].source == "tgju:price_dollar_rl" and by_key["usd"].verified
    assert by_key["btc"].source == "" and by_key["btc"].errors


# ------------------------------------------------------------------- candidates

def test_price_candidates_by_hand():
    days = pd.bdate_range("2020-01-01", periods=300)
    close = pd.Series(np.linspace(100, 199, 300), index=days)
    raw = _frame(close.to_numpy(), days)
    raw.iloc[-2:, raw.columns.get_loc("close")] = raw["yesterday"].iloc[-2:] * 1.05  # two limit-up days
    c = price_candidates(close, raw, days[-1])
    assert c["mom_12_1"] == pytest.approx(close.iloc[-22] / close.iloc[-253] - 1)
    assert c["reversal_1m"] == pytest.approx(-(close.iloc[-1] / close.iloc[-22] - 1))
    assert c["high_52w"] == pytest.approx(1.0)
    assert c["low_vol"] < 0
    assert c["limit_days"] == 2
    assert "mom_12_1" not in price_candidates(close.iloc[:200], raw.iloc[:200], days[199])


# --------------------------------------------------------------- market studies

def test_market_studies():
    days = pd.bdate_range("2019-03-21", periods=1500)
    rng = np.random.default_rng(5)
    index = pd.Series(1000 * np.cumprod(1 + 0.01 * rng.standard_normal(1500)), index=days)
    seasonal = market.seasonality(index)
    assert len(seasonal) == 12 and {x["label"] for x in seasonal} >= {"فروردین", "اسفند"}
    assert all(x["years"] >= 4 for x in seasonal)
    vt = market.volatility_targeting(index, cash_rate=0.2)
    assert 0 < vt["avg_exposure"] <= 1 and vt["targeted"]["vol_pct"] <= vt["hold"]["vol_pct"] + 1e-9
    usd = pd.Series(np.linspace(100, 200, 1500), index=days)
    d = market.index_in_dollars(index, usd)
    assert d["usd_change_pct"] == pytest.approx(100.0)
    assert d["usd_pct"] == pytest.approx(((1 + d["rial_pct"] / 100) / 2 - 1) * 100)


def test_jalali_month_returns():
    days = pd.date_range("2025-03-01", "2025-06-30")  # Esfand 1403 .. Tir 1404
    m = market.jalali_month_returns(pd.Series(np.arange(len(days)) + 100.0, index=days))
    assert list(m["month"]) == [1, 2, 3]  # Esfand has no previous close; Tir is still in progress
    assert (m["ret"] > 0).all()


# ---------------------------------------------------------------------- universe

class FakeGateway:
    """Rows shaped like the live MarketWatchCash answer (1405/07)."""

    def market_watch(self, kind="Cash"):
        rows = [
            ("فولاد", "IRO1FOLD0001", "فلزات اساسی", "بورس", "بازار اول - فرعی", "سهام عادی", 900),
            ("اهرم", "IRT1AHRM0001", "صندوق سرمایه گذاری", "بورس", None, "", 990),             # fund
            ("ح .فولاد", "IRR1FOLD0101", "فلزات اساسی", "بورس", None, "حق تقدم", 800),         # rights
            ("اخزا4024", "IRB3TB740001", "اوراق", "فرابورس", None, "", 700),                     # bond
            ("شپنا", "IRO1NAFT0001", "فراورده‌های نفتی", "بورس", "بازار اول - فرعی", "سهام عادي", 500),
            ("آ س پ", "IRO3ASPZ0001", "انبوه سازی، املاک و مستغلات", "فرابورس", None, "سهام عادی", 400),
            ("پایه۱", "IRO3XXXX0001", "متفرقه", "فرابورس", "بازار پایه زرد", "سهام عادی", 999),  # base market
            ("نماد", "IRO1XXXX0002", "متفرقه", "بورس", None, "سهام ممتاز", 950),               # not ordinary
            ("فولاد", "IRO1FOLD0001", "فلزات اساسی", "بورس", "بازار اول - فرعی", "سهام عادی", 10),  # duplicate
        ]
        return [{"instrumentName": s, "instrumentId": i, "companyNamePersian": s, "industryname": ind + " " * 8,
                 "marketname": m, "markettypeid": "NO", "markettypename": "نرمال", "boardName": board,
                 "securitiesid": "O", "securitiesname": kind, "tradeValue": {"value": v, "state": 0}}
                for s, i, ind, m, board, kind, v in rows]


def test_market_universe_keeps_ordinary_shares_by_traded_value():
    top = market_universe(FakeGateway(), 10)
    assert [c.symbol for c in top] == ["فولاد", "شپنا", "آ س پ"]
    assert top[0].industry == "فلزات اساسی"  # the live name is padded with spaces
    assert [c.symbol for c in market_universe(FakeGateway(), 1)] == ["فولاد"]


# ---------------------------------------------------------------------- pipeline

def test_offline_run_writes_the_report_and_log(tmp_path, monkeypatch):
    db = tmp_path / "prices.sqlite"
    store = build_store(db, symbols=10, n=900)
    days = store.raw("1000").index
    store.save_series("macro:usd", pd.Series(np.cumprod(1 + 0.01 * np.random.default_rng(8).standard_normal(len(days))),
                                             index=days))
    store.close()
    monkeypatch.chdir(tmp_path)
    assert pipeline.run(db=str(db), out="out", skip_update=True, workers=1) == 0
    folder = next((tmp_path / "out").iterdir())
    names = {p.name for p in folder.iterdir()}
    assert {"research.log", "report.txt", "summary.json", "observations.csv", "trades.csv"} <= names
    assert ("weights.json" in names) != ("weights.rejected.json" in names)
    report = (folder / "report.txt").read_text(encoding="utf-8")
    for part in ("خلاصه‌ی دقت", "۱) امتیاز پتانسیل رشد", "۲) «احتمال رشد ۳ ماهه»", "۳) سیگنال خرید", "۴) وزن‌های کالیبره",
                 "۵) ایده‌های آزمایشی", "۶) بازار", "باد موافق دلار", "دارونما"):
        assert part in report, part
    log = (folder / "research.log").read_text(encoding="utf-8")
    assert "مرحله‌ی 4" in log and "پایان در" in log
    summary = json.loads((folder / "summary.json").read_text(encoding="utf-8"))
    assert summary["symbols"] == 10 and "site" in summary["signals"] and "seasonality" in summary["studies"]


def test_offline_run_on_an_empty_store_fails_clearly(tmp_path, monkeypatch):
    monkeypatch.chdir(tmp_path)
    assert pipeline.run(db=str(tmp_path / "empty.sqlite"), out="out", skip_update=True, workers=1) == 3
    log = next((tmp_path / "out").iterdir()) / "research.log"
    assert "هیچ نمادی" in log.read_text(encoding="utf-8")
