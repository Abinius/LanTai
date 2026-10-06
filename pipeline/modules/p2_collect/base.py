"""P2 采集基类:适配器契约 + HTTP 容错。"""

from __future__ import annotations

import urllib.error
import urllib.request
from typing import Optional

from pipeline.contracts import RawItem

DEFAULT_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"


class CollectorResult:
    """采集诊断信息,写入 run.log。"""

    def __init__(self, source: str, date: str) -> None:
        self.source = source
        self.date = date
        self.items: list[RawItem] = []
        self.log_lines: list[str] = []

    def log(self, msg: str) -> None:
        self.log_lines.append(f"[{self.source}/{self.date}] {msg}")


class BaseCollector:
    """适配器契约:子类实现 fetch(date) → list[RawItem]。"""

    name: str = "base"

    def fetch(self, date_str: str) -> tuple[list[RawItem], list[str]]:
        """返回 (采集项, 诊断日志行)。"""
        raise NotImplementedError

    def _http_get(self, url: str, *, timeout: int = 30) -> tuple[int, bytes]:
        """同步 GET,返回 (状态码, 字节)。UA 伪装 Chrome。"""
        req = urllib.request.Request(
            url,
            headers={
                "User-Agent": DEFAULT_UA,
                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                return resp.getcode(), resp.read()
        except urllib.error.HTTPError as e:
            return e.code, e.read() or b""
        except (urllib.error.URLError, TimeoutError) as e:
            return 0, str(e).encode("utf-8", errors="replace")
