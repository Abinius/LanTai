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

    def test_report_json_has_summary(self):
        r = Report(period="p", title="标题", summary="摘要", content_md="# 标题")
        data = json.loads(r.to_json())
        self.assertEqual(set(data.keys()),
                         {"period", "title", "summary", "generated_at", "source_count", "content_md"})

    def test_raw_item_defaults(self):
        item = RawItem(source="rmrb", date="20261005", title="t", url="u", body="b")
        self.assertIsNone(item.published_at)
        self.assertEqual(item.fetched_bytes, 0)


if __name__ == "__main__":
    unittest.main()
