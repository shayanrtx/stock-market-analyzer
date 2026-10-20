"""Candidate factors borrowed from other markets, tested before they may enter the score.

Each is computed at date t from prices up to t and given the sign under which it "should"
work, so a positive monthly IC means the idea holds on the Tehran exchange:

- ``mom_12_1``: return from 12 months to 1 month ago (Jegadeesh & Titman; the last month is
  skipped because it tends to reverse). The app's own momentum factor uses 6 months.
- ``reversal_1m``: minus last month's return (short-term reversal).
- ``high_52w``: price relative to its 52-week high (George & Hwang: traders anchor to the high
  and under-react as a stock approaches it).
- ``low_vol``: minus the volatility of the last 60 sessions (low-volatility anomaly).
- ``limit_days``: sessions near the upper limit minus sessions near the lower limit over the
  last 5 (markets with daily limits — Taiwan, China — continue after limit hits because the
  limit delays price discovery; Tehran's ±5% should do the same if anything does).
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd

LIMIT_NEAR = 0.045  # a close within half a percent of the ±5% limit

LABELS = {
    "mom_12_1": "مومنتوم ۱۲ ماهه (بدون ماه آخر)",
    "reversal_1m": "بازگشت کوتاه‌مدت (ماه آخر برعکس)",
    "high_52w": "نزدیکی به سقف ۵۲ هفته",
    "low_vol": "کم‌نوسانی (۶۰ جلسه)",
    "limit_days": "روزهای سقف دامنه در هفته‌ی اخیر",
    "macro:total": "باد موافق کلان (همه‌ی محرک‌ها)",
    "macro:usd": "باد موافق دلار",
    "macro:usdt": "باد موافق تتر",
    "macro:gold": "باد موافق انس طلا",
    "macro:coin": "باد موافق سکه",
    "macro:btc": "باد موافق بیت‌کوین",
    "macro:oil": "باد موافق نفت",
}


def price_candidates(adjusted_close: pd.Series, raw: pd.DataFrame, at: pd.Timestamp) -> dict[str, float]:
    """Candidate values at ``at``. Returns from the fully adjusted series equal the point-in-time
    ones (later adjustments rescale both ends alike); limit days use the raw rows."""
    c = adjusted_close.loc[:at].to_numpy(dtype=float)
    out: dict[str, float] = {}
    n = len(c)
    if n >= 253 and c[-253] > 0 and c[-22] > 0:
        out["mom_12_1"] = c[-22] / c[-253] - 1
    if n >= 22 and c[-22] > 0:
        out["reversal_1m"] = -(c[-1] / c[-22] - 1)
    if n >= 250:
        high = np.nanmax(c[-250:])
        if high > 0:
            out["high_52w"] = c[-1] / high
    if n >= 61:
        r = np.diff(c[-61:]) / c[-61:-1]
        vol = float(np.nanstd(r, ddof=1))
        if math.isfinite(vol):
            out["low_vol"] = -vol
    rows = raw.loc[:at].tail(5)
    if len(rows) == 5 and "yesterday" in rows.columns:
        moves = (rows["close"] / rows["yesterday"] - 1).to_numpy(dtype=float)
        moves = moves[np.isfinite(moves)]
        if len(moves):
            out["limit_days"] = float((moves >= LIMIT_NEAR).sum() - (moves <= -LIMIT_NEAR).sum())
    return out
