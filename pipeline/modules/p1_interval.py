"""P1 区间解析:--from/--to 或 --days → [YYYYMMDD...] 列表。"""

from __future__ import annotations

import argparse
from datetime import date, datetime, timedelta


def parse_range(args: argparse.Namespace) -> list[str]:
    if args.from_ and args.to:
        start = _parse_date(args.from_)
        end = _parse_date(args.to)
    elif args.days:
        end = date.today()
        start = end - timedelta(days=args.days - 1)
    else:
        # 默认最近 7 天
        end = date.today()
        start = end - timedelta(days=6)

    if start > end:
        raise ValueError(f"区间起点 {start} 晚于终点 {end}")

    days: list[str] = []
    cur = start
    while cur <= end:
        days.append(cur.strftime("%Y%m%d"))
        cur += timedelta(days=1)
    return days


def _parse_date(s: str) -> date:
    return datetime.strptime(s, "%Y-%m-%d").date()


def period_label(days: list[str]) -> str:
    """区间标签,用于落盘目录名。"""
    if not days:
        return "empty"
    if len(days) == 1:
        return days[0]
    return f"{days[0]}-{days[-1]}"
