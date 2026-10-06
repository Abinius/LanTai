"""P3 抽取:正则预筛九类指标 + LLM 结构化 → 数据台账。

九类(PRD §3.3):增长/投资/消费/外贸/物价/金融/能源/财政/就业。
LLM 失败/限流时,正则命中片段仍入台账(标 llm_unverified=True),不阻断流水线。
"""

from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any, Optional

from pipeline import llm
from pipeline.contracts import DataPoint, Ledger, RawItem
from pipeline.config import PROMPTS_DIR

# 九类指标关键词。同时服务两处:正则预筛(是否值得送 LLM)与订阅领域标签派生。
CATEGORY_KEYWORDS = {
    "增长": r"GDP|生产总值|增加值|增长|增速",
    "投资": r"固定资产投资|投资|招商引资",
    "消费": r"社会消费品零售|消费|零售额",
    "外贸": r"进出口|出口|进口|贸易顺差|贸易逆差|外贸",
    "物价": r"CPI|PPI|物价|居民消费价格|工业生产者出厂价格",
    "金融": r"M2|广义货币|社融|社会融资|人民币贷款|存款",
    "能源": r"发电量|用电量|能源消费|原煤|原油|天然气",
    "财政": r"财政收入|财政支出|税收|一般公共预算",
    "就业": r"就业|失业率|城镇调查失业率|新增就业",
}
_ALL_KW = re.compile("|".join(CATEGORY_KEYWORDS.values()))
# 数值模式:百分比、带单位的大数、纯小数
_NUMBER = re.compile(r"\d+(?:\.\d+)?\s*%|(?:\d+(?:\.\d+)?)\s*(?:万亿元|亿元|万亿美元|亿|万吨|万人|亿人次|亿千瓦时)")
_SENT_SPLIT = re.compile(r"[。！？；\n]+")

EXTRACT_PROMPT = (PROMPTS_DIR / "extract.md").read_text(encoding="utf-8")


def matched_categories(text: str) -> list[str]:
    """返回文本命中的领域类别,保持注册顺序。订阅领域标签的事实来源。"""
    return [name for name, pattern in CATEGORY_KEYWORDS.items() if re.search(pattern, text)]


def extract(raw_items: list[RawItem], period: str) -> Ledger:
    ledger = Ledger(period=period)
    for item in raw_items:
        candidates = _pre_filter(item.body)
        if not candidates:
            continue
        points = _llm_extract(item, candidates)
        if points is None:
            # LLM 失败:正则命中片段作为未核验点保留(避 sensenova 限流阻断)
            points = _fallback_points(item, candidates)
        ledger.points.extend(points)
    return ledger


def _pre_filter(body: str) -> list[str]:
    """分句后保留含宏观关键词 + 数值的句子。"""
    out: list[str] = []
    for sent in _SENT_SPLIT.split(body):
        sent = sent.strip()
        if not sent or len(sent) < 8:
            continue
        if _ALL_KW.search(sent) and _NUMBER.search(sent):
            out.append(sent)
    # 去重,控制 LLM 输入长度
    seen = set()
    uniq = []
    for s in out:
        if s not in seen:
            seen.add(s)
            uniq.append(s)
    return uniq[:40]  # 单篇最多送 40 句,控成本


def _llm_extract(item: RawItem, candidates: list[str]) -> list[DataPoint] | None:
    snippet = "\n".join(candidates)
    user_msg = (
        f"来源 URL: {item.url}\n日期: {item.date}\n正文片段:\n{snippet}\n\n按规则抽取数据点,只输出 JSON 数组。"
    )
    data = llm.chat_json(
        [
            {"role": "system", "content": EXTRACT_PROMPT},
            {"role": "user", "content": user_msg},
        ],
        max_tokens=2500,
    )
    if not isinstance(data, list):
        return None
    points: list[DataPoint] = []
    for dp in data:
        if not isinstance(dp, dict) or "indicator" not in dp or "value" not in dp:
            continue
        points.append(
            DataPoint(
                indicator=str(dp.get("indicator", "")),
                value=str(dp.get("value", "")),
                source_url=item.url,
                raw_text=_find_snippet(candidates, str(dp.get("value", ""))),
                agency=_clean(dp.get("agency")) or "",
                unit=_clean(dp.get("unit")),
                scope=_clean(dp.get("scope")),
                yoy=_clean(dp.get("yoy")),
                mom=_clean(dp.get("mom")),
                pub_date=_clean(dp.get("pub_date")),
                region=_normalize_region(_clean(dp.get("region"))),
            )
        )
    return points


def _fallback_points(item: RawItem, candidates: list[str]) -> list[DataPoint]:
    """LLM 不可用时,把每个命中句子作为一个未核验点,保留可溯源 URL。"""
    return [
        DataPoint(
            indicator="(待核验)",
            value="",
            source_url=item.url,
            raw_text=s,
            agency="",
            llm_unverified=True,
        )
        for s in candidates
    ]


def _find_snippet(candidates: list[str], value: str) -> str:
    if not value:
        return ""
    for c in candidates:
        if value in c:
            return c
    return candidates[0] if candidates else ""


def _clean(val: Any) -> Optional[str]:
    """LLM 偶尔输出字符串 "null" 而非 JSON null,统一归一为空。"""
    if val is None:
        return None
    s = str(val).strip()
    return s if s.lower() != "null" else None


# 四个封闭类别值,不参与行政后缀归一(尤其"地区"是境外/跨区域类别,不能被截断)
REGION_CATEGORIES = ("全国", "县域", "地区")
# 行政区划后缀,长者优先。归一到裸省名(与台账中占多数的写法一致),
# 否则同一数据在不同文章里会散成 海南/海南省、安徽/安徽省 两类标签。
REGION_SUFFIXES = ("特别行政区", "自治区", "自治州", "省", "市")


def _normalize_region(region: Optional[str]) -> Optional[str]:
    """海南省→海南、内蒙古自治区→内蒙古;封闭类别与"浦东新区"等不受影响。"""
    if not region or region in REGION_CATEGORIES:
        return region
    for suf in REGION_SUFFIXES:
        if region.endswith(suf) and len(region) > len(suf):
            return region[: -len(suf)]
    return region
