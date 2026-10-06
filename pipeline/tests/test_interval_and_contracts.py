# -*- coding: utf-8 -*-
"""P1 区间解析 + 数据契约 单元测试。"""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from pipeline.contracts import Analysis, DataPoint, Ledger, RawItem, Report
from pipeline.modules import p1_interval


class TestInterval(unittest.TestCase):
    def _args(self, **kw):
        import argparse
        ns = argparse.Namespace()
        ns.from_ = None
        ns.to = None
        ns.days = None
        for k, v in kw.items():
            setattr(ns, k, v)
        return ns

    def test_from_to(self):
        d = p1_interval.parse_range(self._args(from_="2026-10-01", to="2026-10-03"))
        self.assertEqual(d, ["20261001", "20261002", "20261003"])

    def test_single_day(self):
        d = p1_interval.parse_range(self._args(from_="2026-10-05", to="2026-10-05"))
        self.assertEqual(d, ["20261005"])

    def test_cross_month(self):
        d = p1_interval.parse_range(self._args(from_="2026-09-28", to="2026-10-02"))
        self.assertEqual(d, ["20260928", "20260929", "20260930", "20261001", "20261002"])

    def test_cross_year(self):
        d = p1_interval.parse_range(self._args(from_="2025-12-30", to="2026-01-02"))
        self.assertEqual(d, ["20251230", "20251231", "20260101", "20260102"])

    def test_leap_year_february(self):
        d = p1_interval.parse_range(self._args(from_="2028-02-27", to="2028-03-01"))
        self.assertIn("20280229", d)

    def test_non_leap_no_feb29(self):
        d = p1_interval.parse_range(self._args(from_="2027-02-27", to="2027-03-01"))
        self.assertNotIn("20270229", d)

    def test_reversed_range_raises(self):
        with self.assertRaises(ValueError):
            p1_interval.parse_range(self._args(from_="2026-10-05", to="2026-10-01"))

    def test_days_mode(self):
        d = p1_interval.parse_range(self._args(days=3))
        self.assertEqual(len(d), 3)
        self.assertTrue(d[0] < d[-1] or d[0] == d[-1])

    def test_days_one(self):
        d = p1_interval.parse_range(self._args(days=1))
        self.assertEqual(len(d), 1)

    def test_bad_format_raises(self):
        with self.assertRaises(ValueError):
            p1_interval.parse_range(self._args(from_="2026/10/01", to="2026-10-02"))

    def test_period_label_range(self):
        self.assertEqual(
            p1_interval.period_label(["20261001", "20261002", "20261005"]),
            "20261001-20261005",
        )

    def test_period_label_single(self):
        self.assertEqual(p1_interval.period_label(["20261005"]), "20261005")


class TestContracts(unittest.TestCase):
    def test_ledger_json_roundtrip(self):
        ledger = Ledger(period="20261001-20261005")
        ledger.points.append(DataPoint(
            indicator="社会消费品零售总额", value="2.5", source_url="http://x/a.html",
            raw_text="社零同比增长2.5%", agency="国家统计局", unit="%", scope="累计",
            yoy="2.5", mom=None, pub_date=None, llm_unverified=False,
        ))
        data = json.loads(ledger.to_json())
        self.assertEqual(data["period"], "20261001-20261005")
        self.assertTrue(data["generated_at"])
        self.assertEqual(len(data["points"]), 1)
        # 未核验标记与中文都不能丢
        self.assertFalse(data["points"][0]["llm_unverified"])
        self.assertIn("零售", data["points"][0]["indicator"])

    def test_ledger_none_fields_survive(self):
        ledger = Ledger(period="p")
        ledger.points.append(DataPoint(indicator="i", value="v", source_url="u", raw_text="r"))
        data = json.loads(ledger.to_json())
        self.assertIsNone(data["points"][0]["pub_date"])
        self.assertIsNone(data["points"][0]["yoy"])

    def test_analysis_json(self):
        a = Analysis(period="p", core_judgments=["j1", "j2"], structural_findings=["f"])
        a.predictions.append(type("P", (), {"text": "t", "data_refs": ["u1"]})())
        import dataclasses
        a.predictions = [type("P", (), {"__dataclass_fields__": None})()] if False else a.predictions
        # 用真正的 Prediction 类型
        from pipeline.contracts import Prediction
        a.predictions = [Prediction(text="预测A", data_refs=["http://u1"])]
        data = json.loads(a.to_json())
        self.assertEqual(data["core_judgments"], ["j1", "j2"])
        self.assertEqual(data["predictions"][0]["data_refs"], ["http://u1"])

    def test_report_json_has_summary_and_tags(self):
        r = Report(period="p", title="标题", summary="摘要", content_md="# 标题",
                   domains=["投资"], regions=["全国"])
        data = json.loads(r.to_json())
        self.assertEqual(set(data.keys()),
                         {"period", "title", "summary", "generated_at", "source_count",
                          "domains", "regions", "content_md"})
        self.assertEqual(data["domains"], ["投资"])
        self.assertEqual(data["regions"], ["全国"])
        # 订阅标签缺省为空数组,不省键:站端 summaries() 直接读这两个字段
        data2 = json.loads(Report(period="p", title="t", summary="s",
                                  content_md="").to_json())
        self.assertEqual(data2["domains"], [])
        self.assertEqual(data2["regions"], [])

    def test_raw_item_defaults(self):
        item = RawItem(source="rmrb", date="20261005", title="t", url="u", body="b")
        self.assertIsNone(item.published_at)
        self.assertEqual(item.fetched_bytes, 0)


class TestReportTags(unittest.TestCase):
    """P5 订阅标签:站端「我的情报」匹配的唯一数据来源。"""

    def _points(self):
        return [
            DataPoint(indicator="社会消费品零售总额", value="2.5", source_url="u1",
                      raw_text="社会消费品零售总额增长2.5%", region="全国"),
            DataPoint(indicator="固定资产投资", value="5", source_url="u2",
                      raw_text="固定资产投资增长5%", region="山东"),
            DataPoint(indicator="县域产值", value="1", source_url="u3",
                      raw_text="县域产值1亿元", region="县域"),
            DataPoint(indicator="县域产值", value="2", source_url="u4",
                      raw_text="重复点", region="县域"),
        ]

    def test_domains_and_regions(self):
        from pipeline.modules.p5_publish import _tags

        domains, regions = _tags(self._points())

        self.assertIn("消费", domains)
        self.assertIn("投资", domains)
        # 地区去重保序
        self.assertEqual(regions, ["全国", "山东", "县域"])

    def test_empty_points(self):
        from pipeline.modules.p5_publish import _tags

        self.assertEqual(_tags([]), ([], []))


class TestRegionNormalize(unittest.TestCase):
    """地区归一:p3 剥离行政后缀,站端订阅地区标签依赖归一后的裸名。"""

    def _n(self, s):
        from pipeline.modules.p3_extract import _normalize_region
        return _normalize_region(s)

    def test_plain_suffixes_stripped(self):
        self.assertEqual(self._n("海南省"), "海南")
        self.assertEqual(self._n("安徽省"), "安徽")
        self.assertEqual(self._n("广东省"), "广东")

    def test_autonomous_regions_use_bare_name(self):
        # 民族自治区全称要归一到常用裸名,否则「广西壮族」这种既不是候选标签,
        # 又与同一份台账里 LLM 直接吐的「广西」分裂成两个地区标签
        self.assertEqual(self._n("广西壮族自治区"), "广西")
        self.assertEqual(self._n("宁夏回族自治区"), "宁夏")
        self.assertEqual(self._n("新疆维吾尔自治区"), "新疆")
        self.assertEqual(self._n("西藏自治区"), "西藏")
        self.assertEqual(self._n("内蒙古自治区"), "内蒙古")

    def test_closed_categories_untouched(self):
        for c in ("全国", "县域", "地区"):
            self.assertEqual(self._n(c), c)

    def test_none_and_empty_pass_through(self):
        self.assertIsNone(self._n(None))
        self.assertEqual(self._n(""), "")

    def test_non_region_text_untouched(self):
        # 以省/市 结尾但不是行政区的词不应被误剥
        self.assertEqual(self._n("京津冀地区"), "京津冀地区")
        self.assertEqual(self._n("省"), "省")


class TestPredictionRefs(unittest.TestCase):
    """P4 解析 LLM 返回的 predictions:data_refs 必须是 URL 列表。

    LLM 偶尔会把单条引用写成字符串而非数组;直接 for 遍历字符串会逐字符
    拆成几十个「URL」,渲染到站上就是一堆单字符链接,且不报错。
    """

    def _parse(self, preds):
        from pipeline.modules.p4_analyze import _parse
        return _parse({"predictions": preds}, "p")

    def test_string_data_refs_becomes_single_ref(self):
        a = self._parse([{"text": "t", "data_refs": "http://u1"}])
        self.assertEqual(a.predictions[0].data_refs, ["http://u1"])

    def test_empty_and_missing_refs(self):
        a = self._parse([
            {"text": "t0"},
            {"text": "t1", "data_refs": None},
            {"text": "t2", "data_refs": []},
        ])
        for pred in a.predictions:
            self.assertEqual(pred.data_refs, [])

    def test_non_string_entries_dropped(self):
        a = self._parse([{"text": "t", "data_refs": [1, "http://u", None, {"x": 1}]}])
        self.assertEqual(a.predictions[0].data_refs, ["http://u"])

    def test_predictions_without_text_skipped(self):
        a = self._parse([{"data_refs": ["u"]}, {"text": ""}, "not-a-dict", {"text": "ok"}])
        self.assertEqual([p.text for p in a.predictions], ["ok"])

    def test_non_dict_payload_is_empty(self):
        from pipeline.modules.p4_analyze import _parse
        for bad in (None, [], "x", 3):
            self.assertEqual(_parse(bad, "p").core_judgments, [])


class TestPipelineLoaders(unittest.TestCase):
    """--reanalyze / --republish 走「读回已有产物」路径,读回器必须容忍脏产物
    且不能丢字段(region 丢过一回,地区标签会静默失效)。"""

    def _load(self, name, payload, loader):
        import json
        import tempfile
        from pathlib import Path

        from pipeline import run
        with tempfile.TemporaryDirectory() as d:
            Path(d, name).write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
            return getattr(run, loader)(Path(d))

    def test_load_analysis_tolerates_bad_refs(self):
        a = self._load("analysis.json", {
            "period": "p",
            "core_judgments": ["j"],
            "predictions": [{"text": "t", "data_refs": None},
                            {"text": "u", "data_refs": "http://u"}],
        }, "_load_analysis")

        self.assertEqual(a.core_judgments, ["j"])
        self.assertEqual([p.data_refs for p in a.predictions], [[], ["http://u"]])

    def test_load_ledger_keeps_region(self):
        led = self._load("ledger.json", {
            "period": "p",
            "points": [{"indicator": "i", "value": "v", "source_url": "u",
                        "raw_text": "r", "region": "山东"}],
        }, "_load_ledger")

        self.assertEqual(led.points[0].region, "山东")

    def test_load_analysis_missing_file(self):
        import tempfile
        from pathlib import Path

        from pipeline import run
        with tempfile.TemporaryDirectory() as d:
            self.assertIsNone(run._load_analysis(Path(d)))


if __name__ == "__main__":
    unittest.main()
