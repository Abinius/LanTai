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
    ledger_head = "## 数据台账"
    if ledger.points:
        lines = []
        for p in ledger.points:
            line = f"- {p.indicator}={p.value}{p.unit or ''}"
            if p.yoy:
                line += f" 同比{p.yoy}"
            if p.agency:
                line += f" [{p.agency}]"
            if p.region and p.region != "全国":
                line += f" 〔{p.region}〕"
            if p.llm_unverified:
                line += " 〔未核验〕"
            line += f" {{src:{p.source_url}}}"
            lines.append(line)
        points_block = "\n".join(lines)
        region_cnt = sum(1 for p in ledger.points if p.region and p.region != "全国")
        unmarked = sum(1 for p in ledger.points if not p.region)
        unverified = sum(1 for p in ledger.points if p.llm_unverified)
        national = len(ledger.points) - region_cnt - unmarked
        ledger_head = (
            f"## 数据台账（全国口径 {national} 点 / 地域口径 {region_cnt} 点 / "
            f"未标记 {unmarked} 点 / 未核验 {unverified} 点）"
        )

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
        f"{ledger_head}\n{points_block}\n\n"
        f"## 新闻联播提要\n{xwlb_section}\n\n"
        f"## 人民日报标题清单\n{rmrb_section}\n\n"
        "按规则做三维交叉研判,只输出 JSON。"
    )


def normalize_refs(refs: Any) -> list[str]:
    """data_refs 归一成 URL 列表。

    LLM 偶尔把单条引用写成字符串而非数组——直接遍历会逐字符拆成一堆
    「URL」;写成 null 则直接 TypeError。两种都静默毁掉整份研判。
    """
    if isinstance(refs, str):
        return [refs]
    if not isinstance(refs, list):
        return []
    return [str(r) for r in refs if isinstance(r, str)]


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
            analysis.predictions.append(
                Prediction(
                    text=str(p["text"]),
                    data_refs=normalize_refs(p.get("data_refs")),
                )
            )
    return analysis
