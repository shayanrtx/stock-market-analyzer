"""Small statistics helpers: Wilson score intervals and Spearman rank correlation.

Hit rates from a few dozen samples are noisy; a Wilson interval states how noisy. Spearman
is implemented on numpy ranks because pandas' ``method="spearman"`` needs scipy.
"""

from __future__ import annotations

import math
from typing import Iterable

import numpy as np

Z95 = 1.959963984540054


def wilson(successes: float, n: float, z: float = Z95) -> tuple[float, float] | None:
    """Wilson score interval (default 95%) for a proportion ``successes / n``."""
    if not n or n <= 0:
        return None
    p = successes / n
    denom = 1 + z * z / n
    centre = (p + z * z / (2 * n)) / denom
    half = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / denom
    return max(0.0, centre - half), min(1.0, centre + half)


def wilson_rate(flags: Iterable[bool]) -> dict[str, float | int | None]:
    """Share of true values with its interval: ``{"rate", "low", "high", "n"}``."""
    values = [bool(x) for x in flags]
    n = len(values)
    k = sum(values)
    ci = wilson(k, n)
    return {"rate": k / n if n else None, "low": ci[0] if ci else None, "high": ci[1] if ci else None, "n": n}


def rank(values: Iterable[float]) -> np.ndarray:
    """Ranks starting at 1, ties get their average rank (like ``scipy.stats.rankdata``)."""
    x = np.asarray(list(values), dtype=float)
    order = np.argsort(x, kind="mergesort")
    ranks = np.empty(len(x), dtype=float)
    sorted_x = x[order]
    i = 0
    while i < len(x):
        j = i
        while j + 1 < len(x) and sorted_x[j + 1] == sorted_x[i]:
            j += 1
        ranks[order[i:j + 1]] = (i + j) / 2 + 1
        i = j + 1
    return ranks


def spearman(x: Iterable[float], y: Iterable[float]) -> float | None:
    """Spearman rank correlation over the pairs where both values are finite."""
    a, b = np.asarray(list(x), dtype=float), np.asarray(list(y), dtype=float)
    ok = np.isfinite(a) & np.isfinite(b)
    a, b = a[ok], b[ok]
    if len(a) < 3:
        return None
    ra, rb = rank(a), rank(b)
    if ra.std() == 0 or rb.std() == 0:
        return None
    return float(np.corrcoef(ra, rb)[0, 1])


def t_stat(r: float | None, n: int) -> float | None:
    """t statistic of a correlation ``r`` over ``n`` pairs (|t| > 2 ~ significant at 5%)."""
    if r is None or n < 3 or abs(r) >= 1:
        return None
    return r * math.sqrt((n - 2) / (1 - r * r))
