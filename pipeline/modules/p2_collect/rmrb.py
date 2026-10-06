"""人民日报适配器:新版面路径 layout→content,正文字典 ozoom/articleContent。

旧路径 rmrb/html/YYYY-MM/DD/nbs.D110000renmrb_*.htm 已废弃(2026-10 实测 404)。
日期从 URL 路径取,不用抓取时间(避雷 9)。
"""

from __future__ import annotations

import re
from html.parser import HTMLParser
from urllib.parse import urljoin

from pipeline.contracts import RawItem
from pipeline.modules.p2_collect.base import BaseCollector

PAPER_BASE = "http://paper.people.com.cn/rmrb/pc/"
LAYOUT_TPL = PAPER_BASE + "layout/{ym}/{dd}/node_{page:02d}.html"
CONTENT_RE = re.compile(r"content_\d+")
MAX_PAGES = 16  # 版面上限,遇到连续 404 即停

# void 元素无结束标签,不能计入容器嵌套深度,否则会永不退出正文容器
VOID_TAGS = {
    "area", "base", "br", "col", "embed", "hr", "img", "input",
    "link", "meta", "param", "source", "track", "wbr",
}

# 版面列表里混排的版权/责编条目(也链向 content 页,但不是文章)
CREDIT_PATTERNS = ("本版责编", "版式设计", "本版美术", "版权所有", "版权声明")


def is_credit_title(title: str) -> bool:
    return any(p in title for p in CREDIT_PATTERNS)


class _LayoutParser(HTMLParser):
    """提取版面页里的文章链接与标题。"""

    def __init__(self) -> None:
        super().__init__()
        self.links: list[tuple[str, str]] = []
        self._in_a = False
        self._cur_href = ""
        self._cur_text: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            href = next((v for k, v in attrs if k == "href"), "")
            if href and CONTENT_RE.search(href):
                self._in_a = True
                self._cur_href = href
                self._cur_text = []

    def handle_data(self, data):
        if self._in_a:
            self._cur_text.append(data)

    def handle_endtag(self, tag):
        if tag == "a" and self._in_a:
            title = "".join(self._cur_text).strip()
            if title:
                self.links.append((self._cur_href, title))
            self._in_a = False


class _ArticleParser(HTMLParser):
    """提取正文容器(ozoom / articleContent)内文本。"""

    TARGET_IDS = {"ozoom", "articleContent"}

    def __init__(self) -> None:
        super().__init__()
        self._depth = 0  # 在目标容器内的嵌套深度
        self._chunks: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag in VOID_TAGS:
            return
        if self._depth > 0:
            self._depth += 1
            return
        elem_id = next((v for k, v in attrs if k == "id"), "")
        if elem_id in self.TARGET_IDS:
            self._depth = 1

    def handle_endtag(self, tag):
        if tag in VOID_TAGS:
            return
        if self._depth > 0:
            self._depth -= 1

    def handle_data(self, data):
        if self._depth > 0:
            text = data.strip()
            # 只保留非空白文本;空白是 HTML 缩进/换行,拼接时会干扰段落分隔
            if text:
                self._chunks.append(text)

    def text(self) -> str:
        # 段落间用换行分隔,不能直接 join——否则 <p>A</p><p>B</p> 会连成 "AB"
        return re.sub(r"\n{3,}", "\n\n", "\n".join(self._chunks)).strip()


class RmrbCollector(BaseCollector):
    name = "rmrb"

    def fetch(self, date_str: str) -> tuple[list[RawItem], list[str]]:
        ym = date_str[:6]  # YYYYMM
        dd = date_str[6:8]
        logs: list[str] = []
        items: list[RawItem] = []
        consecutive_404 = 0

        for page in range(1, MAX_PAGES + 1):
            layout_url = LAYOUT_TPL.format(ym=ym, dd=dd, page=page)
            code, body = self._http_get(layout_url)

            if code == 404:
                consecutive_404 += 1
                logs.append(f"[rmrb/{date_str}] 版面 page={page} 404")
                if consecutive_404 >= 3:
                    logs.append(f"[rmrb/{date_str}] 连续 3 个 404,停止翻版")
                    break
                continue
            if code != 200:
                logs.append(f"[rmrb/{date_str}] 版面 page={page} HTTP {code},跳过")
                continue

            consecutive_404 = 0
            parser = _LayoutParser()
            try:
                parser.feed(body.decode("utf-8", errors="replace"))
            except Exception as e:
                logs.append(f"[rmrb/{date_str}] page={page} 解析异常: {e}")
                continue

            if not parser.links:
                logs.append(f"[rmrb/{date_str}] page={page} 无文章链接,停止翻版")
                break

            logs.append(f"[rmrb/{date_str}] page={page} 匹配 {len(parser.links)} 篇")

            for href, title in parser.links:
                if is_credit_title(title):
                    logs.append(f"[rmrb/{date_str}] page={page} 跳过版权/责编条目: {title[:20]}")
                    continue
                content_url = urljoin(layout_url, href)
                item = self._fetch_article(content_url, title, date_str)
                if item:
                    items.append(item)
                    logs.append(
                        f"[rmrb/{date_str}] 文章 {item.url} body={len(item.body)}字"
                    )
                else:
                    logs.append(f"[rmrb/{date_str}] 文章 {content_url} 正文空,跳过")

        return items, logs

    def _fetch_article(self, url: str, title: str, date_str: str) -> RawItem | None:
        code, body = self._http_get(url)
        if code != 200 or not body:
            return None
        parser = _ArticleParser()
        try:
            parser.feed(body.decode("utf-8", errors="replace"))
        except Exception:
            return None
        text = parser.text()
        if not text:
            return None
        return RawItem(
            source="rmrb",
            date=date_str,
            title=title,
            url=url,
            body=text,
            summary=text[:200],
            published_at=f"{date_str[:4]}-{date_str[4:6]}-{date_str[6:8]}",  # URL 路径日,非抓取时间
            fetched_bytes=len(body),
        )
