"""流水线数据契约。模块之间只传这些结构化类型,任一步可独立替换/重跑。"""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from typing import Optional


@dataclass
class RawItem:
    """P2 采集产物:一篇官媒原文。"""

    source: str  # "xwlb" | "rmrb"
    date: str  # YYYYMMDD,目标采集日(用于落盘分组)
    title: str
    url: str
    body: str
    published_at: Optional[str] = None  # ISO 8601,信源真实发布/播出时间
    summary: Optional[str] = None
    fetched_bytes: int = 0  # 诊断用


@dataclass
class DataPoint:
    """P3 抽取产物:一个量化数据点。"""

    indicator: str
    value: str  # 保留原文数值,不做单位换算
    source_url: str  # 必须可溯源到原文
    raw_text: str  # 原文切片,便于人工核对
    agency: str = ""  # 发布机构白名单之一
    unit: Optional[str] = None
    scope: Optional[str] = None  # 单月 | 累计 | 季度
    yoy: Optional[str] = None  # 同比
    mom: Optional[str] = None  # 环比
    pub_date: Optional[str] = None  # YYYYMMDD;解析不出留 None,禁止用抓取时间冒充
    llm_unverified: bool = False  # LLM 失败时正则命中仍入台账,标记未核验


@dataclass
class Ledger:
    """P3 产出:数据台账。"""

    period: str  # 区间,如 20261001-20261005
    points: list[DataPoint] = field(default_factory=list)
    generated_at: str = ""

    def to_json(self) -> str:
        import json

        self.generated_at = datetime.now(timezone.utc).isoformat()
        return json.dumps(
            {
                "period": self.period,
                "generated_at": self.generated_at,
                "points": [asdict(p) for p in self.points],
            },
            ensure_ascii=False,
            indent=2,
        )


def raw_item_to_dict(item: RawItem) -> dict:
    return asdict(item)
