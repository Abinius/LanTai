"""新闻联播适配器:CNTV API 直连,一天一条提要。

避雷 1/9:用 time 字段拿真实播出时间,不用抓取时间。
提要 brief 即当日全部正文,不再抓节目页。
"""

from __future__ import annotations

import json
from typing import Any

from pipeline.contracts import RawItem
from pipeline.modules.p2_collect.base import BaseCollector

CNTV_API = (
    "https://api.cntv.cn/NewVideo/getVideoListByColumn"
    "?id=TOPC1451528971114112&n=50&sort=desc&p=1&mode=0&serviceId=tvcctv"
)


class XwlbCollector(BaseCollector):
    name = "xwlb"

    def fetch(self, date_str: str) -> tuple[list[RawItem], list[str]]:
        target = f"{date_str[:4]}-{date_str[4:6]}-{date_str[6:8]}"
        code, body = self._http_get(CNTV_API)
        logs = [f"[xwlb/{date_str}] CNTV API HTTP {code} {len(body)}B"]

        if code != 200 or not body:
            return [], logs + [f"[xwlb/{date_str}] API 不可用,放弃"]

        try:
            data = json.loads(body.decode("utf-8"))
        except json.JSONDecodeError as e:
            return [], logs + [f"[xwlb/{date_str}] JSON 解析失败: {e}"]

        items = data.get("data", {}).get("list", []) or []
        # 按播出日期过滤;同日多条(19:00 首播 + 21:00 重播)取最早一条
        same_day: list[dict[str, Any]] = []
        for it in items:
            t = it.get("time", "")
            if t.startswith(target):
                same_day.append(it)

        if not same_day:
            return [], logs + [f"[xwlb/{date_str}] 当日无联播条目"]

        same_day.sort(key=lambda x: x.get("time", ""))
        picked = same_day[0]
        title = (picked.get("title") or "").strip()
        brief = (picked.get("brief") or "").strip()
        url = picked.get("url") or ""
        published_at = picked.get("time")

        raw = RawItem(
            source="xwlb",
            date=date_str,
            title=title,
            url=url,
            body=brief,  # 提要即正文
            summary=brief[:200] if brief else None,
            published_at=published_at,
            fetched_bytes=len(body),
        )
        logs.append(
            f"[xwlb/{date_str}] 命中 {len(same_day)} 条,取 {published_at} 那期,"
            f"title={title[:40]!r}, body={len(brief)}字"
        )
        return [raw], logs
