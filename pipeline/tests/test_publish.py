"""p5_publish 双轨出刊测试:brief/full 分流 + 精选数据。

不发真实 LLM 请求——monkey-patch _llm_title_summary 返回固定值。
"""

from __future__ import annotations

import unittest
from unittest.mock import patch

from pipeline.contracts import Analysis, DataPoint, Ledger, Prediction, RawItem
from pipeline.modules.p5_publish import (
    FEATURED_LIMIT,
    _select_featured,
    publish,
)


def _make_point(indicator: str = "指标A", url: str = "http://u1", region: str = "全国") -> DataPoint:
    return DataPoint(
        indicator=indicator,
        value="5.2",
        source_url=url,
        raw_text=f"{indicator} 5.2%",
        agency="国家统计局",
        unit="%",
        region=region,
    )


def _make_analysis(
    judgments: list[str] | None = None,
    findings: list[str] | None = None,
    predictions: list[Prediction] | None = None,
    period: str = "20261001-20261005",
) -> Analysis:
    return Analysis(
        period=period,
        core_judgments=judgments or ["判断1"],
        structural_findings=findings or ["发现1"],
        predictions=predictions or [],
    )


class TestPublishBrief(unittest.TestCase):
    """kind='brief':无台账表,只给判断。"""

    def test_brief_no_data_table(self):
        ledger = Ledger(period="p", points=[_make_point()])
        analysis = _make_analysis()
        with patch("pipeline.modules.p5_publish._llm_title_summary", return_value=("标题", "摘要")):
            report = publish(analysis, ledger, [], "20261001-20261005", kind="brief")
        self.assertEqual(report.kind, "brief")
        self.assertNotIn("| 指标 | 数值 | 单位 |", report.content_md)
        self.assertNotIn("<details>", report.content_md)
        self.assertIn("## 核心摘要", report.content_md)
        self.assertIn("## 一、核心矛盾判断", report.content_md)

    def test_brief_has_sources(self):
        ledger = Ledger(period="p", points=[_make_point(url="http://src1")])
        analysis = _make_analysis()
        with patch("pipeline.modules.p5_publish._llm_title_summary", return_value=("标题", "摘要")):
            report = publish(analysis, ledger, [], "p", kind="brief")
        self.assertIn("http://src1", report.content_md)
        self.assertIn("附:数据溯源", report.content_md)

    def test_brief_default_kind(self):
        """不传 kind 默认 brief。"""
        ledger = Ledger(period="p", points=[])
        analysis = _make_analysis()
        with patch("pipeline.modules.p5_publish._llm_title_summary", return_value=("标题", "摘要")):
            report = publish(analysis, ledger, [], "p")
        self.assertEqual(report.kind, "brief")


class TestPublishFull(unittest.TestCase):
    """kind='full':精选数据表 + <details> 折叠完整台账。"""

    def test_full_has_featured_table_and_details(self):
        p1 = _make_point("指标A", "http://u1")
        p2 = _make_point("指标B", "http://u2")
        ledger = Ledger(period="p", points=[p1, p2])
        analysis = _make_analysis(
            predictions=[Prediction(text="预测1", data_refs=["http://u1"])]
        )
        with patch("pipeline.modules.p5_publish._llm_title_summary", return_value=("标题", "摘要")):
            report = publish(analysis, ledger, [], "p", kind="full")
        self.assertEqual(report.kind, "full")
        self.assertIn("<details>", report.content_md)
        self.assertIn("## 四、精选数据", report.content_md)
        self.assertIn("指标A", report.content_md)  # 精选数据(被引用)
        # 完整台账也应在折叠区内
        self.assertIn("指标B", report.content_md)  # 未被引用但在完整台账

    def test_full_shows_point_total(self):
        points = [_make_point(f"指标{i}", f"http://u{i}") for i in range(5)]
        ledger = Ledger(period="p", points=points)
        analysis = _make_analysis()
        with patch("pipeline.modules.p5_publish._llm_title_summary", return_value=("标题", "摘要")):
            report = publish(analysis, ledger, [], "p", kind="full")
        self.assertIn("共 5 条数据点", report.content_md)

    def test_full_no_cited_points_shows_placeholder(self):
        """无预测引用时精选数据表显示占位文本。"""
        ledger = Ledger(period="p", points=[_make_point()])
        analysis = _make_analysis(predictions=[])
        with patch("pipeline.modules.p5_publish._llm_title_summary", return_value=("标题", "摘要")):
            report = publish(analysis, ledger, [], "p", kind="full")
        self.assertIn("本期无被预测引用的数据点", report.content_md)


class TestSelectFeatured(unittest.TestCase):
    """_select_featured:被预测引用的台账点,上限 FEATURED_LIMIT。"""

    def test_selects_cited_points(self):
        p1 = _make_point("指标A", "http://u1")
        p2 = _make_point("指标B", "http://u2")
        p3 = _make_point("指标C", "http://u3")
        preds = [Prediction(text="预测1", data_refs=["http://u1", "http://u3"])]
        featured = _select_featured([p1, p2, p3], preds)
        indicators = [p.indicator for p in featured]
        self.assertIn("指标A", indicators)
        self.assertIn("指标C", indicators)
        self.assertNotIn("指标B", indicators)

    def test_caps_at_featured_limit(self):
        points = [_make_point(f"指标{i}", f"http://u{i}") for i in range(FEATURED_LIMIT + 10)]
        refs = [f"http://u{i}" for i in range(FEATURED_LIMIT + 10)]
        preds = [Prediction(text="预测", data_refs=refs)]
        featured = _select_featured(points, preds)
        self.assertEqual(len(featured), FEATURED_LIMIT)

    def test_empty_predictions_returns_empty(self):
        points = [_make_point()]
        self.assertEqual(_select_featured(points, []), [])

    def test_empty_data_refs_returns_empty(self):
        points = [_make_point()]
        preds = [Prediction(text="预测", data_refs=[])]
        self.assertEqual(_select_featured(points, preds), [])

    def test_no_matching_points_returns_empty(self):
        points = [_make_point(url="http://u1")]
        preds = [Prediction(text="预测", data_refs=["http://other"])]
        self.assertEqual(_select_featured(points, preds), [])


if __name__ == "__main__":
    unittest.main()
