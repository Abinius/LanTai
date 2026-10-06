"""P5 出刊引擎:模板驱动 + 变量注入。

数据表/源列表程序化渲染(数字确定性,无幻觉);标题+摘要走 LLM 短调用。
LLM 失败时用程序化兜底(标题取首条判断前 20 字,摘要拼前两条发现)。
"""

from __future__ import annotations

from typing import Optional

from pipeline import llm
from pipeline.config import PROMPTS_DIR, TEMPLATES_DIR
from pipeline.contracts import Analysis, DataPoint, Ledger, Prediction, RawItem, Report
from pipeline.modules.p3_extract import matched_categories

PUBLISH_PROMPT = (PROMPTS_DIR / "publish.md").read_text(encoding="utf-8")
TEMPLATE = (TEMPLATES_DIR / "report-template.md").read_text(encoding="utf-8")


def publish(analysis: Analysis, ledger: Ledger, raw_items: list[RawItem], period: str) -> Report:
    title, summary = _llm_title_summary(analysis, raw_items, period)
    # LLM 失败兜底:标题取首条判断,摘要拼结构性发现
    if not title:
        title = (analysis.core_judgments[0][:25] + "…") if analysis.core_judgments else f"{period} 报告"
    if not summary:
        summary = "；".join(analysis.structural_findings[:2]) or "数据不足，详见附表。"

    core_md = _render_list(analysis.core_judgments) or "_暂无_"
    findings_md = _render_list(analysis.structural_findings) or "_暂无_"
    predictions_md = _render_predictions(analysis.predictions) or "_暂无_"
    data_table_md = _render_data_table(ledger.points)
    sources_md = _render_sources(ledger.points, analysis.predictions)
    period_label = _period_label(period)

    content = (
        TEMPLATE.replace("{{title}}", title)
        .replace("{{period_label}}", period_label)
        .replace("{{summary}}", summary)
        .replace("{{core_judgments_md}}", core_md)
        .replace("{{structural_findings_md}}", findings_md)
        .replace("{{predictions_md}}", predictions_md)
        .replace("{{data_table_md}}", data_table_md)
        .replace("{{sources_md}}", sources_md)
    )

    source_count = len(_all_sources(ledger.points, analysis.predictions))
    domains, regions = _tags(ledger.points)
    return Report(
        period=period,
        title=title,
        summary=summary,
        content_md=content,
        source_count=source_count,
        domains=domains,
        regions=regions,
    )


def _llm_title_summary(analysis: Analysis, raw_items: list[RawItem], period: str) -> tuple[str, str]:
    """LLM 生成标题+摘要。失败返回 ("", "")。"""
    judgments = "\n".join(f"- {j}" for j in analysis.core_judgments)
    findings = "\n".join(f"- {f}" for f in analysis.structural_findings[:3])
    preds = "\n".join(f"- {p.text}" for p in analysis.predictions[:5])
    xwlb_titles = "；".join(i.title for i in raw_items if i.source == "xwlb")
    user = (
        f"## 区间 {period}\n## 核心判断\n{judgments or '(无)'}\n"
        f"## 结构性发现(摘录)\n{findings or '(无)'}\n"
        f"## 趋势预测(摘录)\n{preds or '(无)'}\n"
        f"## 联播主线\n{xwlb_titles or '(无)'}\n"
        "按规则写标题与摘要,只输出 JSON。"
    )
    data = llm.chat_json(
        [
            {"role": "system", "content": PUBLISH_PROMPT},
            {"role": "user", "content": user},
        ],
        max_tokens=800,
    )
    if not isinstance(data, dict):
        return "", ""
    return str(data.get("title") or ""), str(data.get("summary") or "")


def _render_list(items: list[str]) -> str:
    return "\n".join(f"{i}. {x}" for i, x in enumerate(items, 1)) if items else ""


def _render_predictions(preds: list[Prediction]) -> str:
    if not preds:
        return ""
    lines = []
    for i, p in enumerate(preds, 1):
        line = f"{i}. {p.text}"
        if p.data_refs:
            refs = ", ".join(f"[src]({r})" for r in p.data_refs[:3])
            line += f"\n   - 数据溯源: {refs}"
        lines.append(line)
    return "\n".join(lines)


def _cell(s: Optional[str]) -> str:
    """Markdown 表格单元格:竖线与换行会打断表格结构。"""
    return (s or "").replace("|", "/").replace("\n", " ").strip() or "—"


def _render_data_table(points: list[DataPoint]) -> str:
    if not points:
        return "_本期台账为空_"
    header = "| 指标 | 数值 | 单位 | 同比 | 地区 | 机构 | 源 |"
    sep = "|---|---|---|---|---|---|---|"
    rows = []
    for p in points:
        # 未核验点没有结构化数值,把正则命中的原文放进数值列,否则整行只有个链接
        val = p.value or (p.raw_text if p.llm_unverified else None)
        rows.append(
            f"| {_cell(p.indicator)} | {_cell(val)} | {_cell(p.unit)} | "
            f"{_cell(p.yoy)} | {_cell(p.region)} | {_cell(p.agency)} | [↗]({p.source_url}) |"
        )
    return "\n".join([header, sep, *rows])


def _render_sources(points: list[DataPoint], preds: list[Prediction]) -> str:
    srcs = _all_sources(points, preds)
    if not srcs:
        return "_无_"
    return "\n".join(f"- {s}" for s in srcs)


def _all_sources(points: list[DataPoint], preds: list[Prediction]) -> list[str]:
    seen: list[str] = []
    for p in points:
        if p.source_url and p.source_url not in seen:
            seen.append(p.source_url)
    for pred in preds:
        for r in pred.data_refs:
            if r and r not in seen:
                seen.append(r)
    return seen


def _tags(points: list[DataPoint]) -> tuple[list[str], list[str]]:
    """从台账派生订阅标签:领域(关键词命中)与地区(台账 region 字段)。

    领域与地区是订阅匹配的两条轴(PRD §3.9 兴趣标签 = 领域 × 地区)。
    地区直接取台账字段,领域复用抽取阶段的关键词分类,单一事实来源。
    """
    domains: list[str] = []
    regions: list[str] = []
    for p in points:
        for d in matched_categories(f"{p.indicator} {p.raw_text}"):
            if d not in domains:
                domains.append(d)
        if p.region and p.region not in regions:
            regions.append(p.region)
    return domains, regions


def _period_label(period: str) -> str:
    if "-" in period:
        a, b = period.split("-", 1)
        return f"{a[:4]}-{a[4:6]}-{a[6:8]} ~ {b[:4]}-{b[4:6]}-{b[6:8]}"
    return f"{period[:4]}-{period[4:6]}-{period[6:8]}"
