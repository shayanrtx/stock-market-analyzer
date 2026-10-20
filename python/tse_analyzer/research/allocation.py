"""Beating inflation with what anyone can hold: the stock market (TEDPIX), gold (the Emami coin),
the free-market dollar and a fixed-income fund.

All four can be held through traded funds (an index fund, a gold fund that holds coin
certificates, a fixed-income fund; the dollar through tether), whose round trip costs about
0.3% instead of a share's 1.25%. The question is whether moving between them beats simply
holding one, and inflation.

Strategies, decided at the last session of each Jalali month with data up to that day and
traded at the close of the next session (fund fees on what changes hands):

- **hold**: each asset on its own;
- **trend**: the stock market while TEDPIX is above its 200-session average, else the
  fixed-income fund;
- **rotation (L months)**: of stocks, gold and the dollar, the one that rose most over the last
  L months, if that beat the fixed-income fund over the same months; else the fund. L = 3, 6
  and 12 are all shown, so a lucky choice of L is visible;
- **half stocks, half gold**, rebalanced monthly.

Nothing is fitted (200 sessions and 3/6/12 months are the textbook values), so the whole
period is out of sample; the report still shows the recent years separately. Inflation:
a fixed annual rate (the figure the user gives, default 89%) and, as the market's own measure
over the years, the free-market dollar.

Caveats: an index fund trails TEDPIX by its fees (1-2% a year) and gold funds trail the coin;
the fixed-income fund is assumed to pay a constant rate.
"""

from __future__ import annotations

from dataclasses import dataclass, field as dc_field

import numpy as np
import pandas as pd

from ..fees import DEFAULT_RATES
from ..potential import gregorian_to_jalali, jalali_str

FUND_BUY = DEFAULT_RATES["fee_fund_buy"]
FUND_SELL = DEFAULT_RATES["fee_fund_sell"]
SMA = 200
LOOKBACKS = (3, 6, 12)
ASSETS = {"stocks": "بورس (شاخص کل)", "gold": "طلا (سکه امامی)", "usd": "دلار", "cash": "صندوق درآمد ثابت"}
MIN_MONTHS = 36


@dataclass
class Allocation:
    start: str = ""
    end: str = ""
    recent_from: str = ""
    cash_rate: float = 0.25
    inflation: float = 0.89
    strategies: dict = dc_field(default_factory=dict)  # key -> {"label", "full": stats, "recent": stats}
    current: dict = dc_field(default_factory=dict)  # what each rule holds now

    def to_json(self) -> dict:
        return {"from": self.start, "to": self.end, "recent_from": self.recent_from, "cash_rate": self.cash_rate,
                "inflation": self.inflation, "strategies": self.strategies, "current": self.current}


def prices(index: pd.Series | None, drivers: dict[str, pd.Series]) -> pd.DataFrame:
    """Stocks, gold and dollar on TEDPIX's sessions (the last known value of each day)."""
    if index is None or "coin" not in drivers or "usd" not in drivers:
        return pd.DataFrame()
    stocks = index.dropna().sort_index()
    frame = pd.DataFrame({"stocks": stocks[stocks > 0]})
    for key, name in (("coin", "gold"), ("usd", "usd")):
        s = drivers[key].dropna().sort_index()
        s = s[s > 0]
        frame[name] = s.reindex(frame.index.union(s.index)).ffill().reindex(frame.index)
    return frame.dropna()


def month_ends(dates: pd.DatetimeIndex) -> list[int]:
    """Positions of the last session of each Jalali month."""
    keys = [gregorian_to_jalali(d.year, d.month, d.day)[:2] for d in dates]
    return [i for i in range(len(keys) - 1) if keys[i] != keys[i + 1]]


def decisions(frame: pd.DataFrame, cash_rate: float) -> dict[str, dict[int, dict[str, float]]]:
    """Target weights of every strategy at every month end it can be judged (position -> weights)."""
    sma = frame["stocks"].rolling(SMA, min_periods=SMA).mean()
    ends = month_ends(frame.index)
    out: dict[str, dict[int, dict[str, float]]] = {f"hold_{a}": {} for a in ASSETS}
    out.update({"trend": {}, "mix": {}, **{f"rotation_{n}": {} for n in LOOKBACKS}})
    for k, i in enumerate(ends):
        for a in ASSETS:
            out[f"hold_{a}"][i] = {a: 1.0}
        out["mix"][i] = {"stocks": 0.5, "gold": 0.5}
        if not np.isnan(sma.iloc[i]):
            out["trend"][i] = {"stocks": 1.0} if frame["stocks"].iloc[i] > sma.iloc[i] else {"cash": 1.0}
        for n in LOOKBACKS:
            if k < n:
                continue
            past = ends[k - n]
            growth = frame.iloc[i] / frame.iloc[past] - 1
            cash = (1 + cash_rate) ** ((frame.index[i] - frame.index[past]).days / 365) - 1
            best = str(growth.idxmax())
            out[f"rotation_{n}"][i] = {best: 1.0} if growth[best] > cash else {"cash": 1.0}
    return out


def simulate(frame: pd.DataFrame, plan: dict[int, dict[str, float]], cash_rate: float, start: int) -> pd.Series:
    """Daily value of following ``plan``: a decision at position i is traded at the close of i + 1."""
    assets = list(ASSETS)
    growth = (frame[[a for a in assets if a != "cash"]].pct_change().fillna(0.0) + 1).to_numpy()
    days = frame.index.to_series().diff().dt.days.fillna(0).to_numpy()
    cash_growth = (1 + cash_rate) ** (days / 365)
    values = np.zeros(len(assets))
    uninvested = 1.0  # money not yet placed: the first purchase pays only the buy fee
    trade_at = {i + 1: w for i, w in plan.items() if i >= start}
    curve = []
    for t in range(start, len(frame)):
        if t > start:
            values[:-1] *= growth[t]
            values[-1] *= cash_growth[t]
        target = trade_at.get(t)
        if target is not None:
            total = values.sum() + uninvested
            want = np.array([target.get(a, 0.0) for a in assets]) * total
            delta = want - values
            cost = FUND_BUY * delta.clip(min=0).sum() + FUND_SELL * (-delta).clip(min=0).sum()
            values, uninvested = want * (1 - cost / total), 0.0
        curve.append(values.sum() + uninvested)
    return pd.Series(curve, index=frame.index[start:])


def stats(curve: pd.Series, usd: pd.Series, switches: int, inflation: float) -> dict:
    if len(curve) < 2:
        return {}
    years = (curve.index[-1] - curve.index[0]).days / 365.25
    growth = float(curve.iloc[-1] / curve.iloc[0])
    in_usd = growth / float(usd.loc[curve.index[-1]] / usd.loc[curve.index[0]])
    keys = ["%04d/%02d" % gregorian_to_jalali(d.year, d.month, d.day)[:2] for d in curve.index]
    monthly = curve.groupby(keys, sort=False).last().pct_change().dropna()
    year_ago = curve.index - pd.DateOffset(years=1)
    known = year_ago >= curve.index[0]
    rolling = pd.Series(curve.to_numpy()[known] / curve.reindex(year_ago[known], method="ffill").to_numpy() - 1,
                        index=curve.index[known])  # every 12-month holding period
    return {"years": round(years, 1), "cagr_pct": (growth ** (1 / years) - 1) * 100 if years > 0 else None,
            "usd_cagr_pct": (in_usd ** (1 / years) - 1) * 100 if years > 0 else None,
            "max_drawdown_pct": float((curve / curve.cummax() - 1).min() * 100),
            "monthly_avg_pct": float(monthly.mean() * 100), "loss_months": float((monthly < 0).mean()),
            "worst_year_pct": float(rolling.min() * 100) if rolling.notna().any() else None,
            "beat_inflation_years": float((rolling.dropna() > inflation).mean()) if rolling.notna().any() else None,
            "switches_per_year": switches / years if years > 0 else None}


LABELS = {
    "hold_stocks": "نگه‌داشتن بورس (شاخص کل)", "hold_gold": "نگه‌داشتن طلا (سکه)", "hold_usd": "نگه‌داشتن دلار",
    "hold_cash": "صندوق درآمد ثابت", "trend": "بورس وقتی بالای میانگین ۲۰۰ روزه است، وگرنه درآمد ثابت",
    "mix": "نیمی بورس، نیمی طلا (تعادل ماهانه)",
    **{f"rotation_{n}": f"جابه‌جایی ماهانه بین بورس، طلا و دلار (روند {n} ماهه)" for n in LOOKBACKS},
}


def run(index: pd.Series | None, drivers: dict[str, pd.Series], cash_rate: float = 0.25, inflation: float = 0.89,
        recent_from: pd.Timestamp | None = None) -> Allocation:
    frame = prices(index, drivers)
    out = Allocation(cash_rate=cash_rate, inflation=inflation)
    if frame.empty:
        return out
    plans = decisions(frame, cash_rate)
    ready = [min(p) for p in plans.values() if p]
    if not ready:
        return out
    start = max(ready)  # every rule can be judged from here on
    if len(month_ends(frame.index[start:])) < MIN_MONTHS:
        return out
    recent = recent_from if recent_from is not None and frame.index[start] < recent_from < frame.index[-1] \
        else frame.index[start + int((len(frame) - start) * 0.5)]
    r_start = int(frame.index.searchsorted(recent))
    out.start, out.end = frame.index[start].strftime("%Y-%m-%d"), frame.index[-1].strftime("%Y-%m-%d")
    out.recent_from = frame.index[r_start].strftime("%Y-%m-%d")
    for key, plan in plans.items():
        curve = simulate(frame, plan, cash_rate, start)
        held = [max(w, key=w.get) for i, w in sorted(plan.items()) if i >= start]
        switches = sum(a != b for a, b in zip(held, held[1:]))
        recent_held = [max(w, key=w.get) for i, w in sorted(plan.items()) if i >= r_start]
        out.strategies[key] = {
            "label": LABELS[key],
            "full": stats(curve, frame["usd"], switches, inflation),
            "recent": stats(curve.loc[frame.index[r_start]:], frame["usd"],
                            sum(a != b for a, b in zip(recent_held, recent_held[1:])), inflation),
        }
        last = plan[max(plan)]
        out.current[key] = {a: w for a, w in last.items() if w}
    return out


# --------------------------------------------------------------------- report

def _pct(x, digits=0) -> str:
    return "—" if x is None else f"{x:+.{digits}f}٪"


def _share(x) -> str:
    return "—" if x is None else f"{x * 100:.0f}٪"


def _row(s: dict) -> str:
    return (f"رشد سالانه {_pct(s.get('cagr_pct'))}، به دلار {_pct(s.get('usd_cagr_pct'))}، بیشترین افت {_pct(s.get('max_drawdown_pct'))}، "
            f"بدترین ۱۲ ماه {_pct(s.get('worst_year_pct'))}، ماه منفی {_share(s.get('loss_months'))}")


def _now(weights: dict) -> str:
    return " + ".join(f"{ASSETS[a]}" + (f" {w * 100:.0f}٪" if w < 1 else "") for a, w in weights.items())


def summary_lines(a: Allocation) -> list[str]:
    if not a.strategies:
        return ["۰ب) جابه‌جایی بین بورس، طلا، دلار و درآمد ثابت: داده‌ی کافی نبود (شاخص کل، سکه و دلار دست‌کم ۳ سال لازم است)."]
    lines = [f"۰ب) جابه‌جایی بین بورس، طلا، دلار و درآمد ثابت (با صندوق‌ها؛ کارمزد هر جابه‌جایی حدود "
             f"{(FUND_BUY + FUND_SELL) * 100:.1f}٪؛ درآمد ثابت {a.cash_rate * 100:.0f}٪ در سال):",
             f"   از {jalali_str(pd.Timestamp(a.start))} تا {jalali_str(pd.Timestamp(a.end))}؛ تورم مقایسه {a.inflation * 100:.0f}٪ در سال."]
    ranked = sorted(a.strategies.items(), key=lambda kv: -(kv[1]["full"].get("cagr_pct") or -1e9))
    for key, s in ranked:
        lines.append(f"   • {s['label']}: {_row(s['full'])}")
    best_key, best = ranked[0]
    f, r = best["full"], best["recent"]
    beat = "بالاتر" if (f.get("cagr_pct") or 0) > a.inflation * 100 else "پایین‌تر"
    lines.append(f"   جمع‌بندی: بهترین «{best['label']}» با رشد سالانه {_pct(f.get('cagr_pct'))} ({beat} از تورم "
                 f"{a.inflation * 100:.0f}٪)، و از {jalali_str(pd.Timestamp(a.recent_from))} به بعد {_pct(r.get('cagr_pct'))}؛ "
                 f"در {_share(f.get('beat_inflation_years'))} دوره‌های ۱۲ ماهه از تورم {a.inflation * 100:.0f}٪ جلو بوده است.")
    lines.append(f"   این روش الان: {_now(a.current.get(best_key, {}))}.")
    return lines


def report_text(a: Allocation) -> str:
    if not a.strategies:
        return "جابه‌جایی بین دارایی‌ها: داده‌ی کافی نبود."
    lines = ["جابه‌جایی بین بورس، طلا، دلار و درآمد ثابت", "",
             "تصمیم در آخرین روز هر ماه شمسی با داده‌ی همان روز، معامله در پایان روز بعد؛ کارمزد صندوق برای هر جابه‌جایی کم شده است.",
             "هیچ عددی روی داده تنظیم نشده (میانگین ۲۰۰ روزه و روند ۳، ۶ و ۱۲ ماهه مقادیر رایج‌اند).", ""]
    for key, s in sorted(a.strategies.items(), key=lambda kv: -(kv[1]["full"].get("cagr_pct") or -1e9)):
        f, r = s["full"], s["recent"]
        lines.append(f"── {s['label']}")
        lines.append(f"  کل دوره ({f.get('years')} سال): {_row(f)}؛ جابه‌جایی {f.get('switches_per_year') or 0:.1f} بار در سال؛ "
                     f"۱۲ ماه‌های بالاتر از تورم {a.inflation * 100:.0f}٪: {_share(f.get('beat_inflation_years'))}")
        lines.append(f"  از {jalali_str(pd.Timestamp(a.recent_from))}: {_row(r)}")
        lines.append(f"  الان: {_now(a.current.get(key, {}))}")
    lines += ["", "محدودیت‌ها: صندوق شاخصی سالانه ۱ تا ۲٪ کارمزد مدیریت دارد و صندوق طلا دقیقاً قیمت سکه را دنبال نمی‌کند؛ "
              "سود درآمد ثابت ثابت فرض شده؛ گذشته تضمین آینده نیست."]
    return "\n".join(lines)
