# -*- coding: utf-8 -*-
"""P2.5 核验源闭环:领域派生关键词 + 关键词回查汇总。"""
import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from pipeline.contracts import DataPoint, Ledger
from pipeline.modules import p2_verify_all, p5_publish


def _point(indicator, raw_text="", **kw):
    return DataPoint(
        indicator=indicator, value="1", source_url="http://u",
        raw_text=raw_text, **kw,
    )


class TestDeriveSearchTerms(unittest.TestCase):
    """领域 → 具体核验关键词派生。"""

    def _terms(self, points):
        ledger = Ledger(points=points, period="p")
        return p2_verify_all.derive_search_terms(ledger, max_terms=8)

    def test_growth_domain_picks_gdp(self):
        pts = [_point("GDP 同比增长 5%", "GDP 同比增长 5%")]
        terms = self._terms(pts)
        self.assertIn("GDP", terms)

    def test_consumption_domain_picks_retail(self):
        pts = [_point("社会消费品零售 增长 2.5%", "社会消费品零售 增长 2.5%")]
        terms = self._terms(pts)
        self.assertIn("社会消费品零售", terms)

    def test_trade_domain_picks_import_export(self):
        pts = [_point("进出口 增长 18%", "进出口 增长 18%")]
        terms = self._terms(pts)
        self.assertIn("进出口", terms)

    def test_investment_domain_picks_fixed_asset(self):
        pts = [_point("固定资产投资 增长 5%", "固定资产投资 增长 5%")]
        terms = self._terms(pts)
        self.assertIn("固定资产投资", terms)

    def test_price_domain_picks_cpi(self):
        pts = [_point("CPI 环比 0.4%", "CPI 环比 0.4%")]
        terms = self._terms(pts)
        self.assertIn("CPI", terms)

    def test_multiple_domains_multi_terms(self):
        pts = [
            _point("GDP 增长 5%", "GDP 增长 5%"),
            _point("CPI 环比 0.4%", "CPI 环比 0.4%"),
            _point("固定资产投资 增长 3%", "固定资产投资 增长 3%"),
        ]
        terms = self._terms(pts)
        self.assertIn("GDP", terms)
        self.assertIn("CPI", terms)
        self.assertIn("固定资产投资", terms)

    def test_max_terms_cap(self):
        # 九类领域都出现,但 max_terms=3 只保留前 3 个
        pts = [
            _point(f"{kw} 增长 5%", f"{kw} 增长 5%")
            for kw in ["GDP", "CPI", "固定资产投资", "社会消费品零售",
                       "进出口", "M2", "用电量", "财政收入", "就业"]
        ]
        terms = p2_verify_all.derive_search_terms(Ledger(points=pts, period="p"), max_terms=3)
        self.assertEqual(len(terms), 3)

    def test_empty_ledger_returns_empty(self):
        self.assertEqual(self._terms([]), [])

    def test_unknown_domain_skipped(self):
        # 台账里没匹配任何领域关键词,派生不出核验词
        pts = [_point("某个冷门指标", "冷门指标的原始文本")]
        terms = self._terms(pts)
        self.assertEqual(terms, [])

    def test_fallback_to_generic_keyword(self):
        # 具体关键词不在台账文本中,取下一个候选
        pts = [_point("工业增加值 增长 5%", "工业增加值 增长 5%")]
        terms = self._terms(pts)
        # 增长 领域候选 ["GDP", "工业增加值", "生产总值"]，GDP 不在文本中，取"工业增加值"
        self.assertIn("工业增加值", terms)


class TestVerifyFromLedger(unittest.TestCase):
    """关键词回查、去重、总量 cap、单条失败容错。"""

    def _ledger_with_domains(self, terms_data):
        # terms_data 里的关键词会触发对应领域的台账
        pts = [_point(f"{t} 增长 5%", f"{t} 增长 5%") for t in terms_data]
        return Ledger(points=pts, period="p")

    def test_dedupes_by_url(self):
        """同一 URL 被两个关键词命中时，只保留第一次出现。"""
        ledger = self._ledger_with_domains(["GDP", "CPI"])

        def fake_search(term, **kw):
            from pipeline.modules.verify import VerifyHit
            return [VerifyHit(
                title=f"{term} 文档",
                url="http://same-url",
                source="ndrc",
                date="20261001",
                agency="国家发展和改革委员会",
            )]

        with patch("pipeline.modules.verify.search", side_effect=fake_search):
            hits = p2_verify_all.verify_from_ledger(ledger, max_hits_per_term=3, total_limit=10)

        self.assertEqual(len(hits), 1)
        self.assertEqual(hits[0]["keyword"], "GDP")  # 首个关键词优先

    def test_respects_total_limit(self):
        """命中超过 total_limit 时截断。"""
        ledger = self._ledger_with_domains(["GDP", "CPI", "固定资产投资", "社会消费品零售", "进出口"])

        call_id = [0]

        def fake_search(term, **kw):
            from pipeline.modules.verify import VerifyHit
            call_id[0] += 1
            return [VerifyHit(
                title=f"{term} 文档 {call_id[0]}{i}",
                url=f"http://url-{call_id[0]}-{i}",
                source="ndrc",
                date="20261001",
                agency="ndrc",
            ) for i in range(5)]

        with patch("pipeline.modules.verify.search", side_effect=fake_search):
            hits = p2_verify_all.verify_from_ledger(ledger, max_hits_per_term=5, total_limit=3)

        self.assertEqual(len(hits), 3)

    def test_respects_max_hits_per_term(self):
        """每个关键词最多保留 max_hits_per_term 条。"""
        ledger = self._ledger_with_domains(["GDP"])

        def fake_search(term, **kw):
            from pipeline.modules.verify import VerifyHit
            return [VerifyHit(
                title=f"{term} 文档 {i}",
                url=f"http://url-{i}",
                source="ndrc",
                date="20261001",
                agency="ndrc",
            ) for i in range(10)]

        with patch("pipeline.modules.verify.search", side_effect=fake_search):
            hits = p2_verify_all.verify_from_ledger(ledger, max_hits_per_term=2, total_limit=20)

        self.assertEqual(len(hits), 2)

    def test_exception_per_term_skipped(self):
        """单个关键词回查抛异常时不阻断其它。"""
        ledger = self._ledger_with_domains(["GDP", "CPI"])

        def fake_search(term, **kw):
            from pipeline.modules.verify import VerifyHit
            if term == "GDP":
                raise ConnectionError("网络失败")
            return [VerifyHit(
                title="CPI 文档", url="http://u", source="ndrc",
                date="20261001", agency="ndrc",
            )]

        with patch("pipeline.modules.verify.search", side_effect=fake_search):
            hits = p2_verify_all.verify_from_ledger(ledger)

        self.assertEqual(len(hits), 1)
        self.assertEqual(hits[0]["keyword"], "CPI")

    def test_empty_ledger_returns_empty(self):
        hits = p2_verify_all.verify_from_ledger(Ledger(period="p"))
        self.assertEqual(hits, [])

    def test_stops_at_time_budget(self):
        """核验源不可达时最坏每词要等 2 源 × 2 页 × 超时，8 词能拖十几分钟，
        会把每日 cron 卡死。总预算到点必须收手，剩余词直接跳过。"""
        ledger = self._ledger_with_domains(["GDP", "CPI", "固定资产投资", "进出口"])
        seen = []

        fake_clock = [0.0]

        def fake_search(term, **kw):
            seen.append(term)
            fake_clock[0] += 100.0  # 每次查询耗 100 秒（模拟超时）
            from pipeline.modules.verify import VerifyHit
            return [VerifyHit(title=f"{term} 文档", url=f"http://u/{term}",
                              source="ndrc", date="20261001", agency="ndrc")]

        with patch("pipeline.modules.verify.search", side_effect=fake_search):
            hits = p2_verify_all.verify_from_ledger(
                ledger,
                max_hits_per_term=3,
                total_limit=30,
                time_budget=150.0,
                clock=lambda: fake_clock[0],
            )

        # 预算 150s / 每次 100s → 最多查 2 个词
        self.assertLessEqual(len(seen), 2, f"超预算仍继续查询: {seen}")
        self.assertEqual(len(seen), 2)


class TestSave(unittest.TestCase):
    def test_writes_verify_json(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            fp = Path(d) / "verify.json"
            p2_verify_all.save(fp, [{"keyword": "CPI", "title": "t", "url": "u"}])
            data = json.loads(fp.read_text(encoding="utf-8"))
            self.assertEqual(data["count"], 1)
            self.assertEqual(data["hits"][0]["keyword"], "CPI")


class TestRenderVerify(unittest.TestCase):
    """p5 出刊时的核验附录渲染。"""

    def _render_with(self, payload):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            data_dir = Path(d) / "20261001-20261005"
            data_dir.mkdir()
            (data_dir / "verify.json").write_text(
                json.dumps(payload, ensure_ascii=False), encoding="utf-8"
            )
            with patch("pipeline.modules.p5_publish.DATA_DIR", Path(d)):
                return p5_publish._render_verify("20261001-20261005")

    def test_grouped_by_keyword(self):
        md = self._render_with({"hits": [
            {"keyword": "CPI", "title": "T1", "url": "u1", "date": "20261001", "agency": "国家统计局"},
            {"keyword": "CPI", "title": "T2", "url": "u2", "date": "20261002", "agency": "国家统计局"},
            {"keyword": "GDP", "title": "T3", "url": "u3", "date": "20261003", "agency": "国家统计局"},
        ]})
        self.assertIn("**CPI**", md)
        self.assertIn("**GDP**", md)
        self.assertIn("T1", md)
        self.assertIn("T3", md)

    def test_empty_hits_returns_placeholder(self):
        self.assertIn("无命中", self._render_with({"hits": []}))

    def test_missing_file_returns_placeholder(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            with patch("pipeline.modules.p5_publish.DATA_DIR", Path(d)):
                md = p5_publish._render_verify("20261001-20261005")
        self.assertIn("未回查", md)

    def test_corrupt_json_returns_placeholder(self):
        import tempfile
        with tempfile.TemporaryDirectory() as d:
            data_dir = Path(d) / "20261001-20261005"
            data_dir.mkdir()
            (data_dir / "verify.json").write_text("{bad json", encoding="utf-8")
            with patch("pipeline.modules.p5_publish.DATA_DIR", Path(d)):
                md = p5_publish._render_verify("20261001-20261005")
        self.assertIn("损坏", md)

    def test_links_rendered(self):
        md = self._render_with({"hits": [
            {"keyword": "CPI", "title": "T1", "url": "http://a", "date": "", "agency": "统计局"},
        ]})
        self.assertIn("[T1](http://a)", md)


if __name__ == "__main__":
    unittest.main()
