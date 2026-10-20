import math

import pytest


def make_daily_rows(n=260, start=10_000.0, drift=0.002):
    """Synthetic closingPriceDaily records (newest first, as TSETMC returns them)."""
    rows = []
    price = start
    for i in range(n):
        prev = price
        price = prev * (1 + drift + 0.01 * math.sin(i / 5))
        rows.append({
            "dEven": int(f"2025{1 + i // 28 % 12:02d}{1 + i % 28:02d}") + 10000 * (i // 336),
            "priceFirst": prev,
            "priceMax": max(prev, price) * 1.01,
            "priceMin": min(prev, price) * 0.99,
            "pDrCotVal": price,
            "pClosing": price,
            "priceYesterday": prev,
            "qTotTran5J": 1_000_000 + i * 1000,
            "qTotCap": price * 1_000_000,
            "zTotTran": 500,
        })
    return list(reversed(rows))


@pytest.fixture
def daily_rows():
    return make_daily_rows()


class FakeResponse:
    def __init__(self, status=200, payload=None):
        self.status_code = status
        self._payload = payload if payload is not None else {}
        self.content = b"x"

    def json(self):
        return self._payload


class FakeSession:
    """Routes URL substrings to queued responses."""

    def __init__(self, routes):
        self.routes = routes
        self.headers = {}
        self.calls = []

    def get(self, url, timeout=None):
        self.calls.append(url)
        for key, value in self.routes.items():
            if key in url:
                if isinstance(value, list):
                    return value.pop(0)
                return value
        return FakeResponse(404)
