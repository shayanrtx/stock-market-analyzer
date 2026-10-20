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
from tse_analyzer.stats import newey_west, rank, spearman, t_stat, wilson, wilson_rate
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
    # 12 symbols: a month's IC needs at least calibrate.MIN_CROSS of them.
    store = build_store(tmp_path_factory.mktemp("lab") / "prices.sqlite", symbols=12, n=900)
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
    assert list(data)[:2] == ["version", "generated_at"] and data["version"] == 1
    assert {"accepted", "factors", "ic", "validation", "accuracy", "probability", "candidates"} <= set(data)
    assert data["horizon_days"] == 63 and data["universe"] == len(universe)
    assert datetime.fromisoformat(data["generated_at"]).tzinfo is not None
    assert data["observations"] == len(cal.observations) > 200
    assert list(data["factors"]) == list(FACTOR_NAMES)
    assert all(0.0 <= m <= 2.0 for m in data["factors"].values())
    assert data["factors"]["ارزش‌گذاری"] == 1.0  # no point-in-time EPS: never calibrated
    if not data["accepted"]:
        assert all(m == 1.0 for m in data["factors"].values()), "rejected weights must not change the app"
    buckets = data["accuracy"]["buckets"]
    assert [b["range"] for b in buckets] == ["0-40", "40-50", "50-60", "60-70", "70-100"]
    assert sum(b["n"] for b in buckets) == data["observations"]
    for b in buckets:
        if b["n"]:
            lo, hi = b["hit_rate_ci"]
            assert lo <= b["hit_rate"] <= hi
    assert set(data["ic"]) == {"score", *FACTOR_NAMES}
    json.dumps(data, allow_nan=False)  # strict JSON for PHP
    cal.save(tmp_path / "w.json")
    assert json.loads((tmp_path / "w.json").read_text(encoding="utf-8")) == json.loads(json.dumps(data))


def test_planted_signal_is_found(lab):
    universe, index, cal, _ = lab
    assert cal.target == "excess"
    assert cal.stats["score"]["mean"] > 0.1 and cal.stats["score"]["t"] > 2
    acc = cal.accuracy
    assert acc["quintile_spread_pct"]["mean"] > 0
    assert acc["high_score_hit"]["rate"] > acc["base_hit"]["rate"]
    buckets = [b for b in acc["buckets"] if b["n"] >= 10]
    assert buckets[-1]["mean_target_pct"] > buckets[0]["mean_target_pct"]
    # Observations only on Jalali month ends, never without a full forward window.
    ends = set(month_ends(pd.DatetimeIndex(index.index)))
    assert all(o.date in ends and o.date <= index.index[-64] for o in cal.observations)


def test_monthly_ic_and_newey_west():
    # Positively autocorrelated values: Newey-West widens the error, so t shrinks.
    x = list(np.repeat([0.1, -0.05, 0.12, 0.0, 0.08, -0.03], 4))
    plain, wide = newey_west(x, 0), newey_west(x, 3)
    assert plain["mean"] == pytest.approx(np.mean(x))
    assert plain["se"] == pytest.approx(np.std(x) / np.sqrt(len(x)))
    assert wide["se"] > plain["se"] and wide["t"] < plain["t"]
    assert newey_west([], 2)["t"] is None and newey_west([1.0, 2.0], 2)["se"] is None

    def obs(month, score, ret):
        return calibrate.Observation(pd.Timestamp(month), "1", "x", score, {}, ret, ret)
    rows = [obs("2025-01-31", s, s / 10) for s in range(10)] + [obs("2025-02-28", s, -s) for s in range(10)]
    rows += [obs("2025-03-31", s, s) for s in range(5)]  # too few symbols that month
    assert calibrate.monthly_ic(rows, "excess", lambda o: o.score) == [pytest.approx(1.0), pytest.approx(-1.0)]


def test_fit_multipliers_rules():
    stats = {name: {"mean": None, "t": None, "months": 0} for name in FACTOR_NAMES}
    stats.update({"روند ثانویه": {"mean": 0.2, "t": 3.0, "months": 60}, "کشش قیمت": {"mean": 0.1, "t": 2.5, "months": 60},
                  "نقدشوندگی": {"mean": -0.15, "t": -2.8, "months": 60}, "رژیم نوسان": {"mean": 0.05, "t": 1.0, "months": 60},
                  "انباشت / توزیع حجم": {"mean": 0.3, "t": 4.0, "months": 12}})
    m = calibrate.fit_multipliers(stats)
    assert m["روند ثانویه"] > 1 > m["کشش قیمت"]  # proportional to IC around the mean positive IC
    assert m["نقدشوندگی"] < 0.5  # significantly negative: toward 0
    assert m["رژیم نوسان"] == 1.0  # not significant
    assert m["انباشت / توزیع حجم"] == 1.0  # too few months
    assert m["ارزش‌گذاری"] == 1.0  # no data
    k, ref = 60 / (60 + calibrate.SHRINK_MONTHS), (0.2 + 0.1) / 2
    assert m["روند ثانویه"] == round(1 + k * (0.2 / ref - 1), 3)


def test_rescore_matches_assess():
    df = synthetic(2, 700)
    flow = flows(df, 3)
    bench = synthetic(4, 700)["close"]
    plain = assess(df, None, None, flow, None, bench)
    mult = {"روند اولیه (Primary trend)": 1.8, "کشش قیمت": 0.2}
    tuned = assess(df, None, None, flow, None, bench, multipliers=mult)
    o = calibrate.Observation(df.index[-1], "1", "x", plain.score, {f.name: f.score for f in plain.factors}, 0, 0,
                              {f.name: f.weight for f in plain.factors})
    assert calibrate.rescore(o, mult) == pytest.approx(tuned.score)
    assert calibrate.rescore(o, None) == plain.score


def test_out_of_sample_split_leaves_a_gap():
    months = pd.date_range("2020-01-31", periods=40, freq="ME")
    rows = [calibrate.Observation(m, str(k), "x", 50, {}, 0, 0) for m in months for k in range(3)]
    train, test, period = calibrate.split_months(rows, 63)
    train_months, test_months = sorted({o.date for o in train}), sorted({o.date for o in test})
    assert len(test_months) == 12  # 30% of 40
    gap = (test_months[0].to_period("M") - train_months[-1].to_period("M")).n
    assert gap == calibrate.lags_for(63) + 1  # three whole months in between: no training return reaches the test
    assert period["gap_months"] == 3


def test_probability_calibration_by_hand():
    rows = []
    for k in range(100):  # said 80%, and 80 of 100 rose
        rows.append(calibrate.Observation(pd.Timestamp("2025-01-31"), str(k), "x", 50, {}, 5 if k < 80 else -5, 0,
                                          prob_up=0.8))
    for k in range(100):  # said 30%, and 50 of 100 rose
        rows.append(calibrate.Observation(pd.Timestamp("2025-02-28"), str(k), "x", 50, {}, 5 if k < 50 else -5, 0,
                                          prob_up=0.3))
    pc = calibrate.probability_calibration(rows)
    assert pc["n"] == 200 and pc["base_rate"] == pytest.approx(0.65)
    assert pc["brier"] == pytest.approx((80 * 0.04 + 20 * 0.64 + 50 * 0.49 + 50 * 0.09) / 200)
    bins = {b["range"]: b for b in pc["bins"]}
    assert bins["0.7-1.0"]["actual"] == pytest.approx(0.8) and bins["0.0-0.4"]["actual"] == pytest.approx(0.5)
    assert pc["skill"] > 0


def test_bucket_stats_by_hand():
    def obs(score, fwd):
        return calibrate.Observation(pd.Timestamp("2025-01-01"), "1", "x", score, {}, fwd, fwd - 1)
    b = {x["range"]: x for x in calibrate.bucket_stats([obs(30, -5), obs(39.9, 5), obs(40, 10), obs(75, 20),
                                                         obs(100, -2)])}
    assert b["0-40"]["n"] == 2 and b["0-40"]["mean_fwd_pct"] == 0 and b["0-40"]["hit_rate"] == 0.5
    assert b["40-50"]["n"] == 1 and b["70-100"]["n"] == 2 and b["50-60"]["n"] == 0
    assert b["50-60"]["mean_fwd_pct"] is None and b["70-100"]["mean_target_pct"] == 8
    assert b["0-40"]["hit_rate_ci"] == [round(x, 4) for x in wilson(1, 2)]


def test_month_ends_are_jalali():
    days = pd.bdate_range("2025-03-17", "2025-04-25")  # 1403/12/27 .. 1404/02/05
    ends = month_ends(days)
    assert ends[0] == pd.Timestamp("2025-03-20")  # 1403/12/30, last session of Esfand
    assert ends[1] == pd.Timestamp("2025-04-18")  # 1404/01/29 (Fri 04-18 is a bdate in this calendar)
    assert ends[-1] == days[-1]


# ---------------------------------------------------------------------- signals

def test_signal_simulation(lab):
    _, _, _, rep = lab
    s = rep.site
    assert s["trades"] >= 3 and 0 <= s["win_rate"] <= 1
    lo, hi = s["win_rate_ci"]
    assert lo <= s["win_rate"] <= hi
    assert s["expectancy_pct"] == pytest.approx(s["avg_return_pct"])
    for t in rep.trades:
        min_score, min_prob = signals.production_thresholds(t.regime)
        assert t.score >= min_score and t.reward_risk >= signals.MIN_REWARD_RISK and t.odds_win >= min_prob
        if t.outcome == "stop":
            assert t.return_pct == pytest.approx(-t.stop_pct)
        if t.outcome == "target":
            assert t.return_pct == pytest.approx(t.target_pct)
    assert rep.plain and len(rep.plain) >= len(rep.trades) - 2  # the site rule only adds conditions
    text = signals.report_text(rep)
    assert "سودده" in text and "قانون فعلی سایت" in text and "قانون ساده‌ی قبلی" in text


def test_site_rule_matches_the_php_signal_engine():
    c = {"score": 65.0, "expectancy_pct": 2.0, "odds_win": 0.5, "odds_loss": 0.3, "win_low": 0.42, "reward_risk": 2.0}
    assert signals.passes(c, "bull") and signals.passes(c, "neutral")
    assert not signals.passes(c, "bear")  # bear market: score 70 and 55%
    assert not signals.passes(dict(c, win_low=0.30), "bull")  # interval below break-even 1/(1+2)
    assert signals.passes(dict(c, win_low=0.30), "bull", site_rule=False)  # the previous rule had no such check
    assert not signals.passes(dict(c, odds_win=0.29), "bull", site_rule=False)
    assert not signals.passes(None, "bull")
    states = pd.Series(["bull", "bear"], index=pd.to_datetime(["2025-01-01", "2025-02-01"]))
    assert signals.state_at(states, pd.Timestamp("2025-01-20")) == "bull"
    assert signals.state_at(states, pd.Timestamp("2025-03-01")) == "bear"
    assert signals.state_at(states, pd.Timestamp("2024-12-01")) is None


def test_production_regime_states():
    from tse_analyzer.regime import production_states
    idx = pd.bdate_range("2023-01-01", periods=320)
    up = production_states(pd.Series(1000 + 3 * np.arange(320.0), index=idx))
    down = production_states(pd.Series(3000 - 4 * np.arange(320.0), index=idx))
    assert up.iloc[0] == "unknown" and up.iloc[-1] == "bull" and down.iloc[-1] == "bear"
    crash = np.concatenate([1000 + 3 * np.arange(300.0), 1900 * (1 - 0.015 * np.arange(1, 21))])
    assert production_states(pd.Series(crash, index=idx)).iloc[-1] == "bear"  # 20%+ below the high


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
    assert main(["--cache-dir", "", "research", "calibrate", "--db", str(db), "--out", str(out), "--workers", "1"]) == 0
    assert "کالیبراسیون" in capsys.readouterr().out
    data = json.loads(out.read_text(encoding="utf-8"))
    assert data["version"] == 1 and data["universe"] == 2 and list(data["factors"]) == list(FACTOR_NAMES)
    trades = tmp_path / "trades.csv"
    assert main(["--cache-dir", "", "research", "signals", "--db", str(db), "--step", "20", "--workers", "1",
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
