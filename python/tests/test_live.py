from datetime import datetime, timedelta

import pytest

from tse_analyzer.client import TsetmcClient
from tse_analyzer.live import rules
from tse_analyzer.live.engine import LiveEngine
from tse_analyzer.live.feed import build_snapshot, parse_book
from tse_analyzer.live.models import BookLevel, Snapshot, SymbolContext
from tse_analyzer.live.session import TEHRAN, Phase, next_open, phase_at, session_progress

from conftest import FakeResponse, FakeSession

# 1405/07/26 is a Saturday (trading day); 2026-10-23 is a Friday.
SAT = datetime(2026, 10, 24, tzinfo=TEHRAN)


def at(h, m, day=SAT):
    return day.replace(hour=h, minute=m)


def snap(t, **kw):
    base = dict(symbol="فملی", at=t, last=10_000, close=10_000, yesterday=10_000, volume=0)
    base.update(kw)
    return Snapshot(**base)


CTX = SymbolContext("فملی", "1", avg_volume=1_000_000, avg_value=10_000_000_000, prev_high=10_200,
                    prev_close=10_000, high_52w=12_000, stop=9_000, target=11_500)


# ------------------------------------------------------------------ session

def test_phases():
    assert phase_at(at(8, 50)) == Phase.PRE_OPEN
    assert phase_at(at(9, 0)) == Phase.CONTINUOUS
    assert phase_at(at(12, 35)) == Phase.CLOSING_GAP
    assert phase_at(at(12, 50)) == Phase.TAL
    assert phase_at(at(13, 30)) == Phase.CLOSED
    assert phase_at(at(10, 0, SAT - timedelta(days=1))) == Phase.CLOSED  # Friday
    assert phase_at(at(10, 0), market_open=False) == Phase.CLOSED  # holiday
    assert session_progress(at(10, 45)) == pytest.approx(0.5)
    assert next_open(at(14, 0, SAT + timedelta(days=4))).weekday() == 5  # Wed afternoon -> Sat


# -------------------------------------------------------------------- model

def test_queue_detection_and_book_parsing():
    book = parse_book([
        {"number": 2, "pMeDem": 9_900, "qTitMeDem": 10, "pMeOf": 10_100, "qTitMeOf": 20},
        {"number": 1, "pMeDem": 10_500, "qTitMeDem": 500_000, "zOrdMeDem": 120, "pMeOf": 0, "qTitMeOf": 0},
    ])
    assert book[0].bid_price == 10_500  # sorted by level number
    s = snap(at(9, 30), book=book)
    assert s.queue == "buy" and s.queue_volume == 500_000
    s2 = snap(at(9, 30), book=[BookLevel(9_500, 100, 1, 9_500, 50_000, 30)], lower_limit=9_500)
    assert s2.queue == "sell"
    s3 = snap(at(9, 30), book=[BookLevel(9_900, 100, 1, 10_000, 50, 1)])
    assert s3.queue is None


def test_build_snapshot_from_api_records():
    s = build_snapshot("فملی", at(9, 30),
                       {"pDrCotVal": 10_100, "pClosing": 10_050, "priceYesterday": 10_000, "qTotTran5J": 5e5},
                       [{"number": 1, "pMeDem": 10_090, "qTitMeDem": 1000, "pMeOf": 10_100, "qTitMeOf": 800}],
                       {"buy_I_Volume": 4e5, "buy_CountI": 100, "sell_I_Volume": 2e5, "sell_CountI": 200})
    assert s.change_pct == pytest.approx(1.0)
    assert s.buyer_power == pytest.approx(4.0)
    assert s.bid_total == 1000


# -------------------------------------------------------------------- rules

def test_heavy_buy_queue():
    s = snap(at(9, 30), book=[BookLevel(10_500, 1_200_000, 300, 0, 0, 0)])
    out = rules.queue_state([s], CTX, s.at)
    assert out[0].direction == 1 and out[0].severity == 3 and "سنگین" in out[0].title


def test_buy_queue_breaks_and_drains():
    q = lambda t, v: snap(t, book=[BookLevel(10_500, v, 10, 0, 0, 0)])  # noqa: E731
    gone = snap(at(9, 45), book=[BookLevel(10_400, 100, 1, 10_450, 200, 2)])
    out = rules.queue_change([q(at(9, 30), 1_000_000), gone], CTX, gone.at)
    assert out[0].title == "صف خرید شکسته شد" and out[0].direction == -1
    hist = [q(at(9, 30), 1_000_000), q(at(9, 42), 500_000)]
    out = rules.queue_change(hist, CTX, hist[-1].at)
    assert out[0].title == "صف خرید در حال ریختن"


def test_imbalance_and_smart_money():
    s = snap(at(10, 0), book=[BookLevel(9_990, 60_000, 5, 10_000, 10_000, 3)])
    assert rules.book_imbalance([s], CTX, s.at)[0].direction == 1
    s = snap(at(10, 0), buy_i_volume=900_000, buy_i_count=30, sell_i_volume=300_000, sell_i_count=60)
    out = rules.smart_money([s], CTX, s.at)
    assert out[0].title == "ورود پول هوشمند" and out[0].metrics["power"] == pytest.approx(6.0)
    few = snap(at(10, 0), buy_i_volume=900_000, buy_i_count=3, sell_i_volume=300_000, sell_i_count=6)
    assert rules.smart_money([few], CTX, few.at) == []


def test_volume_pace_needs_time_and_volume():
    early = snap(at(9, 5), volume=900_000)
    assert rules.volume_pace([early], CTX, early.at) == []  # too early to judge
    s = snap(at(10, 45), volume=2_000_000, last=10_400)  # half session, 4x expected
    out = rules.volume_pace([s], CTX, s.at)
    assert out[0].severity == 3 and out[0].direction == 1


def test_levels_and_fast_move():
    s = snap(at(10, 0), last=8_900)
    assert any(i.rule == "stop" for i in rules.price_levels([s], CTX, s.at))
    s = snap(at(10, 0), last=12_100)
    titles = {i.title for i in rules.price_levels([s], CTX, s.at)}
    assert {"شکست سقف ۵۲ هفته", "رسیدن به هدف قیمتی"} <= titles
    hist = [snap(at(10, 0), last=10_000), snap(at(10, 12), last=10_300)]
    assert rules.fast_move(hist, CTX, hist[-1].at)[0].direction == 1


def test_pre_open_outlook():
    ctx = SymbolContext("فملی", "1", prev_close=10_000)
    s = snap(at(8, 55), last=None, close=None, upper_limit=10_300,
             book=[BookLevel(10_300, 900_000, 80, 10_300, 1000, 1)])
    assert rules.pre_open_outlook([s], ctx, s.at)[0].title == "احتمال بازگشایی با صف خرید"
    s = snap(at(8, 55), book=[BookLevel(10_250, 5000, 4, 10_150, 4000, 3)])
    out = rules.pre_open_outlook([s], ctx, s.at)
    assert out[0].direction == 1 and "+2.0" in out[0].title


def test_registry_is_extensible():
    @rules.rule("test_rule", {Phase.CONTINUOUS}, "test")
    def custom(history, ctx, now):
        return []
    try:
        assert any(r.id == "test_rule" for r in rules.REGISTRY)
    finally:
        rules.REGISTRY[:] = [r for r in rules.REGISTRY if r.id != "test_rule"]


# ------------------------------------------------------------------- engine

def _engine_session(queue_volume):
    return {
        "GetClosingPriceInfo/1": FakeResponse(200, {"closingPriceInfo": {
            "pDrCotVal": 10_500, "pClosing": 10_400, "priceYesterday": 10_000, "qTotTran5J": 400_000}}),
        "BestLimits/1": FakeResponse(200, {"bestLimits": [
            {"number": 1, "pMeDem": 10_500, "qTitMeDem": queue_volume, "zOrdMeDem": 50, "pMeOf": 0, "qTitMeOf": 0}]}),
        "GetClientType/1": FakeResponse(200, {"clientType": {}}),
        "SelectedIndexes/1": FakeResponse(200, {"indexB1": [
            {"insCode": "32097828799138957", "lVal30": "شاخص كل", "xDrNivJIdx004": 3e6, "xVarIdxJRfV": -1.6}]}),
    }


def test_engine_tick_dedupes_and_reports_state():
    clock = {"now": at(9, 30)}
    session = FakeSession(_engine_session(1_500_000))
    engine = LiveEngine(TsetmcClient(session=session, max_retries=0), [], contexts={"فملی": CTX},
                        clock=lambda: clock["now"])
    first = engine.tick()
    titles = {i.title for i in first}
    assert "صف سنگین خرید" in titles and "افت قابل توجه شاخص کل" in titles
    assert engine.phase == Phase.CONTINUOUS

    clock["now"] = at(9, 31)
    assert not any(i.rule == "queue" for i in engine.tick())  # cooldown

    state = engine.state()
    row = state["symbols"][0]
    assert row["queue"] == "buy" and row["change"] == pytest.approx(5.0)
    assert state["phase_label"] == "معاملات پیوسته" and state["insights"]
    assert state["market"]["change"] == -1.6


def test_engine_closed_market_collects_but_does_not_alert():
    session = FakeSession(_engine_session(1_500_000))
    engine = LiveEngine(TsetmcClient(session=session, max_retries=0), [], contexts={"فملی": CTX},
                        clock=lambda: at(15, 0))
    assert engine.tick() == []
    assert engine.state()["symbols"][0]["price"] == 10_500


def test_engine_survives_symbol_errors():
    session = FakeSession({"SelectedIndexes/1": FakeResponse(200, {"indexB1": []})})
    engine = LiveEngine(TsetmcClient(session=session, max_retries=0), [], contexts={"فملی": CTX},
                        clock=lambda: at(10, 0))
    engine.tick()
    assert engine.state()["symbols"][0]["error"]
