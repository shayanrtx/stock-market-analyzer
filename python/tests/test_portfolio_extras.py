"""Asset classes, sale fees, fund NAV premium, dollar sensitivity and disclosures in the portfolio and stock views."""

from urllib.parse import quote

import numpy as np
import pandas as pd
import pytest

from tse_analyzer import portfolio, web
from tse_analyzer.client import TsetmcClient, daily_history_frame
from tse_analyzer.portfolio import Holding
from tse_analyzer.report import portfolio_text

from conftest import FakeResponse, FakeSession, make_daily_rows

INSTRUMENTS = {
    "فملی": ("1", "IRO1MSMI0001", "ملی صنایع مس ایران", "فلزات اساسی", make_daily_rows(300, drift=0.003)),
    "کمند": ("2", "IRT3KMDF0001", "صندوق س درآمد ثابت کمند", "صندوق سرمایه گذاری قابل معامله",
             make_daily_rows(300, start=10_000, drift=0.001)),
    "اهرم": ("3", "IRT1AHRM0001", "صندوق اهرمی کاریزما", "صندوق سرمایه گذاری قابل معامله",
             make_daily_rows(300, drift=0.002)),
}


def session():
    routes = {}
    for sym, (code, isin, name, sector, rows) in INSTRUMENTS.items():
        routes[f"GetInstrumentSearch/{quote(sym)}"] = FakeResponse(200, {"instrumentSearch": [
            {"insCode": code, "lVal18AFC": sym, "lVal30": name, "cIsin": isin}]})
        routes[f"GetClosingPriceDailyList/{code}/"] = FakeResponse(200, {"closingPriceDaily": rows})
        routes[f"GetInstrumentInfo/{code}"] = FakeResponse(200, {"instrumentInfo": {"sector": {"lSecVal": sector}}})
        routes[f"GetClosingPriceInfo/{code}"] = FakeResponse(200, {"closingPriceInfo": {}})
        routes[f"GetClientType/{code}"] = FakeResponse(200, {"clientType": {}})
    close = INSTRUMENTS["اهرم"][4][0]["pClosing"]
    routes["GetETFByInsCode/3"] = FakeResponse(200, {"etf": {"navRed": close / 1.08, "navSub": close / 1.07}})
    routes["Msg/GetMsgByInsCode/1"] = FakeResponse(200, {"msg": [
        {"dEven": 20261018, "hEven": 91500, "tseTitle": "توقف <b>نماد</b>", "tseDesc": "مجمع"}]})
    routes["Codal/GetPreparedDataByInsCode/15/1"] = FakeResponse(200, {"preparedData": [
        {"title": "افزایش سرمایه", "publishDateTime": "1405/07/20 10:00", "url": "javascript:alert(1)"},
        {"title": "صورت‌های مالی", "publishDateTime": "1405/07/21 10:00", "url": "/Reports/x.aspx"}]})
    return FakeSession(routes)


def usd_like(df: pd.DataFrame) -> pd.Series:
    """A dollar series that moves with فملی's closes (beta ~1)."""
    return df["close"] * 50


def test_portfolio_classes_fees_premium_and_usd():
    client = TsetmcClient(session=session(), max_retries=0)
    usd = usd_like(daily_history_frame(INSTRUMENTS["فملی"][4]))
    r = portfolio.analyze_portfolio(client, [Holding("فملی", 1000, 100), Holding("کمند", 500),
                                             Holding("اهرم", 200)], usd=usd, risk_free=0.31)
    by = {p.holding.symbol: p for p in r.positions}
    assert by["فملی"].asset_class == "سهام" and by["کمند"].asset_class == "صندوق درآمد ثابت"
    assert by["اهرم"].asset_class == "صندوق اهرمی" and by["اهرم"].asset_type == "صندوق"
    assert by["فملی"].sell_fee == pytest.approx(round(by["فملی"].value * 0.0088), abs=1)
    assert by["کمند"].sell_fee == pytest.approx(round(by["کمند"].value * 0.001875), abs=1)
    assert by["فملی"].net_value == pytest.approx(by["فملی"].value - by["فملی"].sell_fee)
    assert r.liquidation_value == pytest.approx(r.nav - sum(p.sell_fee for p in r.positions))
    assert by["اهرم"].nav_premium_pct == pytest.approx(8.0)
    assert by["فملی"].nav_premium_pct is None
    assert by["فملی"].usd_beta == pytest.approx(1.0, abs=0.05) and by["فملی"].usd_correlation > 0.99
    # A fixed-income fund is cash-like: held, never judged on growth potential.
    assert by["کمند"].cash_like and by["کمند"].advice.action == "hold"
    assert any("حباب" in w for w in r.warnings)
    assert r.risk_free == 0.31 and r.metrics["risk_free_pct"] == pytest.approx(31)
    text = portfolio_text(r)
    assert "پس از کسر کارمزد" in text and "صندوق اهرمی" in text and "حباب +8.0٪" in text


def test_stock_page_shows_fund_nav_and_disclosures():
    client = TsetmcClient(session=session(), max_retries=0)
    fund = web.stock_page(client, "اهرم")
    assert "حباب نسبت به NAV" in fund and "صندوق اهرمی" in fund
    usd = usd_like(daily_history_frame(INSTRUMENTS["فملی"][4]))
    stock = web.stock_page(client, "فملی", risk_free=0.3, usd=usd)
    assert "حساسیت به دلار" in stock and "پیام‌های ناظر و اطلاعیه‌های کدال" in stock
    assert "توقف &lt;b&gt;نماد&lt;/b&gt;" in stock  # escaped
    assert 'href="https://www.codal.ir/Reports/x.aspx"' in stock
    assert "javascript:" not in stock  # only http(s) links are rendered
    assert np.isfinite(len(stock))


def test_cli_json_outputs(monkeypatch, capsys, tmp_path):
    import json

    from tse_analyzer import client as client_mod
    from tse_analyzer.cli import main

    s = session()
    monkeypatch.setattr(client_mod.requests, "Session", lambda: s)
    (tmp_path / "pf.csv").write_text("فملی 1000\nاهرم 10\n", encoding="utf-8")
    assert main(["--cache-dir", "", "--risk-free", "0.3", "--json", "portfolio", "--file",
                 str(tmp_path / "pf.csv")]) == 0
    data = json.loads(capsys.readouterr().out)
    assert data["risk"]["equity"] and data["performance"] is None
    assert {p["asset_class"] for p in data["positions"]} == {"سهام", "صندوق اهرمی"}
    assert main(["--cache-dir", "", "--json", "potential", "اهرم"]) == 0
    data = json.loads(capsys.readouterr().out)
    assert data["asset_class"]["kind"] == "fund_leveraged" and data["nav_premium_pct"] == pytest.approx(8.0)
    assert main(["--cache-dir", "", "potential", "فملی"]) == 0
    out = capsys.readouterr().out
    assert "پیام‌های ناظر و اطلاعیه‌های کدال" in out and "نوع دارایی: سهام" in out
