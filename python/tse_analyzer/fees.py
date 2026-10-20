"""Broker fee rates by fee class (port of ``app/src/Portfolio/FeeSchedule.php``).

Rates are fractions of trade value. Defaults follow the common Iranian schedule: stock and
rights sales include the 0.5% transfer tax; funds, bonds and options pay none. The fee
class of an instrument comes from :attr:`asset_class.AssetClass.fee_class`.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

FEE_CLASSES = ("stock", "fund", "bond", "option")
DEFAULT_RATES: dict[str, float] = {
    "fee_stock_buy": 0.003712,
    "fee_stock_sell": 0.0088,
    "fee_fund_buy": 0.00116,
    "fee_fund_sell": 0.001875,
    "fee_bond_buy": 0.000725,
    "fee_bond_sell": 0.000725,
    "fee_option_buy": 0.00103,
    "fee_option_sell": 0.00103,
}


@dataclass
class FeeSchedule:
    rates: dict[str, float] = field(default_factory=dict)

    def __post_init__(self) -> None:
        self.rates = {**DEFAULT_RATES, **self.rates}

    def rate(self, side: str, fee_class: str) -> float:
        """``side``: buy | sell. Unknown fee classes pay the stock rate."""
        cls = fee_class if fee_class in FEE_CLASSES else "stock"
        return float(self.rates[f"fee_{cls}_{side}"])

    def fee(self, side: str, fee_class: str, quantity: float, price: float) -> float:
        """Fee in rial, rounded half away from zero like PHP's ``round()``."""
        x = quantity * price * self.rate(side, fee_class)
        return math.copysign(math.floor(abs(x) + 0.5), x)
