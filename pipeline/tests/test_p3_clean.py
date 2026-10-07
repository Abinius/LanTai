# -*- coding: utf-8 -*-
"""P1 数据清洁层:p3 的 URL 日期回填 / 地市→省映射 / 单位修正 / 跨文章去重。

针对 30 天台账实测的三个数据可信问题:pub_date 空 50%、县域 170 点无具体地名、
同一 raw_text 拆出的重复行(None 值/单位错)。
"""
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from pipeline.contracts import DataPoint, RawItem
from pipeline.modules import p3_extract, p5_publish


class TestUrlDate(unittest.TestCase):
    """URL 里的显式日期:rmrb 正文路径含 YYYYMM/DD,可作为 LLM 未抽出 pub_date 时的合法兜底。"""

    def _url_date(self, url):
        from pipeline.modules.p3_extract import _url_date
        return _url_date(url)

    def test_rmrb_content_path(self):
        self.assertEqual(
            self._url_date("http://paper.people.com.cn/rmrb/pc/content/202610/06/content_30184259.html"),
            "20261006",
        )

    def test_cross_month_boundary(self):
        self.assertEqual(
            self._url_date("http://paper.people.com.cn/rmrb/pc/content/202609/30/content_1.html"),
            "20260930",
        )

    def test_query_param_t(self):
        self.assertEqual(self._url_date("https://example.com/x?t=20261006"), "20261006")

    def test_no_date_pattern(self):
        # xwlb URL 不含日期模式,不能瞎猜;交给 item.date 兜底
        self.assertIsNone(self._url_date("https://tv.cctv.com/v/a/VdH8k4k5"))
        self.assertIsNone(self._url_date("http://x.com/no/date"))
        self.assertIsNone(self._url_date(""))
        self.assertIsNone(self._url_date(None))

    def test_illegal_month_not_accepted(self):
        self.assertIsNone(self._url_date("https://example.com/x?t=20261306"))
        self.assertIsNone(self._url_date("https://example.com/x?t=20260001"))

    def test_content_path_also_validates(self):
        """content 路径与 query 路径的校验必须一致，非法日期不能放行。

        /content/202613/45/ 这种路径要能构造出来就说明解析没验月/日，
        会往台账里写一个不存在的日期，违背「不造假」红线。
        """
        self.assertIsNone(self._url_date(
            "http://paper.people.com.cn/rmrb/pc/content/202613/45/content_1.html"))
        self.assertIsNone(self._url_date(
            "http://paper.people.com.cn/rmrb/pc/content/202600/00/content_1.html"))
        # 合法路径仍要正常解析
        self.assertEqual(
            self._url_date("http://paper.people.com.cn/rmrb/pc/content/202602/28/content_1.html"),
            "20260228")


class TestSafeDate(unittest.TestCase):
    def _safe(self, s):
        from pipeline.modules.p3_extract import _safe_date
        return _safe_date(s)

    def test_valid(self):
        self.assertEqual(self._safe("20261006"), "20261006")
        self.assertEqual(self._safe("20260228"), "20260228")

    def test_invalid(self):
        self.assertIsNone(self._safe("20261301"))   # 13 月
        self.assertIsNone(self._safe("20260001"))   # 0 月
        self.assertIsNone(self._safe("20261032"))   # 32 日
        self.assertIsNone(self._safe("2026-10-06"))
        self.assertIsNone(self._safe(""))
        self.assertIsNone(self._safe(None))


class TestCityToProvince(unittest.TestCase):
    """CITY_TO_PROVINCE 表:地市→省级,让地市级数据归到可订阅的地区标签。"""

    def _n(self, region, indicator=""):
        from pipeline.modules.p3_extract import _normalize_region
        return _normalize_region(region, indicator)

    def test_bare_city_names(self):
        self.assertEqual(self._n("苏州"), "江苏")
        self.assertEqual(self._n("深圳"), "广东")
        self.assertEqual(self._n("南京"), "江苏")
        self.assertEqual(self._n("杭州"), "浙江")

    def test_city_with_shi_suffix(self):
        # 后缀剥离后是地市名,继续归到省级
        self.assertEqual(self._n("南京市"), "江苏")
        self.assertEqual(self._n("深圳市"), "广东")
        self.assertEqual(self._n("苏州市"), "江苏")

    def test_city_with_county_suffix(self):
        # 县级行政区("南京县")不属"市"后缀,靠 CITY_TO_PROVINCE 后缀扫描命中
        self.assertEqual(self._n("南京县"), "江苏")

    def test_municipalities_map_to_self(self):
        # 直辖市是省级,自身映射到自身
        self.assertEqual(self._n("天津"), "天津")
        self.assertEqual(self._n("上海"), "上海")
        self.assertEqual(self._n("北京"), "北京")
        self.assertEqual(self._n("重庆"), "重庆")

    def test_autonomous_prefectures(self):
        # 民族自治州也是地级,归到所属省
        self.assertEqual(self._n("湘西"), "湖南")
        self.assertEqual(self._n("霍尔果斯"), "新疆")

    def test_unregistered_place_untouched(self):
        # 不在表里的地名保留原样,不硬映射
        self.assertEqual(self._n("阿勒泰"), "阿勒泰")
        self.assertEqual(self._n("浦东新区"), "浦东新区")

    def test_existing_behavior_unchanged(self):
        # 已有的省级后缀剥离、自治区别名、封闭类别行为不回归
        self.assertEqual(self._n("海南省"), "海南")
        self.assertEqual(self._n("广西壮族自治区"), "广西")
        self.assertEqual(self._n("全国"), "全国")
        self.assertEqual(self._n("地区"), "地区")
        self.assertEqual(self._n("京津冀地区"), "京津冀地区")


class TestCountyUpgrade(unittest.TestCase):
    """region='县域' 但 indicator 里带具体地名时,升级为所属省,让县域点可订阅。"""

    def _n(self, indicator, region="县域"):
        from pipeline.modules.p3_extract import _normalize_region
        return _normalize_region(region, indicator)

    def test_indicator_with_known_city(self):
        self.assertEqual(self._n("前海合作区 GDP 增速"), "广东")
        self.assertEqual(self._n("苏州工业园区产值"), "江苏")
        self.assertEqual(self._n("吉隆口岸进出口额"), "云南")

    def test_indicator_without_city_keeps_county(self):
        # 抽不出地名则保留「县域」类别,订阅侧仍按县域匹配
        self.assertEqual(self._n("某个县域 GDP 增速"), "县域")
        self.assertEqual(self._n(""), "县域")
        self.assertEqual(self._n(None), "县域")

    def test_indicator_empty_string(self):
        self.assertEqual(self._n(""), "县域")


class TestExtractCityName(unittest.TestCase):
    def _c(self, indicator):
        from pipeline.modules.p3_extract import _extract_city_name
        return _extract_city_name(indicator)

    def test_finds_city_in_long_text(self):
        self.assertEqual(self._c("苏州工业园区 GDP 增速"), "苏州")
        self.assertEqual(self._c("前海合作区 2026 年 1-9 月"), "前海合作区")

    def test_returns_none_when_not_matched(self):
        self.assertIsNone(self._c("全国 GDP 增速"))
        self.assertIsNone(self._c(""))
        self.assertIsNone(self._c(None))


class TestValidateUnit(unittest.TestCase):
    """单位校验:只修「美元」被拆掉「亿/万」的明确错误,其它一律原样。"""

    def _u(self, unit, raw_text):
        from pipeline.modules.p3_extract import _validate_unit
        return _validate_unit(unit, raw_text)

    def test_usd_millions(self):
        self.assertEqual(self._u("美元", "全年出口总额 4323.1 亿美元"), "亿美元")

    def test_usd_ten_thousand(self):
        self.assertEqual(self._u("美元", "均价 850 万美元"), "万美元")

    def test_usd_no_yi_kept(self):
        # 原文没「亿」字就不该硬补
        self.assertEqual(self._u("美元", "共 4323 美元"), "美元")

    def test_yi_already_present(self):
        self.assertEqual(self._u("亿美元", "4323.1 亿美元"), "亿美元")

    def test_other_units_untouched(self):
        self.assertEqual(self._u("%", "5.6%"), "%")
        self.assertEqual(self._u("亿元", "5.6 亿元"), "亿元")
        self.assertEqual(self._u("亿美元/吨", "5 亿美元/吨"), "亿美元/吨")

    def test_empty_inputs(self):
        # unit 为空 → 原样返回 None
        self.assertIsNone(self._u(None, "5 亿美元"))
        # unit 有值但无 raw_text → 无法交叉校验,原样保留
        self.assertEqual(self._u("美元", ""), "美元")
        self.assertIsNone(self._u(None, ""))


class TestDedupePoints(unittest.TestCase):
    """按 (indicator, value, source_url) 去重:同 URL 里 LLM 重复抽出同指标的情况。"""

    def _p(self, indicator="i", value="v", source_url="u", **kw):
        return DataPoint(
            indicator=indicator, value=value, source_url=source_url,
            raw_text=f"{indicator} {value}", **kw,
        )

    def test_same_triplet_deduped(self):
        pts = [
            self._p("投资", "5", "http://u1"),
            self._p("投资", "5", "http://u1"),
        ]
        self.assertEqual(len(p3_extract._dedupe_points(pts)), 1)

    def test_different_indicators_kept(self):
        # 同 raw_text 拆出的不同指标不算重复(不同维度)
        pts = [
            self._p("进出口总额", "8", "http://u1"),
            self._p("出口", "5", "http://u1"),
            self._p("进口", "3", "http://u1"),
        ]
        self.assertEqual(len(p3_extract._dedupe_points(pts)), 3)

    def test_same_indicator_different_url_kept(self):
        pts = [
            self._p("投资", "5", "http://u1"),
            self._p("投资", "5", "http://u2"),
        ]
        self.assertEqual(len(p3_extract._dedupe_points(pts)), 2)

    def test_same_indicator_different_value_kept(self):
        pts = [
            self._p("投资", "5", "http://u1"),
            self._p("投资", "6", "http://u1"),
        ]
        self.assertEqual(len(p3_extract._dedupe_points(pts)), 2)

    def test_order_preserved(self):
        pts = [
            self._p("a", "1", "http://u1"),
            self._p("b", "2", "http://u1"),
            self._p("a", "1", "http://u1"),
            self._p("c", "3", "http://u1"),
        ]
        out = p3_extract._dedupe_points(pts)
        self.assertEqual([p.indicator for p in out], ["a", "b", "c"])


class TestExtractEndToEndDedup(unittest.TestCase):
    """extract() 末尾去重:同一 LLM 输出里的重复指标被合并,跨点保留。"""

    def test_llm_duplicate_points_deduped(self):
        item = RawItem(source="rmrb", date="20261005", title="t", url="u",
                       body="固定资产投资增长 5% 的社会消费品零售额 增长 2.5% 消费 增长")
        with patch("pipeline.modules.p3_extract.llm.chat_json") as mock_chat:
            mock_chat.return_value = [
                {"indicator": "固定资产投资", "value": "5", "unit": "%"},
                {"indicator": "固定资产投资", "value": "5", "unit": "%"},
                {"indicator": "社会消费品零售", "value": "2.5", "unit": "%"},
            ]
            ledger = p3_extract.extract([item], "p")
        self.assertEqual(len(ledger.points), 2)


class TestPubDateFallback(unittest.TestCase):
    """pub_date 三级回落:LLM 抽到 → URL 日期 → item.date(xwlb 无 URL 日期时的合法兜底)。"""

    def _run_extract(self, url, item_date, llm_pub_date=None):
        item = RawItem(source="xwlb", date=item_date, title="t", url=url,
                       body="固定资产投资增长 5% 的增长")
        with patch("pipeline.modules.p3_extract.llm.chat_json") as mock_chat:
            payload = {"indicator": "固定资产投资", "value": "5", "unit": "%"}
            if llm_pub_date is not None:
                payload["pub_date"] = llm_pub_date
            mock_chat.return_value = [payload]
            ledger = p3_extract.extract([item], "p")
        return ledger.points[0].pub_date

    def test_llm_pub_date_wins(self):
        # LLM 显式抽到 10 月 5 日,即使 URL/item.date 是 10 月 6 日,也不覆盖
        got = self._run_extract(
            "http://paper.people.com.cn/rmrb/pc/content/202610/06/content_1.html",
            "20261006", llm_pub_date="20261005",
        )
        self.assertEqual(got, "20261005")

    def test_url_date_fallback(self):
        # LLM 未抽到 pub_date,rmrb URL 里的日期生效
        got = self._run_extract(
            "http://paper.people.com.cn/rmrb/pc/content/202610/06/content_1.html",
            "20261006",
        )
        self.assertEqual(got, "20261006")

    def test_item_date_last_resort(self):
        # xwlb URL 无日期模式,回落 item.date
        got = self._run_extract("https://tv.cctv.com/v/a/VdH8k4k5", "20261006")
        self.assertEqual(got, "20261006")

    def test_all_three_layers_fail(self):
        # URL 无日期、item.date 也不合法时留空,不造假
        got = self._run_extract("https://tv.cctv.com/v/a/VdH8k4k5", "")
        self.assertIsNone(got)


class TestLlmNullFields(unittest.TestCase):
    """LLM 偶尔把字段值输出为 JSON null 而非省略该键——_clean 归一为空,避免 str(None)="None" 落进台账。"""

    def _extract_one(self, payload):
        item = RawItem(source="rmrb", date="20261005", title="t", url="u",
                       body="固定资产投资增长 5%")
        with patch("pipeline.modules.p3_extract.llm.chat_json") as mock_chat:
            mock_chat.return_value = [payload]
            ledger = p3_extract.extract([item], "p")
        return ledger.points[0]

    def test_null_value_becomes_empty_string(self):
        p = self._extract_one({"indicator": "某指标", "value": None, "unit": "%", "yoy": "5"})
        self.assertEqual(p.value, "")
        self.assertNotEqual(p.value, "None")

    def test_null_indicator_becomes_empty_string(self):
        p = self._extract_one({"indicator": None, "value": "5", "unit": "%"})
        self.assertEqual(p.indicator, "")

    def test_null_agency_and_optional_fields(self):
        # agency 契约是 str(默认 ""),None 归一为 "";unit/yoy 契约是 Optional,None 保留
        p = self._extract_one({"indicator": "某指标", "value": "5",
                               "agency": None, "unit": None, "yoy": None})
        self.assertEqual(p.agency, "")
        self.assertIsNone(p.unit)
        self.assertIsNone(p.yoy)


class TestRenderDataTableSkipsEmptyValues(unittest.TestCase):
    """p5 跳过 value=None 且非未核验的点:避免出现「None 亿元」这种脏行。"""

    def _render(self, points):
        from pipeline.modules.p5_publish import _render_data_table
        return _render_data_table(points)

    def test_empty_value_unverified_kept(self):
        # 未核验点的原文进数值列,应保留
        p = DataPoint(indicator="待核验", value="", source_url="http://u",
                      raw_text="社会消费品零售总额增长 2.5%", llm_unverified=True)
        md = self._render([p])
        self.assertIn("2.5", md)

    def test_empty_value_verified_skipped(self):
        # 已核验但 value 为空:跳过,不进表格——表格只剩表头+分隔线
        p = DataPoint(indicator="某指标", value="", source_url="http://u", raw_text="无数字")
        md = self._render([p])
        self.assertIn("| 指标 |", md)
        self.assertNotIn("某指标", md)
        self.assertEqual(len(md.splitlines()), 2)

    def test_mixed_kept_and_skipped(self):
        kept = DataPoint(indicator="投资", value="5", source_url="http://u1",
                         raw_text="投资增长 5%", unit="%")
        dropped = DataPoint(indicator="空值", value="", source_url="http://u2", raw_text="无数字")
        md = self._render([kept, dropped])
        self.assertIn("投资", md)
        self.assertNotIn("空值", md)


if __name__ == "__main__":
    unittest.main()
