<?php

declare(strict_types=1);

namespace Tests;

use Sabadgardan\Market\AssetClass as A;
use Sabadgardan\Market\Instrument;

final class AssetClassTest extends TestCase
{
    /** Names as TSETMC returns them: Arabic ي/ك, abbreviations, Latin digits. */
    public function testClassifiesEveryKind(): void
    {
        $cases = [
            [A::STOCK, 'IRO1FOLD0001', 'فولاد مباركه اصفهان', 'فلزات اساسي', ''],
            [A::RIGHTS, 'IRR1MSMI0101', 'ح . ملي صنايع مس ايران', 'فلزات اساسي', ''],
            [A::FUND_LEVERAGED, 'IRT1AHRM0001', 'صندوق اهرمي كاريزما', 'صندوق سرمايه گذاري قابل معامله', 'صندوق هاي سرمايه گذاري اهرمي'],
            [A::FUND_GOLD, 'IRT1AYAR0001', 'صندوق س. پشتوانه طلاي مفيد', 'صندوق سرمايه گذاري قابل معامله', ''],
            [A::FUND_FIXED_INCOME, 'IRT3KMDF0001', 'صندوق س درآمد ثابت كمند', 'صندوق سرمايه گذاري قابل معامله', ''],
            [A::FUND_FIXED_INCOME, 'IRT1XXXX0001', 'صندوق س. با درامد ثابت فیروزه', '', ''],
            [A::FUND_EQUITY, 'IRT1XXXX0001', 'صندوق س. سهامي آگاه', '', ''],
            [A::FUND_MIXED, 'IRT1XXXX0001', 'صندوق س.مختلط', '', ''],
            [A::FUND_COMMODITY, 'IRT3ZAF00001', 'صندوق س. پشتوانه زعفران', '', ''],
            [A::FUND_REAL_ESTATE, 'IRT3XXXX0001', 'صندوق س. زمين و ساختمان', '', ''],
            [A::FUND_OTHER, 'IRT1XXXX0001', 'صندوق س. نوع جديد', '', ''],
            [A::BOND, 'IRB3TR031229', 'اسناد خزانه اسلامي0309', '', ''],
            [A::OPTION_CALL, 'IRO9AHRM2731', 'اختيارخ اهرم-24000-1405/08/04', '', ''],
            [A::OPTION_PUT, 'IROFAHRM2741', 'اختيارف اهرم-24000-1405/08/04', '', ''],
            [A::HOUSING, 'IRO3XXXX0001', 'تسهيلات مسكن بانك مسكن-ارديبهشت1402', '', ''],
        ];
        foreach ($cases as [$kind, $isin, $name, $sector, $sub]) {
            $this->assertSame($kind, A::classify($isin, $name, '', $sector, $sub)->kind, $name);
        }
    }

    public function testCompanyNamedFundIsStillAStock(): void
    {
        // «سرمایه‌گذاری صندوق بازنشستگی» is a listed holding company, not a fund.
        $c = A::classify('IRO1SNDK0001', 'سرمايه گذاري صندوق بازنشستگي', 'وصندوق', 'سرمايه گذاريها');
        $this->assertSame(A::STOCK, $c->kind);
        // With nothing but a name, the name is all we have.
        $this->assertSame(A::FUND_FIXED_INCOME, A::classify('', 'صندوق درآمد ثابت نامعلوم')->kind);
    }

    public function testFeeClassAndRoles(): void
    {
        $this->assertSame('fund', A::classify('IRT1AYAR0001', 'صندوق طلا')->feeClass());
        $this->assertSame('bond', A::classify('IRB3TR031229', 'اسناد خزانه')->feeClass());
        $this->assertSame('option', A::classify('IRO9AHRM2731', 'اختيارخ اهرم')->feeClass());
        $this->assertSame('stock', A::classify('IRR1MSMI0101', 'ح . فملی')->feeClass());
        $this->assertTrue(A::classify('IRT3KMDF0001', 'صندوق درآمد ثابت کمند')->isCashLike());
        $this->assertFalse(A::classify('IRT1AHRM0001', 'صندوق اهرمی')->isCashLike());
        foreach (A::kinds() as $kind) {
            $this->assertTrue((new A($kind))->label() !== '', $kind);
        }
    }

    public function testInstrumentUsesRegistryClassification(): void
    {
        $i = new Instrument('1', 'اهرم', 'صندوق اهرمی کاریزما', 'IRT1AHRM0001', 'بورس', 'صندوق سرمايه گذاري قابل معامله',
            'صندوق هاي سرمايه گذاري اهرمي');
        $this->assertSame('صندوق اهرمی', $i->assetClass()->label());
        $this->assertSame('صندوق', $i->type());
        $this->assertSame('fund', $i->toArray()['fee_class']);
    }
}
