"""New data sources: supervisor messages, Codal, fund NAV and registry, treasury bills, tgju USD,
and the client-type history field names. All through fake sessions (TSETMC is Iran-only)."""

from datetime import date
from urllib.parse import quote

import numpy as np
import pandas as pd
import pytest

from tse_analyzer import client as client_mod
from tse_analyzer import disclosures, doctor, funds, rates
from tse_analyzer.analysis import money_flow
from tse_analyzer.asset_class import classify
from tse_analyzer.client import TseGatewayClient, TsetmcClient, TsetmcError
from tse_analyzer.fx import TgjuClient, parse_number, parse_rows, usd_sensitivity
from tse_analyzer.live.feed import build_snapshot
from tse_analyzer.potential import flow_trend, jalali_to_gregorian

from conftest import FakeResponse, FakeSession, make_daily_rows


@pytest.fixture(autouse=True)
def no_sleep(monkeypatch):
    monkeypatch.setattr(client_mod.time, "sleep", lambda s: None)


def cdn(routes):
    return TsetmcClient(session=FakeSession(routes), max_retries=0)


# --------------------------------------------------------------- disclosures

def test_messages_and_codal_merge_newest_first():
    c = cdn({
        "Msg/GetMsgByInsCode/1": FakeResponse(200, {"MSG": [  # key case must not matter
            {"dEven": 20261018, "hEven": 91500, "tseTitle": "توقف نماد فملی", "tseDesc": "به دلیل برگزاري مجمع"},
            {"dEven": 20261010, "hEven": 123000, "tseTitle": "", "tseDesc": "بی‌عنوان"}]}),
        "Codal/GetPreparedDataByInsCode/20/1": FakeResponse(200, {"preparedData": [
            {"Title": "گزارش فعاليت ماهانه دوره 1 ماهه منتهی به 1405/06/31", "PublishDateTime": "1405/07/26 18:02:11",
             "Url": "/Reports/Decision.aspx?LetterSerial=abc", "LetterCode": "ن-۳۰", "TracingNo": 123},
            {"title": "آگهی دعوت به مجمع عمومی عادی سالیانه", "sentDateTime": "2026-10-19T10:05:00",
             "url": "https://www.codal.ir/x"}]}),
    })
    items = disclosures.fetch(c, "1")
    assert [i["source"] for i in items] == ["کدال", "کدال", "ناظر"]
    halt = items[2]
    assert halt["at"] == "2026-10-18 09:15" and halt["kind"] == "halt" and halt["important"]
    assert items[0]["kind"] == "agm" and items[0]["at"] == "2026-10-19 10:05"
    monthly = items[1]
    assert monthly["kind"] == "monthly" and monthly["at"] == "2026-10-18 18:02"  # 1405/07/26
    assert monthly["url"] == "https://www.codal.ir/Reports/Decision.aspx?LetterSerial=abc"
    assert monthly["letter_code"] == "ن-۳۰" and monthly["tracing_no"] == "123"


def test_disclosures_tolerate_missing_sources():
    warnings = []
    assert disclosures.fetch(cdn({}), "1", on_warning=warnings.append) == []
    assert len(warnings) == 2
    assert disclosures.parse_datetime("۱۴۰۵/۰۷/۲۸") == "2026-10-20 00:00"
    assert disclosures.from_deven("2026101", "1") == ""


# --------------------------------------------------------------------- funds

def test_etf_nav_and_premium():
    c = cdn({"Fund/GetETFByInsCode/7": FakeResponse(200, {"ETF": {"PRedTran": {"value": 20_000}, "pSubTran": 20_200,
                                                                   "navStat": 19_950}})})
    nav = funds.fetch_nav(c, "7", "صندوق اهرمی کاریزما", classify("IRT1AHRM0001", "صندوق اهرمی"))
    assert nav == {"redemption": 20_000, "subscription": 20_200, "statistical": 19_950, "source": "etf"}
    assert funds.nav_premium(21_000, nav) == pytest.approx(5.0)
    assert funds.nav_premium(None, nav) is None and funds.nav_premium(21_000, None) is None


def test_nav_falls_back_to_registry_by_name():
    c = cdn({
        "Fund/GetETFByInsCode/7": FakeResponse(200, {"etf": {"navRed": 0}}),
        "Fund/GetFunds/22": FakeResponse(200, {"funds": [
            {"mfName": "صندوق سرمایه گذاری اهرمی موج", "navRed": 1},
            {"mfName": "صندوق سرمایه گذاری اهرمی کاریزما", "navRed": 30_000, "navSub": 30_100}]}),
    })
    nav = funds.fetch_nav(c, "7", "صندوق اهرمي كاريزما", classify("IRT1AHRM0001", "صندوق اهرمی"))
    assert nav["redemption"] == 30_000 and nav["source"] == "registry"
    assert funds.same_fund("صندوق س. طلای مفید", "صندوق سرمایه‌گذاری پشتوانه طلای مفید")
    assert not funds.same_fund("صندوق س. طلای لوتوس", "صندوق سرمایه‌گذاری پشتوانه طلای مفید")


def test_fund_registry_parse():
    c = cdn({"Fund/GetFunds/4": FakeResponse(200, {"funds": [
        {"mfName": "صندوق درآمد ثابت کمند", "regNo": "11477", "navRed": "1,234,567", "navSub": 1_235_000,
         "navStat": 1_234_000, "netAsset": 9.5e13, "day1Return": 0.07, "day7Return": 0.5, "day30Return": 2.1,
         "day90Return": 6.4, "day180Return": 13.0, "day365Return": 28.5, "portfolioStock": 3,
         "portfolioBond": 60, "portfolioDeposit": 35, "portfolioCash": 1.5, "portfolioOther": 0.5},
        {"mfName": ""}]})})
    rows = funds.fund_registry(c, 4)
    assert len(rows) == 1
    f = rows[0]
    assert f.nav_redemption == 1_234_567 and f.reg_no == "11477" and f.type_id == 4
    assert list(f.returns) == [1, 7, 30, 90, 180, 365] and f.returns[365] == 28.5
    assert f.portfolio == {"stock": 3, "bond": 60, "deposit": 35, "cash": 1.5, "other": 0.5}


# ---------------------------------------------------------- treasury bills

def test_bill_maturity_parsing():
    assert rates.bill_maturity("اخزا407", "اسناد خزانه-م2بودجه04-070614") == pd.Timestamp(
        *jalali_to_gregorian(1407, 6, 14))
    assert rates.bill_maturity("اخزا020322", "اسناد خزانه اسلامي020322") == pd.Timestamp(
        *jalali_to_gregorian(1402, 3, 22))
    assert rates.bill_maturity("اخزا۰۲۰۳۲۲", "") is not None  # Persian digits
    assert rates.bill_maturity("", "اسناد خزانه-م5بودجه03-041230") is None  # 1404 has no 30 Esfand
    assert rates.bill_maturity("", "اسناد خزانه-م5بودجه99-991230") == pd.Timestamp("2021-03-20")  # 1399 is leap
    assert rates.bill_maturity("فملی", "ملی صنایع مس") is None
    assert rates.bill_maturity("", "اسناد خزانه-م1بودجه03-031399") is None  # month 13


def test_ytm():
    assert rates.ytm(800_000, 365) == pytest.approx(0.25)
    assert rates.ytm(1_000_000 / 1.21, 730) == pytest.approx(0.10)
    assert rates.ytm(1_100_000, 100) is None and rates.ytm(0, 100) is None and rates.ytm(900_000, 0) is None


def _bill(code, symbol, name, isin="IRB3TR000001"):
    return {"insCode": code, "lVal18AFC": symbol, "lVal30": name, "cIsin": isin}


def test_treasury_yield_median_of_qualifying_bills():
    today = date(2026, 10, 20)  # 1405/07/28
    routes = {
        f"GetInstrumentSearch/{quote('اخزا')}": FakeResponse(200, {"instrumentSearch": [
            _bill("1", "اخزا501", "اسناد خزانه-م1بودجه04-060728"),  # 1406/07/28: 365 days
            _bill("2", "اخزا502", "اسناد خزانه-م2بودجه04-070728"),  # ~730 days
            _bill("3", "اخزا503", "اسناد خزانه-م3بودجه04-050901"),  # 33 days: too short
            _bill("4", "اخزا504", "اسناد خزانه-م4بودجه04-061015"),  # stale: last trade 2 months ago
            _bill("5", "اخزا505", "اسناد خزانه-م5بودجه04-061101", isin="IRO1XXXX0001"),  # not a bond
            _bill("6", "اخزا506", "اسناد خزانه-م6بودجه04-060101"),  # no price at all
            _bill("8", "اخزا508", "اسناد خزانه-م8بودجه04-060201"),  # a price but no evidence of a recent trade
        ]}),
        "GetClosingPriceInfo/8": FakeResponse(200, {"closingPriceInfo": {"pClosing": 850_000}}),
        "GetClosingPriceInfo/1": FakeResponse(200, {"closingPriceInfo": {"pClosing": 800_000, "finalLastDate": 20261019}}),
        "GetClosingPriceInfo/2": FakeResponse(200, {"closingPriceInfo": {"pClosing": 1_000_000 / 1.44}}),
        "GetClosingPriceDailyList/2/": FakeResponse(200, {"closingPriceDaily": [
            {"dEven": 20261018, "pClosing": 690_000, "qTotTran5J": 10, "priceYesterday": 690_000}]}),
        "GetClosingPriceInfo/4": FakeResponse(200, {"closingPriceInfo": {"pClosing": 900_000, "finalLastDate": 20260810}}),
        "GetClosingPriceInfo/6": FakeResponse(500),
    }
    rf = rates.treasury_yield(cdn(routes), today)
    assert rf.source == "treasury" and [b.ins_code for b in rf.bills] == ["1", "2"]
    assert rf.bills[0].days == 365 and rf.bills[0].ytm == pytest.approx(0.25)
    assert rf.bills[1].ytm == pytest.approx((1.44) ** (365 / rf.bills[1].days) - 1)
    assert rf.rate == pytest.approx((rf.bills[0].ytm + rf.bills[1].ytm) / 2)
    assert rf.bills[0].maturity_jalali == "1406/07/28"


def test_risk_free_falls_back_and_resolves_settings(tmp_path):
    warnings = []
    rf = rates.risk_free_rate(cdn({}), fallback=0.28, on_warning=warnings.append)
    assert rf.rate == 0.28 and rf.source == "config" and warnings
    assert rates.resolve("31", None).rate == pytest.approx(0.31)
    assert rates.resolve("0.25", None).rate == pytest.approx(0.25)
    assert rates.resolve("auto", cdn({})).source == "config"
    # A treasury rate is cached on disk and reused without requests.
    cached = rates.RiskFree(0.33, "treasury", date(2026, 10, 20), [])
    rates._cache_write(tmp_path, cached)
    session = FakeSession({})
    assert rates.resolve("auto", TsetmcClient(session=session), cache_dir=tmp_path).rate == 0.33
    assert session.calls == []


# ---------------------------------------------------------------------- tgju

TGJU_ROWS = [
    ["1,050,000", "1,040,000", "1,060,000", "<span class='high'>1,055,000</span>", "5,000", "0.48%",
     "2026/10/20", "1405/07/28"],
    ["1,045,000", "1,030,000", "1,051,000", "1,050,000", "-1,000", "-0.1%", "2026/10/19", "1405/07/27"],
    ["bad", "row"],
]


def test_tgju_parse_and_client():
    s = parse_rows(TGJU_ROWS)
    assert list(s.index) == [pd.Timestamp("2026-10-19"), pd.Timestamp("2026-10-20")]
    assert s.iloc[-1] == 1_055_000
    assert parse_number("<b>۱٬۰۵۰٬۰۰۰</b>") == 1_050_000
    session = FakeSession({"summary-table-data/price_dollar_rl": FakeResponse(200, {"data": TGJU_ROWS})})
    fx = TgjuClient(session=session, max_retries=0)
    assert fx.history().iloc[0] == 1_050_000
    assert session.calls[0] == "https://api.tgju.org/v1/market/indicator/summary-table-data/price_dollar_rl"
    assert session.headers["Referer"] == "https://www.tgju.org/"
    with pytest.raises(TsetmcError):
        TgjuClient(session=FakeSession({}), max_retries=0).history("sekee")


def test_usd_sensitivity_beta_and_correlation():
    rng = np.random.default_rng(3)
    idx = pd.bdate_range("2023-01-01", periods=600)
    usd_rets = rng.normal(0.001, 0.01, len(idx))
    usd = pd.Series(500_000 * np.cumprod(1 + usd_rets), index=idx)
    dollar_stock = pd.Series(1000 * np.cumprod(1 + 1.5 * usd_rets + rng.normal(0, 0.003, len(idx))), index=idx)
    domestic = pd.Series(1000 * np.cumprod(1 + rng.normal(0.001, 0.01, len(idx))), index=idx)
    hi = usd_sensitivity(dollar_stock, usd)
    lo = usd_sensitivity(domestic, usd)
    assert hi["beta"] == pytest.approx(1.5, abs=0.25) and hi["correlation"] > 0.8
    assert "بالا" in hi["label"] and abs(lo["correlation"]) < 0.4
    assert hi["weeks"] <= 104
    assert usd_sensitivity(dollar_stock, None) is None
    assert usd_sensitivity(dollar_stock.iloc[:50], usd) is None  # too few weeks


# ------------------------------------------------------- client type names

def test_history_count_names_are_accepted_everywhere():
    history_row = {"recDate": 20261019, "buy_I_Volume": 400, "buy_I_Count": 10, "sell_I_Volume": 100,
                   "sell_I_Count": 10, "buy_N_Volume": 0, "sell_N_Volume": 300}
    assert money_flow(history_row, None)["buyer_power"] == pytest.approx(4.0)
    assert flow_trend([history_row] * 3)["avg_buyer_power"] == pytest.approx(4.0)
    snap = build_snapshot("x", pd.Timestamp("2026-10-20 10:00"), {"pDrCotVal": 10}, [], history_row)
    assert snap.buyer_power == pytest.approx(4.0)


def test_doctor_accepts_alternative_field_names(tmp_path):
    cdn_session = FakeSession({
        "GetInstrumentSearch": FakeResponse(200, {"instrumentSearch": [{"insCode": "1", "lVal18AFC": "فولاد"}]}),
        "GetClientTypeHistory": FakeResponse(200, {"clientType": [
            {"recDate": 1, "buy_I_Volume": 1, "buy_I_Count": 1, "sell_I_Volume": 1, "sell_I_Count": 1,
             "buy_N_Volume": 1}]}),
        "Msg/GetMsgByInsCode": FakeResponse(200, {"msg": [{"dEven": 1, "hEven": 1, "tseTitle": "t", "tseDesc": "d"}]}),
        "Fund/GetETFByInsCode": FakeResponse(200, {"etf": {"pRedTran": 1, "pSubTran": 1, "navStat": 1}}),
    })
    probes = doctor.build_probes(TsetmcClient(session=cdn_session, max_retries=0),
                                 TseGatewayClient(session=FakeSession({}), max_retries=0), "فولاد",
                                 TgjuClient(session=FakeSession({"summary-table-data": FakeResponse(
                                     200, {"data": TGJU_ROWS})}), max_retries=0))
    results = {r.name: r for r in (doctor.run_probe(p, tmp_path) for p in probes)}
    assert results["cdn_client_type_history"].status == "OK"
    assert results["cdn_supervisor_msg"].status == "OK"
    assert results["cdn_etf_nav"].status == "OK"
    assert results["tgju_usd"].status == "OK" and results["tgju_usd"].records == 3
    assert results["cdn_codal"].status == "ERROR"


def test_daily_rows_fixture_still_parses():
    assert len(make_daily_rows(5)) == 5


def test_jalali_to_gregorian_round_trips():
    from datetime import date as gdate, timedelta

    from tse_analyzer.potential import gregorian_to_jalali, jalali_date

    d = gdate(2000, 3, 20)
    while d < gdate(2045, 3, 21):
        assert jalali_to_gregorian(*gregorian_to_jalali(d.year, d.month, d.day)) == (d.year, d.month, d.day)
        d += timedelta(days=1)
    assert jalali_date(1403, 12, 30) == pd.Timestamp("2025-03-20") and jalali_date(1404, 12, 30) is None
