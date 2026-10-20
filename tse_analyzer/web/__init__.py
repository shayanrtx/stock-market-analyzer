"""Local web dashboard: ``tse-analyzer serve`` then open http://127.0.0.1:8000.

Standard-library HTTP server; fonts (Vazirmatn) and charts (ECharts) are bundled in
``static/`` so the dashboard works without reaching any external CDN. Market data
still comes from TSETMC/tse.ir, which require an Iranian connection.
"""

from .pages import live_page, market_page, portfolio_page, screener_page, stock_page
from .server import App, make_handler, serve

__all__ = ["App", "make_handler", "serve", "portfolio_page", "stock_page", "market_page", "live_page",
           "screener_page"]
