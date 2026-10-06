"""P4 三维交叉研判:数据 × 官媒 × 同频-背离。

输入:数据台账(ledger)+ 官媒文本(raw_items)。
输出:核心矛盾判断、结构性发现、≥5 条带数据支撑的趋势预测。
LLM 失败时返回空 Analysis(M3 出刊仍可渲染,只是研判缺失)。
"""

from __future__ import annotations

from pathlib import Path
from typing import Any

from pipeline import llm
from pipeline.contracts import Analysis, Ledger, Prediction, RawItem
from pipeline.config import PROMPTS_DIR

ANALYZE_PROMPT = (PROMPTS_DIR / "analyze.md").read_text(encoding="utf-8")


def analyze(ledger: Ledger, raw_items: list[RawItem], period: str) -> Analysis:
    ctx = _build_context(ledger, raw_items)
    data = llm.chat_json(
        [
            {"role": "system", "content": ANALYZE_PROMPT},
            {"role": "user", "content": ctx},
        ],
        max_tokens=3000,
    )
    return _parse(data, period)


def _build_context(ledger: Ledger, raw_items: list[RawItem]) -> str:
    # 数据面:台账数据点(精简,只留关键字段)
    points_block = "暂无数据点"
    if ledger.points:
        lines = []
        for p in ledger.points:
            line = f"- {p.indicator}={p.value}{p.unit or ''}"
            if p.yoy:
                line += f" 同比{p.yoy}"
            if p.agency:
                line += f" [{p.agency}]"
            line += f" {{src:{p.source_url}}}"
            lines.append(line)
        points_block = "\n".join(lines)

    # 官媒面:新闻联播提要(已精简)+ 人民日报标题清单
    xwlb_blocks = []
    rmrb_titles = []
    for item in raw_items:
        if item.source == "xwlb":
            xwlb_blocks.append(f"【联播 {item.date}】{item.title}\n{item.body[:600]}")
        elif item.source == "rmrb":
            rmrb_titles.append(item.title)
    xwlb_section = "\n\n".join(xwlb_blocks) if xwlb_blocks else "暂无联播提要"
    rmrb_section = "、".join(rmrb_titles) if rmrb_titles else "暂无人民日报标题"

    return (
        f"## 数据台账\n{points_block}\n\n"
        f"## 新闻联播提要\n{xwlb_section}\n\n"
        f"## 人民日报标题清单\n{rmrb_section}\n\n"
        "按规则做三维交叉研判,只输出 JSON。"
    )


def _parse(data: Any, period: str) -> Analysis:
    analysis = Analysis(period=period)
    if not isinstance(data, dict):
        return analysis
    cj = data.get("core_judgments")
    if isinstance(cj, list):
        analysis.core_judgments = [str(x) for x in cj if isinstance(x, str)]
    sf = data.get("structural_findings")
    if isinstance(sf, list):
        analysis.structural_findings = [str(x) for x in sf if isinstance(x, str)]
    preds = data.get("predictions")
    if isinstance(preds, list):
        for p in preds:
            if not isinstance(p, dict) or not p.get("text"):
                continue
            refs = p.get("data_refs", [])
            analysis.predictions.append(
                Prediction(
                    text=str(p["text"]),
                    data_refs=[str(r) for r in refs if isinstance(r, str)],
                )
            )
    return analysis
