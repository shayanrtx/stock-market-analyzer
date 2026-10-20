"""Cases ported from app/tests/AssetClassTest.php and the fee part of LedgerTest.php."""

import pytest

from tse_analyzer import asset_class as A
from tse_analyzer.asset_class import AssetClass, classify
from tse_analyzer.client import instrument_type
from tse_analyzer.fees import FeeSchedule


# Names as TSETMC returns them: Arabic ي/ك, abbreviations, Latin digits.
CASES = [
    (A.STOCK, "IRO1FOLD0001", "فولاد مباركه اصفهان", "فلزات اساسي", ""),
    (A.RIGHTS, "IRR1MSMI0101", "ح . ملي صنايع مس ايران", "فلزات اساسي", ""),
    (A.FUND_LEVERAGED, "IRT1AHRM0001", "صندوق اهرمي كاريزما", "صندوق سرمايه گذاري قابل معامله",
     "صندوق هاي سرمايه گذاري اهرمي"),
    (A.FUND_GOLD, "IRT1AYAR0001", "صندوق س. پشتوانه طلاي مفيد", "صندوق سرمايه گذاري قابل معامله", ""),
    (A.FUND_FIXED_INCOME, "IRT3KMDF0001", "صندوق س درآمد ثابت كمند", "صندوق سرمايه گذاري قابل معامله", ""),
    (A.FUND_FIXED_INCOME, "IRT1XXXX0001", "صندوق س. با درامد ثابت فیروزه", "", ""),
    (A.FUND_EQUITY, "IRT1XXXX0001", "صندوق س. سهامي آگاه", "", ""),
    (A.FUND_MIXED, "IRT1XXXX0001", "صندوق س.مختلط", "", ""),
    (A.FUND_COMMODITY, "IRT3ZAF00001", "صندوق س. پشتوانه زعفران", "", ""),
    (A.FUND_REAL_ESTATE, "IRT3XXXX0001", "صندوق س. زمين و ساختمان", "", ""),
    (A.FUND_OTHER, "IRT1XXXX0001", "صندوق س. نوع جديد", "", ""),
    (A.BOND, "IRB3TR031229", "اسناد خزانه اسلامي0309", "", ""),
    (A.OPTION_CALL, "IRO9AHRM2731", "اختيارخ اهرم-24000-1405/08/04", "", ""),
    (A.OPTION_PUT, "IROFAHRM2741", "اختيارف اهرم-24000-1405/08/04", "", ""),
    (A.HOUSING, "IRO3XXXX0001", "تسهيلات مسكن بانك مسكن-ارديبهشت1402", "", ""),
]


@pytest.mark.parametrize("kind,isin,name,sector,sub", CASES)
def test_classifies_every_kind(kind, isin, name, sector, sub):
    assert classify(isin, name, "", sector, sub).kind == kind


def test_company_named_fund_is_still_a_stock():
    # «سرمایه‌گذاری صندوق بازنشستگی» is a listed holding company, not a fund.
    assert classify("IRO1SNDK0001", "سرمايه گذاري صندوق بازنشستگي", "وصندوق", "سرمايه گذاريها").kind == A.STOCK
    # With nothing but a name, the name is all we have.
    assert classify("", "صندوق درآمد ثابت نامعلوم").kind == A.FUND_FIXED_INCOME


def test_fee_class_and_roles():
    assert classify("IRT1AYAR0001", "صندوق طلا").fee_class == "fund"
    assert classify("IRB3TR031229", "اسناد خزانه").fee_class == "bond"
    assert classify("IRO9AHRM2731", "اختيارخ اهرم").fee_class == "option"
    assert classify("IRR1MSMI0101", "ح . فملی").fee_class == "stock"
    assert classify("IRT3KMDF0001", "صندوق درآمد ثابت کمند").is_cash_like
    assert not classify("IRT1AHRM0001", "صندوق اهرمی").is_cash_like
    assert classify("IRT1AHRM0001", "صندوق اهرمی").is_fund and classify("IROFAHRM2741").is_option
    for kind in A.kinds():
        assert AssetClass(kind).label != ""
    assert classify("IRT1AHRM0001", "صندوق اهرمی", board="بورس").to_dict() == {
        "kind": A.FUND_LEVERAGED, "label": "صندوق اهرمی", "group": "صندوق", "fee_class": "fund",
        "tone": "orange", "board": "بورس"}


def test_labels_and_groups_match_php():
    assert [(AssetClass(k).label, AssetClass(k).group, AssetClass(k).fee_class) for k in A.kinds()] == [
        ("سهام", "سهام", "stock"), ("حق تقدم", "حق تقدم", "stock"), ("صندوق سهامی", "صندوق", "fund"),
        ("صندوق درآمد ثابت", "صندوق", "fund"), ("صندوق مختلط", "صندوق", "fund"), ("صندوق اهرمی", "صندوق", "fund"),
        ("صندوق طلا", "صندوق کالایی", "fund"), ("صندوق کالایی", "صندوق کالایی", "fund"),
        ("صندوق زمین و ساختمان", "صندوق", "fund"), ("صندوق جسورانه / پروژه", "صندوق", "fund"),
        ("صندوق", "صندوق", "fund"), ("اوراق بدهی", "اوراق", "bond"), ("اختیار خرید", "اختیار معامله", "option"),
        ("اختیار فروش", "اختیار معامله", "option"), ("گواهی سپرده کالایی", "گواهی کالایی", "fund"),
        ("تسهیلات مسکن", "تسهیلات مسکن", "stock"), ("نامشخص", "نامشخص", "stock")]


def test_instrument_type_is_the_group():
    assert instrument_type("IRT1AYAR0001", "صندوق س. پشتوانه طلاي مفيد") == "صندوق کالایی"
    assert instrument_type("IRO9AHRM2731") == "اختیار معامله"
    assert instrument_type("IRR1MSMI0101") == "حق تقدم"


def test_fee_schedule():
    f = FeeSchedule()
    assert f.fee("buy", "stock", 1000, 1000) == 3712
    assert f.fee("sell", "stock", 1000, 1000) == 8800
    assert f.fee("buy", "fund", 1000, 1000) == 1160
    assert f.fee("sell", "fund", 1000, 1000) == 1875
    assert f.fee("sell", "bond", 1000, 1000) == 725
    assert f.fee("buy", "option", 1000, 1000) == 1030
    assert f.fee("buy", "something-else", 1000, 1000) == 3712  # unknown classes pay the stock rate
    assert f.fee("buy", "stock", 1, 135) == 1  # 0.501 rounds half away from zero like PHP
    assert FeeSchedule({"fee_stock_buy": 0.001}).fee("buy", "stock", 1000, 1000) == 1000
