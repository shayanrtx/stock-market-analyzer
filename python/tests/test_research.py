"""Research lab on a synthetic universe: deterministic random walks whose drift persists in long
regimes (the planted signal: trend factors predict forward returns)."""

import json
from datetime import datetime

import numpy as np
import pandas as pd
import pytest

from tse_analyzer.cli import main
from tse_analyzer.potential import FACTOR_NAMES, assess, trade_odds
from tse_analyzer.research import calibrate, load_index, load_universe, signals
from tse_analyzer.research.common import SymbolData, month_ends, score_at
from tse_analyzer.stats import rank, spearman, t_stat, wilson, wilson_rate
from tse_analyzer.store import PriceStore

TEDPIX_SERIES = "index:32097828799138957"


def synthetic(seed: int, n: int = 800, block: int = 250, start: str = "2019-01-01") -> pd.DataFrame:
    rng = np.random.default_rng(seed)
    drift = np.empty(n)
    d = rng.choice([-0.004, 0.004])
    for i in range(n):
        if i and i % block == 0 and rng.random() < 0.7:
            d = -d
        drift[i] = d
    close = 10_000 * np.cumprod(1 + drift + 0.012 * rng.standard_normal(n))
    prev = np.concatenate([[10_000.0], close[:-1]])
    high = np.maximum(close, prev) * (1 + np.abs(rng.normal(0, 0.004, n)))
    low = np.minimum(close, prev) * (1 - np.abs(rng.normal(0, 0.004, n)))
    volume = 1e6 * (1 + rng.random(n))
    return pd.DataFrame({"open": prev, "high": high, "low": low, "last": close, "close": close, "yesterday": prev,
                         "volume": volume, "value": volume * close, "trades": 100.0},
                        index=pd.DatetimeIndex(pd.bdate_range(start, periods=n), name="date"))


def flows(df: pd.DataFrame, seed: int) -> list[dict]:
    rng = np.random.default_rng(seed)
    return [{"recDate": int(d.strftime("%Y%m%d")), "buy_I_Volume": float(rng.integers(100, 1000)), "buy_I_Count": 10,
             "sell_I_Volume": float(rng.integers(100, 1000)), "sell_I_Count": 10, "buy_N_Volume": 50.0,
             "sell_N_Volume": 50.0} for d in df.index]


def build_store(path, symbols: int = 4, n: int = 800) -> PriceStore:
    store = PriceStore(path)
    for k in range(symbols):
        df = synthetic(k, n)
        store.save_history(str(1000 + k), df, f"نماد{k}")
        store.save_client_types(str(1000 + k), flows(df, k))
    store.save_series(TEDPIX_SERIES, synthetic(99, n, block=400)["close"])
    return store


@pytest.fixture(scope="module")
def lab(tmp_path_factory):
    store = build_store(tmp_path_factory.mktemp("lab") / "prices.sqlite")
    universe, index = load_universe(store), load_index(store)
    return universe, index, calibrate.calibrate(universe, index), signals.run(universe, index, step=10)


# ---------------------------------------------------------------- no look-ahead

def test_scores_at_a_date_ignore_future_data():
    raw = synthetic(5, 700)
    at = raw.index[449]
    short = SymbolData("1", "الف", raw.iloc[:450], flows(raw.iloc[:450], 1))
    # The future brings a 50% capital increase, wild prices and more money-flow records.
    future = raw.iloc[450:].copy()
    future[["open", "high", "low", "last", "close"]] *= 0.5
    future["yesterday"] = future["close"].shift().fillna(future["close"].iloc[0])
    future.iloc[0, future.columns.get_loc("yesterday")] = raw["close"].iloc[449] * 0.5
    longer = pd.concat([raw.iloc[:450], future])
    long = SymbolData("1", "الف", longer, flows(raw.iloc[:450], 1) + flows(future, 2))
    index = synthetic(9, 700)["close"]

    p_short, h_short = score_at(short, at, index.iloc[:450])
    p_long, h_long = score_at(long, at, index)
    assert p_short.score == p_long.score
    assert [(f.name, f.score) for f in p_short.factors] == [(f.name, f.score) for f in p_long.factors]
    pd.testing.assert_frame_equal(h_short, h_long)
    # ... whereas adjusting with the future event rescales the past.
    assert long.adjusted["close"].iloc[0] == pytest.approx(raw["close"].iloc[0] * 0.5)


def test_observations_unchanged_when_history_grows(lab, tmp_path):
    universe, index, cal, _ = lab
    trimmed = [SymbolData(s.ins_code, s.symbol, s.raw.iloc[:-100], s.flows) for s in universe]
    obs = calibrate.collect(trimmed, index.iloc[:-100])
    full = {(o.date, o.ins_code): o for o in cal.observations}
    assert obs and all(full[(o.date, o.ins_code)].score == o.score for o in obs)
    assert all(full[(o.date, o.ins_code)].factors == o.factors for o in obs)


# ------------------------------------------------------------------ calibration

def test_calibration_json_schema(lab, tmp_path):
    universe, _, cal, _ = lab
    data = cal.to_json()
    assert list(data) == ["version", "generated_at", "horizon_days", "universe", "observations", "factors",
                          "buckets", "ic"]
    assert data["version"] == 1 and data["horizon_days"] == 63 and data["universe"] == len(universe)
    assert datetime.fromisoformat(data["generated_at"]).tzinfo is not None
    assert data["observations"] == len(cal.observations) > 50
    assert list(data["factors"]) == list(FACTOR_NAMES)
    assert all(0.0 <= m <= 2.0 for m in data["factors"].values())
    assert data["factors"]["ارزش‌گذاری"] == 1.0  # no point-in-time EPS: never calibrated
    assert [b["range"] for b in data["buckets"]] == ["0-40", "40-50", "50-60", "60-70", "70-100"]
    assert sum(b["n"] for b in data["buckets"]) == data["observations"]
    for b in data["buckets"]:
        assert set(b) >= {"range", "n", "mean_fwd_pct", "median_fwd_pct", "hit_rate"}
        if b["n"]:
            lo, hi = b["hit_rate_ci"]
            assert lo <= b["hit_rate"] <= hi
    assert "score" in data["ic"] and set(data["ic"]) - {"score"} <= set(FACTOR_NAMES)
    json.dumps(data, allow_nan=False)  # strict JSON for PHP
    cal.save(tmp_path / "w.json")
    assert json.loads((tmp_path / "w.json").read_text(encoding="utf-8")) == data


def test_planted_signal_is_found(lab):
    universe, index, cal, _ = lab
    assert cal.target == "excess"
    assert cal.ic["score"] > 0.1
    buckets = [b for b in cal.buckets if b["n"] >= 10]
    assert buckets[-1]["mean_fwd_pct"] > buckets[0]["mean_fwd_pct"]
    # Observations only on Jalali month ends, never without a full forward window.
    ends = set(month_ends(pd.DatetimeIndex(index.index)))
    assert all(o.date in ends and o.date <= index.index[-64] for o in cal.observations)


def test_fit_multipliers_rules():
    n = {name: 500 for name in FACTOR_NAMES}
    ic = {name: None for name in FACTOR_NAMES}
    ic.update({"روند ثانویه": 0.2, "کشش قیمت": 0.1, "نقدشوندگی": -0.15, "رژیم نوسان": 0.02})
    m = calibrate.fit_multipliers(ic, n)
    assert m["روند ثانویه"] > 1 > m["کشش قیمت"]  # proportional to IC around the mean positive IC
    assert m["نقدشوندگی"] < 0.5  # significantly negative: toward 0
    assert m["رژیم نوسان"] == 1.0  # not significant: default
    assert m["ارزش‌گذاری"] == 1.0  # no data
    assert all(0 <= v <= 2 for v in m.values())
    few = calibrate.fit_multipliers({"روند ثانویه": 0.5}, {"روند ثانویه": 20})
    assert few["روند ثانویه"] == 1.0  # too few observations
    strong = calibrate.fit_multipliers({"روند ثانویه": 0.9, "کشش قیمت": 0.05}, {"روند ثانویه": 10_000, "کشش قیمت": 10_000})
    k, ref = 10_000 / (10_000 + calibrate.SHRINK_N), (0.9 + 0.05) / 2  # shrinkage and mean positive IC
    assert strong["روند ثانویه"] == round(1 + k * (0.9 / ref - 1), 3) == 1.877
    assert strong["کشش قیمت"] == round(1 + k * (0.05 / ref - 1), 3) == 0.123


def test_bucket_stats_by_hand():
    def obs(score, fwd):
        return calibrate.Observation(pd.Timestamp("2025-01-01"), "1", "x", score, {}, fwd, fwd - 1)
    b = {x["range"]: x for x in calibrate.bucket_stats([obs(30, -5), obs(39.9, 5), obs(40, 10), obs(75, 20),
                                                         obs(100, -2)])}
    assert b["0-40"]["n"] == 2 and b["0-40"]["mean_fwd_pct"] == 0 and b["0-40"]["hit_rate"] == 0.5
    assert b["40-50"]["n"] == 1 and b["70-100"]["n"] == 2 and b["50-60"]["n"] == 0
    assert b["50-60"]["mean_fwd_pct"] is None and b["70-100"]["median_fwd_pct"] == 9
    assert b["0-40"]["hit_rate_ci"] == [round(x, 4) for x in wilson(1, 2)]
    assert b["70-100"]["mean_excess_pct"] == 8


def test_month_ends_are_jalali():
    days = pd.bdate_range("2025-03-17", "2025-04-25")  # 1403/12/27 .. 1404/02/05
    ends = month_ends(days)
    assert ends[0] == pd.Timestamp("2025-03-20")  # 1403/12/30, last session of Esfand
    assert ends[1] == pd.Timestamp("2025-04-18")  # 1404/01/29 (Fri 04-18 is a bdate in this calendar)
    assert ends[-1] == days[-1]


# ---------------------------------------------------------------------- signals

def test_signal_simulation(lab):
    _, _, _, rep = lab
    s = rep.all
    assert s["trades"] >= 3 and 0 <= s["win_rate"] <= 1
    lo, hi = s["win_rate_ci"]
    assert lo <= s["win_rate"] <= hi
    assert s["expectancy_pct"] == pytest.approx(s["avg_return_pct"])
    for t in rep.trades:
        assert t.score >= signals.BUY_MIN_SCORE and t.reward_risk >= signals.MIN_REWARD_RISK
        assert t.odds_win >= signals.MIN_PROBABILITY
        if t.outcome == "stop":
            assert t.return_pct == pytest.approx(-t.stop_pct)
        if t.outcome == "target":
            assert t.return_pct == pytest.approx(t.target_pct)
    assert rep.filtered and all(t.regime_ok for t in rep.filtered)
    text = signals.report_text(rep)
    assert "نرخ برد" in text and "با فیلتر رژیم" in text


def test_follow_outcomes_by_hand():
    idx = pd.bdate_range("2025-01-01", periods=6)
    df = pd.DataFrame({"close": [100, 101, 102, 103, 104, 105.0], "high": [100, 102, 103, 111, 104, 106.0],
                       "low": [100, 99, 101, 102, 103, 104.0]}, index=idx)
    assert signals.follow(df, 0, 5, 10, 42) == ("target", 10, 3)
    assert signals.follow(df, 0, 0.5, 10, 42) == ("stop", -0.5, 1)  # day 1 low 99 < 99.5
    both = df.copy()
    both.loc[idx[1], ["high", "low"]] = [120, 90]
    assert signals.follow(both, 0, 5, 10, 42)[0] == "stop"  # touching both counts as a stop
    outcome, ret, days = signals.follow(df, 0, 50, 50, 3)
    assert outcome == "expired" and days == 3 and ret == pytest.approx(3.0)
    assert signals.follow(df, 0, 50, 50, 42)[0] == "open"
    t = signals.Trade("1", "x", idx[0], 70, 5, 10, 2, 0.5, True, "stop", -5, 1)
    assert t.r_multiple == -1


def test_trade_odds_on_a_steady_rise():
    idx = pd.bdate_range("2020-01-01", periods=400)
    close = pd.Series(100 * 1.002 ** np.arange(400), index=idx)
    df = pd.DataFrame({"close": close, "high": close * 1.001, "low": close * 0.999})
    odds = trade_odds(df, 5, 20, 42)
    assert odds["win_rate"] == 1 and odds["loss_rate"] == 0 and odds["expectancy_pct"] == pytest.approx(5)
    assert odds["win_rate_ci"][0] > 0.8 and odds["avg_days_to_target"] == pytest.approx(25, abs=1)
    down = trade_odds(df, 5, 20, 42, side=-1)
    assert down["win_rate"] == 0 and down["timeout_rate"] == 1


def test_analogs_report_wilson_intervals():
    df = synthetic(1, 900)
    a = assess(df).analogs
    lo, hi = a["win_rate_ci"]
    assert lo <= a["win_rate"] <= hi
    k = round(a["win_rate"] * a["samples"])
    assert (lo, hi) == wilson(k, a["samples"])
    from tse_analyzer.report import potential_text
    assert "بازه‌ی اطمینان ۹۵٪" in potential_text("x", assess(df))


# ------------------------------------------------------------------------ stats

def test_wilson_and_spearman():
    lo, hi = wilson(0, 10)
    assert lo == 0 and hi == pytest.approx(0.2775, abs=1e-4)
    lo, hi = wilson(5, 10)
    assert lo == pytest.approx(0.2366, abs=1e-4) and hi == pytest.approx(0.7634, abs=1e-4)
    assert wilson(1, 0) is None
    assert wilson_rate([True, False, True, True])["rate"] == 0.75
    assert list(rank([10, 20, 20, 5])) == [2, 3.5, 3.5, 1]
    assert spearman([1, 2, 3, 4], [10, 20, 30, 1000]) == pytest.approx(1)
    assert spearman([1, 2, 3, 4], [4, 3, 2, 1]) == pytest.approx(-1)
    assert spearman([1, 2, float("nan"), 4, 5], [1, 2, 3, 5, 4]) == pytest.approx(0.8)
    assert spearman([1, 1, 1], [1, 2, 3]) is None and spearman([1, 2], [1, 2]) is None
    assert t_stat(0.5, 18) == pytest.approx(0.5 * (16 / 0.75) ** 0.5)


# -------------------------------------------------------------------------- CLI

def test_cli_research(tmp_path, capsys):
    db = tmp_path / "prices.sqlite"
    build_store(db, symbols=2, n=650).close()
    out = tmp_path / "weights.json"
    assert main(["--cache-dir", "", "research", "calibrate", "--db", str(db), "--out", str(out)]) == 0
    assert "کالیبراسیون" in capsys.readouterr().out
    data = json.loads(out.read_text(encoding="utf-8"))
    assert data["version"] == 1 and data["universe"] == 2 and list(data["factors"]) == list(FACTOR_NAMES)
    trades = tmp_path / "trades.csv"
    assert main(["--cache-dir", "", "research", "signals", "--db", str(db), "--step", "20",
                 "--trades", str(trades)]) == 0
    assert "شبیه‌سازی تاریخی" in capsys.readouterr().out
    assert trades.read_text(encoding="utf-8").startswith("date,symbol")
    assert main(["--cache-dir", "", "research", "calibrate", "--db", str(tmp_path / "empty.sqlite")]) == 1


# --------------------------------------------------------- factor names / weights

def test_every_factor_name_is_registered_and_multipliers_apply(tmp_path):
    df = synthetic(2, 700)
    info = {"eps": {"epsValue": df["close"].iloc[-1] / 5, "sectorPE": 8}}
    flow = flows(df, 3)
    full = assess(df, info, None, flow, None, synthetic(4, 700)["close"])
    today_only = assess(df, {"eps": {"epsValue": -1}}, {"buyer_power": 1.8})
    names = {f.name for f in full.factors} | {f.name for f in today_only.factors}
    assert names <= set(FACTOR_NAMES) and len(names) >= 11
    # A zero multiplier removes a factor's influence; the others are rescaled.
    trend_name = "روند اولیه (Primary trend)"
    muted = assess(df, info, None, flow, None, synthetic(4, 700)["close"], multipliers={trend_name: 0.0})
    assert next(f for f in muted.factors if f.name == trend_name).contribution == 0
    assert abs(50 + sum(f.contribution for f in muted.factors) - muted.score) < 1e-9
    path = tmp_path / "weights.json"
    path.write_text(json.dumps({"version": 1, "factors": {trend_name: 0.0, "x": "bad"}}), encoding="utf-8")
    from tse_analyzer.potential import load_multipliers
    assert load_multipliers(path) == {trend_name: 0.0}
    assert load_multipliers(tmp_path / "missing.json") is None
