"""Rule-based portfolio actions (افزایش / نگهداری / کاهش / خروج) with target weights.

The rules mirror how a discretionary portfolio manager reads the dashboard:
conviction comes from the growth-potential score, the primary trend and the
historical probability of a rise; size is limited by concentration, by each
holding's share of portfolio risk and by liquidity (how many sessions an exit takes).
These are decision aids, not orders.
"""

from __future__ import annotations

from dataclasses import dataclass, field as dc_field

MAX_POSITION_WEIGHT = 25.0  # percent of the invested portfolio
MAX_DAYS_TO_EXIT = 5.0  # sessions at 20% participation before liquidity becomes a risk
RISK_OVERWEIGHT = 1.5  # risk share / weight above which a holding is a risk hog

ACTIONS = {
    "add": "افزایش",
    "hold": "نگهداری",
    "trim": "کاهش",
    "exit": "خروج تدریجی",
    "halted": "متوقف",
}


@dataclass
class Advice:
    action: str  # key of ACTIONS
    target_weight: float
    reasons: list[str] = dc_field(default_factory=list)

    @property
    def label(self) -> str:
        return ACTIONS[self.action]


def advise(
    weight: float,
    potential_score: float | None,
    probability_up: float | None,
    above_sma200: bool | None,
    risk_share: float | None,
    days_to_exit: float | None,
    status: str | None,
    reward_risk: float | None = None,
) -> Advice:
    reasons: list[str] = []
    if status and status != "مجاز":
        return Advice("halted", weight, [f"نماد {status} است؛ تا بازگشایی امکان معامله نیست."])
    if potential_score is None:
        return Advice("hold", weight, ["داده‌ی کافی برای ارزیابی نیست."])

    # Conviction from the assessment.
    if potential_score >= 65 and above_sma200 is not False:
        action = "add"
        reasons.append(f"امتیاز پتانسیل رشد {potential_score:.0f} و روند اولیه صعودی")
    elif potential_score >= 50:
        action = "hold"
        reasons.append(f"امتیاز پتانسیل رشد {potential_score:.0f}؛ دلیل قاطعی برای تغییر وزن نیست")
    elif potential_score >= 38:
        action = "trim"
        reasons.append(f"امتیاز پتانسیل رشد {potential_score:.0f}؛ کیفیت روند و جریان پول ضعیف است")
    else:
        action = "exit"
        reasons.append(f"امتیاز پتانسیل رشد {potential_score:.0f}؛ ریسک افت بر پتانسیل رشد می‌چربد")

    if probability_up is not None:
        if probability_up < 0.4 and action in ("add", "hold"):
            action = "hold" if action == "add" else "trim"
            reasons.append(f"احتمال تاریخی رشد ۳ ماهه فقط {probability_up * 100:.0f}٪ است")
        elif probability_up >= 0.65 and action in ("trim",):
            action = "hold"
            reasons.append(f"احتمال تاریخی رشد ۳ ماهه {probability_up * 100:.0f}٪ است؛ فروش عجولانه توصیه نمی‌شود")

    if action == "add" and reward_risk is not None and reward_risk < 1:
        action = "hold"
        reasons.append(f"نسبت پاداش به ریسک {reward_risk:.1f} برای خرید جدید کافی نیست؛ منتظر پولبک بمانید")

    # Size: start from today's weight and tilt by conviction.
    tilt = {"add": 1.3, "hold": 1.0, "trim": 0.6, "exit": 0.0}[action]
    target = weight * tilt if weight else (3.0 if action == "add" else 0.0)

    if target > MAX_POSITION_WEIGHT:
        target = MAX_POSITION_WEIGHT
        reasons.append(f"سقف وزن هر نماد {MAX_POSITION_WEIGHT:.0f}٪ است (کنترل تمرکز)")
        if action == "add":
            action = "hold"
        if weight > MAX_POSITION_WEIGHT:
            action = "trim"
    if risk_share is not None and weight and risk_share / weight > RISK_OVERWEIGHT and action != "exit":
        target = min(target, weight * 0.85)
        reasons.append(f"سهم این نماد از ریسک سبد {risk_share:.0f}٪ است، {risk_share / weight:.1f} برابر وزنش")
        if action in ("add", "hold"):
            action = "trim"
    if days_to_exit is not None and days_to_exit > MAX_DAYS_TO_EXIT:
        reasons.append(f"خروج کامل حدود {days_to_exit:.0f} جلسه طول می‌کشد (ریسک نقدشوندگی)")
        if action == "add":
            target = min(target, weight * 1.1)

    return Advice(action, round(target, 2), reasons)

