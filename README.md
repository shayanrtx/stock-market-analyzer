# Stock Market Analyzer — تحلیلگر بورس تهران

<div dir="rtl">

ابزار خط فرمان (CLI) و کتابخانه‌ی پایتون برای تحلیل نمادهای بورس و فرابورس تهران، روی API غیررسمی
`cdn.tsetmc.com`. مرجع endpointها:
[BabakEslami/tse-market-data — ENDPOINTS.md](https://github.com/BabakEslami/tse-market-data/blob/main/ENDPOINTS.md)

## امکانات

- **جستجوی نماد** و تبدیل نام نماد به `insCode` (با یکسان‌سازی «ي/ی» و «ك/ک»)
- **تحلیل تکنیکال:** میانگین متحرک ۲۰/۵۰/۲۰۰، RSI، MACD، باند بولینگر، ATR، نسبت حجم به میانگین
- **بازدهی** یک هفته، یک ماه، سه ماه، شش ماه و یک سال
- **حقیقی/حقوقی:** سرانه‌ی خرید و فروش، قدرت خریدار، ورود و خروج پول حقیقی
- **سیگنال و جمع‌بندی:** امتیازدهی ساده از «نزولی قوی» تا «صعودی قوی»
- **وضعیت بازار:** شاخص‌های منتخب یا همه‌ی شاخص‌ها و نمادهای با بیشترین اثر روی شاخص کل
- تکرار درخواست با exponential backoff برای خطاهای 429/502/503/504، هدرهای لازم (User-Agent/Referer)، و کش روی دیسک

## نصب

```bash
pip install -e ".[dev]"
```

## استفاده

```bash
tse-analyzer search فولاد
tse-analyzer analyze فولاد شپنا خودرو
tse-analyzer analyze فولاد --days 0        # کل تاریخچه
tse-analyzer market                        # شاخص‌های بورس
tse-analyzer market --farabourse --all     # همه‌ی شاخص‌های فرابورس
tse-analyzer --json analyze فولاد          # خروجی JSON
```

استفاده به‌عنوان کتابخانه:

```python
from tse_analyzer import TsetmcClient
from tse_analyzer.analysis import analyze

c = TsetmcClient(cache_dir=".cache")
inst = c.resolve("فولاد")
history = c.daily_history(inst["insCode"])
result = analyze("فولاد", inst["lVal30"], history, c.client_type(inst["insCode"]))
print(result.verdict, result.score)
```

## ساختار پروژه

| فایل | کارکرد |
|---|---|
| `tse_analyzer/client.py` | کلاینت HTTP برای TSETMC: هدرها، retry، کش، تبدیل تاریخچه به DataFrame |
| `tse_analyzer/indicators.py` | اندیکاتورهای تکنیکال با pandas |
| `tse_analyzer/analysis.py` | جریان پول حقیقی/حقوقی، سیگنال‌ها و جمع‌بندی |
| `tse_analyzer/report.py` | خروجی متنی و JSON |
| `tse_analyzer/cli.py` | دستورات خط فرمان |

## نکات مهم

- API غیررسمی است و ممکن است بدون اطلاع قبلی تغییر کند.
- این API فقط از سمت سرور قابل استفاده است. از مرورگر با خطای CORS یا 403 روبه‌رو می‌شوید.
- اگر نماد متوقف باشد، داده‌ی خالی یا خطای ۵۰۰ طبیعی است. وضعیت نماد در خروجی نمایش داده می‌شود.
- **این ابزار توصیه‌ی سرمایه‌گذاری نیست.**

## تست

```bash
pytest
```

تست‌ها آفلاین هستند و با پاسخ‌های ساختگی اجرا می‌شوند.

</div>
