"""P2 扩展:官方核验查询(发改委/文旅)。

按指标名回查官方口径——枚举近 N 页列表 + 标题关键词过滤,返回匹配文档。
不进主流水线 SOURCES,仅 run.py --verify 独立查询。

与 p2_collect 区别:采集是日期驱动(fetch(date)),核验是关键词驱动(search(keyword))。
复用 html.parser 零依赖范式(同 rmrb.py),不走 BaseCollector.fetch 接口。
"""

from __future__ import annotations

import re
import urllib.request
from dataclasses import dataclass
from html.parser import HTMLParser
from urllib.parse import urljoin
from urllib.error import HTTPError, URLError

DEFAULT_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36"

# 核验源注册:源名 → (基础 URL, ul class 选择器, 机构名)
# 基础 URL 是列表首页;分页规则:第 N 页(N>=2)为 index_{N-1}.html
SOURCES = {
    "ndrc": (
        "https://www.ndrc.gov.cn/xxgk/zcfb/fzggwl/",
        "u-list",
        "国家发展改革委",
    ),
    "mct_policy": (
        "https://zwgk.mct.gov.cn/zfxxgkml/zcfg/",
        "r_content_2022",
        "文化和旅游部",
    ),
}

# URL 中的日期模式:tYYYYMMDD(中文政务站常见)
_DATE_RE = re.compile(r"t(\d{8})")


@dataclass
class VerifyHit:
    """一条核验命中:官方文档。"""

    title: str
    url: str
    source: str  # "ndrc" | "mct_policy"
    date: str  # YYYYMMDD;从 URL 解析,解析不出留空
    agency: str  # 发布机构


class _ListParser(HTMLParser):
    """提取指定 class 的 <ul> 下 <li> 内的 <a> 链接(title + href)。"""

    def __init__(self, target_class: str) -> None:
        super().__init__()
        self.target_class = target_class
        self._in_target_ul = False
        self._ul_depth = 0  # 当前 ul 嵌套深度(目标 ul 内部)
        self._in_li = False
        self._li_depth = 0
        self._in_a = False
        self._a_href = ""
        self._a_title_attr = ""
        self._a_text_parts: list[str] = []
        self.items: list[tuple[str, str]] = []  # (title, href)

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        d = dict(attrs)
        if tag == "ul":
            cls = d.get("class", "")
            if not self._in_target_ul and self.target_class in cls.split():
                self._in_target_ul = True
                self._ul_depth = 1
            elif self._in_target_ul:
                self._ul_depth += 1
        elif self._in_target_ul:
            if tag == "li":
                self._li_depth += 1
                self._in_li = self._li_depth == 1
            elif tag == "a" and self._in_li:
                self._in_a = True
                self._a_href = d.get("href", "") or ""
                self._a_title_attr = d.get("title", "") or ""
                self._a_text_parts = []

    def handle_endtag(self, tag: str) -> None:
        if not self._in_target_ul:
            return
        if tag == "ul":
            self._ul_depth -= 1
            if self._ul_depth == 0:
                self._in_target_ul = False
        elif tag == "li":
            self._li_depth -= 1
            self._in_li = self._li_depth >= 1
        elif tag == "a" and self._in_a:
            self._in_a = False
            text = "".join(self._a_text_parts).strip()
            title = self._a_title_attr.strip() or text
            href = self._a_href.strip()
            if title and href and href.startswith(("http", "//", "./", "../", "/")):
                self.items.append((title, href))

    def handle_data(self, data: str) -> None:
        if self._in_a:
            self._a_text_parts.append(data)


def _http_get(url: str, *, timeout: int = 20) -> str | None:
    """GET 页面文本;失败返回 None(网络错/超时/非 200)。"""
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": DEFAULT_UA,
            "Accept-Language": "zh-CN,zh;q=0.9,en;q=0.8",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            if resp.getcode() != 200:
                return None
            raw = resp.read()
    except (HTTPError, URLError, TimeoutError):
        return None
    # 政务站编码不统一(部分 GB2312 部分 UTF-8);统一走 errors=replace 避免 surrogate
    for enc in ("utf-8", "gb18030"):
        try:
            return raw.decode(enc, errors="replace")
        except UnicodeDecodeError:
            continue
    return raw.decode("utf-8", errors="replace")


def _extract_date(url: str) -> str:
    """从 URL 提取 YYYYMMDD:t20260928 → 20260928;无匹配留空。"""
    m = _DATE_RE.search(url)
    return m.group(1) if m else ""


def _clean_text(s: str) -> str:
    """清除 BOM(U+FEFF) / 替换字符(U+FFFD),避免下游 UTF-8 编码报错。"""
    return s.replace(chr(0xfeff), "").replace(chr(0xfffd), "").strip()


def _scan(source_name: str, keyword: str, *, max_pages: int = 3) -> list[VerifyHit]:
    """枚举近 max_pages 页列表,按标题关键词过滤。

    相对链接始终相对基础目录(base_url)解析,不随分页页(index_N.html)变——
    政务站列表页的分页文件只是同一目录下的另一种列表视图,链接仍指向上级目录。
    """
    base_url, ul_class, agency = SOURCES[source_name]
    hits: list[VerifyHit] = []
    for page in range(max_pages):
        page_url = base_url if page == 0 else base_url + f"index_{page}.html"
        html = _http_get(page_url)
        if not html:
            continue
        parser = _ListParser(ul_class)
        try:
            parser.feed(html)
        except Exception:
            continue
        for title, href in parser.items:
            title = _clean_text(title)
            if keyword not in title:
                continue
            full_url = urljoin(base_url, href)
            hits.append(
                VerifyHit(
                    title=title,
                    url=full_url,
                    source=source_name,
                    date=_extract_date(full_url),
                    agency=agency,
                )
            )
    # 按日期倒序(空日期排最后),同日期按标题
    hits.sort(key=lambda h: (h.date == "", h.date, h.title), reverse=True)
    return hits


def search(keyword: str, *, max_pages: int = 3) -> list[VerifyHit]:
    """按指标名回查官方核验源。遍历所有注册源,汇总命中。"""
    hits: list[VerifyHit] = []
    for name in SOURCES:
        hits.extend(_scan(name, keyword, max_pages=max_pages))
    # 全局按日期倒序
    hits.sort(key=lambda h: (h.date == "", h.date), reverse=True)
    return hits
