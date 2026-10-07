"""verify.py 单元测试:HTML 解析 + 关键词过滤 + 日期提取。

不发真实网络请求——monkey-patch _http_get 返回本地 HTML fixture。
"""

from __future__ import annotations

import unittest
from unittest.mock import patch

from pipeline.modules.verify import (
    VerifyHit,
    _ListParser,
    _clean_text,
    _extract_date,
    _scan,
    search,
)


# 仿造 ndrc.gov.cn 列表页:ul.u-list > li > a[title][href]
NDRC_HTML = """
<html><body>
<ul class="u-list">
  <li><a href="./202609/t20260928_1407859.html" title="城镇调查失业率管理办法">城镇调查失业率管理办法</a></li>
  <li><a href="./202608/t20260815_1405000.html" title="水利建设投资政策">水利建设投资政策</a></li>
  <li><a href="./202607/t20260701_1400000.html" title="关于促进消费的通知">关于促进消费的通知</a></li>
</ul>
</body></html>
"""

# 仿造 mct.gov.cn 列表页:ul.r_content_2022 > li > div > p > a
MCT_HTML = """
<html><body>
<ul class="r_content_2022">
  <li>
    <div class="l_items">
      <p class="p1"><a href="./bmgz/202608/t20260827_966974.html">城镇调查失业率统计方法</a></p>
      <p class="p2">2026-08-27</p>
    </div>
  </li>
  <li>
    <div class="l_items">
      <p class="p1"><a href="./bmgz/202607/t20260701_960000.html">国内游客人次统计公报</a></p>
      <p class="p2">2026-07-01</p>
    </div>
  </li>
</ul>
</body></html>
"""


class TestListParser(unittest.TestCase):
    """_ListParser:从指定 class 的 ul 中提取 li > a 的 (title, href)。"""

    def test_ndrc_selector_extracts_items(self):
        parser = _ListParser("u-list")
        parser.feed(NDRC_HTML)
        titles = [t for t, _ in parser.items]
        self.assertIn("城镇调查失业率管理办法", titles)
        self.assertIn("水利建设投资政策", titles)
        self.assertIn("关于促进消费的通知", titles)
        self.assertEqual(len(parser.items), 3)

    def test_mct_selector_extracts_items(self):
        parser = _ListParser("r_content_2022")
        parser.feed(MCT_HTML)
        titles = [t for t, _ in parser.items]
        self.assertIn("城镇调查失业率统计方法", titles)
        self.assertIn("国内游客人次统计公报", titles)

    def test_href_is_preserved(self):
        parser = _ListParser("u-list")
        parser.feed(NDRC_HTML)
        hrefs = [h for _, h in parser.items]
        self.assertIn("./202609/t20260928_1407859.html", hrefs)

    def test_ignores_other_ul_classes(self):
        html = """<ul class="other"><li><a href="/x.html">不应匹配</a></li></ul>
                  <ul class="u-list"><li><a href="/y.html">应匹配</a></li></ul>"""
        parser = _ListParser("u-list")
        parser.feed(html)
        self.assertEqual(len(parser.items), 1)
        self.assertEqual(parser.items[0][1], "/y.html")

    def test_empty_ul_yields_nothing(self):
        parser = _ListParser("u-list")
        parser.feed('<ul class="u-list"></ul>')
        self.assertEqual(parser.items, [])


class TestHelpers(unittest.TestCase):
    def test_extract_date_from_t_pattern(self):
        self.assertEqual(
            _extract_date("https://www.ndrc.gov.cn/xxgk/zcfb/fzggwl/202609/t20260928_1407859.html"),
            "20260928",
        )

    def test_extract_date_no_match_returns_empty(self):
        self.assertEqual(_extract_date("https://example.com/no-date.html"), "")

    def test_clean_text_removes_replacement_chars(self):
        self.assertEqual(_clean_text("正常文本"), "正常文本")
        self.assertEqual(_clean_text("有﻿BOM"), "有BOM")
        self.assertEqual(_clean_text("有�替换"), "有替换")
        self.assertEqual(_clean_text("  空格  "), "空格")


class TestScan(unittest.TestCase):
    """_scan:monkey-patch _http_get 避免网络,测关键词过滤 + URL 拼接 + 日期提取。"""

    def test_scan_ndrc_filters_by_keyword(self):
        with patch("pipeline.modules.verify._http_get", return_value=NDRC_HTML):
            hits = _scan("ndrc", "失业率", max_pages=1)
        self.assertEqual(len(hits), 1)
        self.assertEqual(hits[0].title, "城镇调查失业率管理办法")
        self.assertEqual(hits[0].source, "ndrc")
        self.assertEqual(hits[0].agency, "国家发展改革委")
        self.assertEqual(hits[0].date, "20260928")
        # URL 应相对基础目录解析,不含 index_N.html
        self.assertIn("https://www.ndrc.gov.cn/xxgk/zcfb/fzggwl/202609/t20260928_1407859.html", hits[0].url)
        self.assertNotIn("index_", hits[0].url)

    def test_scan_mct_filters_by_keyword(self):
        with patch("pipeline.modules.verify._http_get", return_value=MCT_HTML):
            hits = _scan("mct_policy", "游客", max_pages=1)
        self.assertEqual(len(hits), 1)
        self.assertEqual(hits[0].title, "国内游客人次统计公报")
        self.assertEqual(hits[0].source, "mct_policy")
        self.assertEqual(hits[0].agency, "文化和旅游部")

    def test_scan_no_match_returns_empty(self):
        with patch("pipeline.modules.verify._http_get", return_value=NDRC_HTML):
            hits = _scan("ndrc", "不存在的关键字", max_pages=1)
        self.assertEqual(hits, [])

    def test_scan_pagination_skips_failed_pages(self):
        """第 2 页返回 None(HTTP 失败)应跳过,不中断。"""
        def fake_get(url):
            return NDRC_HTML if url.endswith("/") else None
        with patch("pipeline.modules.verify._http_get", side_effect=fake_get):
            hits = _scan("ndrc", "水利", max_pages=2)
        self.assertEqual(len(hits), 1)
        self.assertEqual(hits[0].title, "水利建设投资政策")

    def test_scan_sorts_by_date_descending(self):
        """多页命中应按日期倒序(空日期最后)。"""
        def fake_get(url):
            if url.endswith("/"):
                return '<ul class="u-list"><li><a href="./202605/t20260501_1.html" title="测试指标A">测试指标A</a></li></ul>'
            else:
                return '<ul class="u-list"><li><a href="./202612/t20261201_2.html" title="测试指标B">测试指标B</a></li></ul>'
        with patch("pipeline.modules.verify._http_get", side_effect=fake_get):
            hits = _scan("ndrc", "测试指标", max_pages=2)
        self.assertEqual(hits[0].date, "20261201")
        self.assertEqual(hits[1].date, "20260501")


class TestSearch(unittest.TestCase):
    """search:汇总所有源,全局排序。"""

    def test_search_aggregates_all_sources(self):
        def fake_get(url):
            if "ndrc" in url:
                return '<ul class="u-list"><li><a href="./202609/t20260901_1.html" title="投资指标">投资指标</a></li></ul>'
            return '<ul class="r_content_2022"><li><div class="l_items"><p class="p1"><a href="./bmgz/202608/t20260801_2.html">投资指标公报</a></p></div></li></ul>'
        with patch("pipeline.modules.verify._http_get", side_effect=fake_get):
            hits = search("投资指标", max_pages=1)
        self.assertGreaterEqual(len(hits), 2)
        sources = {h.source for h in hits}
        self.assertIn("ndrc", sources)
        self.assertIn("mct_policy", sources)

    def test_search_no_matches_returns_empty(self):
        with patch("pipeline.modules.verify._http_get", return_value="<html></html>"):
            hits = search("完全不存在的关键字xyz", max_pages=1)
        self.assertEqual(hits, [])


if __name__ == "__main__":
    unittest.main()
