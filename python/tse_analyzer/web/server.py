"""HTTP server (standard library only). Run with ``tse-analyzer serve``."""

from __future__ import annotations

import json
import mimetypes
import sys
import threading
from dataclasses import asdict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from .. import portfolio as pf
from .. import report
from ..client import TseGatewayClient, TsetmcClient, TsetmcError
from ..live import LiveEngine
from ..potential import fetch_potential
from . import pages

STATIC = Path(__file__).parent / "static"
MAX_FORM_BYTES = 64 * 1024
mimetypes.add_type("font/woff2", ".woff2")


class App:
    """Shared state for request handlers: API clients, file paths and the live engine."""

    def __init__(self, cdn: TsetmcClient, gw: TseGatewayClient, portfolio_path: Path, watchlist_path: Path,
                 live_interval: float = 45.0) -> None:
        self.cdn, self.gw = cdn, gw
        self.portfolio_path, self.watchlist_path = portfolio_path, watchlist_path
        self.live_interval = live_interval
        self._engine: LiveEngine | None = None
        self._lock = threading.Lock()

    def watchlist(self) -> list[str]:
        if not self.watchlist_path.exists():
            return []
        return [w for w in self.watchlist_path.read_text(encoding="utf-8").split() if w]

    def live_symbols(self) -> list[str]:
        held = [h.symbol for h in pf.load_holdings(self.portfolio_path) if h.symbol.lower() not in pf.CASH_NAMES]
        return list(dict.fromkeys(held + self.watchlist()))

    def engine(self) -> LiveEngine:
        """Started on first use; rebuilt when the watched symbols change."""
        with self._lock:
            symbols = self.live_symbols()
            if self._engine is None or self._engine.symbols != symbols:
                if self._engine:
                    self._engine.stop()
                # Live polling must bypass the disk cache.
                live_client = TsetmcClient(timeout=self.cdn.timeout, cache_dir=None, max_retries=1)
                live_gw = TseGatewayClient(timeout=self.gw.timeout, cache_dir=None, max_retries=1)
                self._engine = LiveEngine(live_client, symbols, interval=self.live_interval, gateway=live_gw)
                self._engine.start()
            return self._engine

    def add_to_watchlist(self, text: str) -> None:
        current = self.watchlist()
        for sym in text.replace("،", " ").replace(",", " ").split():
            if sym not in current:
                current.append(sym)
        self.watchlist_path.write_text("\n".join(current) + "\n", encoding="utf-8")


def make_handler(app: App):
    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802 (http.server API)
            url = urlparse(self.path)
            q = {k: v[0] for k, v in parse_qs(url.query).items()}
            path = url.path
            if path.startswith("/static/"):
                return self._static(path[len("/static/"):])
            if path in ("/", "/portfolio"):
                return self._html(pages.portfolio_page(app.cdn, app.portfolio_path))
            if path == "/stock" and q.get("symbol"):
                return self._html(pages.stock_page(app.cdn, q["symbol"].strip()))
            if path == "/market":
                return self._html(pages.market_page(app.cdn, app.gw))
            if path == "/live":
                app.engine()
                return self._html(pages.live_page(app.live_symbols()))
            if path == "/screener":
                return self._html(pages.screener_page(app.cdn, app.gw, q.get("type", "")))
            if path == "/api/live":
                return self._json(app.engine().state())
            if path == "/api/stock" and q.get("symbol"):
                try:
                    a, p, _ = fetch_potential(app.cdn, q["symbol"].strip())
                except TsetmcError as exc:
                    return self._json({"error": str(exc)}, 502)
                return self._json({"analysis": report.stock_dict(a), "potential": asdict(p)})
            if path == "/api/portfolio":
                try:
                    r = pf.analyze_portfolio(app.cdn, pf.load_holdings(app.portfolio_path))
                except TsetmcError as exc:
                    return self._json({"error": str(exc)}, 502)
                return self._json({"nav": r.nav, "cash": r.cash, "day_pnl": r.day_pnl, "metrics": r.metrics,
                                   "positions": [asdict(p) for p in r.positions], "warnings": r.warnings})
            return self._html(pages.not_found(), 404)

        def do_POST(self) -> None:  # noqa: N802 (http.server API)
            length = int(self.headers.get("Content-Length") or 0)
            path = urlparse(self.path).path
            if length > MAX_FORM_BYTES or path not in ("/portfolio", "/live/watch"):
                return self._html(pages.not_found(), 400)
            form = parse_qs(self.rfile.read(length).decode("utf-8"))
            if path == "/portfolio":
                pf.save_holdings(app.portfolio_path, pf.parse_holdings(form.get("holdings", [""])[0]))
                return self._redirect("/")
            app.add_to_watchlist(form.get("symbols", [""])[0])
            return self._redirect("/live")

        # ------------------------------------------------------------ helpers

        def _static(self, name: str) -> None:
            target = (STATIC / name).resolve()
            if STATIC.resolve() not in target.parents or not target.is_file():
                return self._html(pages.not_found(), 404)
            data = target.read_bytes()
            ctype = mimetypes.guess_type(target.name)[0] or "application/octet-stream"
            self.send_response(200)
            self.send_header("Content-Type", ctype + ("; charset=utf-8" if ctype.startswith("text/")
                                                      or ctype.endswith("javascript") else ""))
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "public, max-age=86400")
            self.end_headers()
            self.wfile.write(data)

        def _html(self, body: str, status: int = 200) -> None:
            self._send(body.encode("utf-8"), "text/html; charset=utf-8", status)

        def _json(self, obj, status: int = 200) -> None:
            data = json.dumps(obj, ensure_ascii=False, default=_json_default).encode("utf-8")
            self._send(data, "application/json; charset=utf-8", status)

        def _redirect(self, location: str) -> None:
            self.send_response(303)
            self.send_header("Location", location)
            self.end_headers()

        def _send(self, data: bytes, ctype: str, status: int) -> None:
            self.send_response(status)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(data)

        def log_message(self, fmt: str, *args) -> None:
            sys.stderr.write(f"{self.address_string()} {fmt % args}\n")

    return Handler


def _json_default(obj):
    if hasattr(obj, "isoformat"):
        return obj.isoformat()
    if hasattr(obj, "to_dict"):
        return obj.to_dict()
    return str(obj)


def serve(host: str, port: int, cdn: TsetmcClient, gw: TseGatewayClient,
          portfolio_path: str | Path = "portfolio.csv", watchlist_path: str | Path = "watchlist.txt",
          live_interval: float = 45.0) -> None:
    app = App(cdn, gw, Path(portfolio_path), Path(watchlist_path), live_interval)
    server = ThreadingHTTPServer((host, port), make_handler(app))
    print(f"داشبورد آماده است: http://{host}:{port}  (Ctrl+C برای خروج)")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
