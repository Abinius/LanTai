"""兰台观局 v3.0 内核编排器。

用法:
    python pipeline/run.py --from 2026-10-01 --to 2026-10-05
    python pipeline/run.py --days 7
    python pipeline/run.py --reextract  # 跳过 P2,从已有 raw 重跑 P3

退出码:0=全成功;1=部分失败但 raw 已落盘;2=参数或凭证错误。
M2 接入点:在 P3 之后串联 p4_analyze;M3 再接 p5_publish。
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
import traceback
from dataclasses import asdict
from pathlib import Path

# Windows 控制台默认 cp936,强制 stdout/stderr UTF-8 让中文日志不乱码
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")

# 让 `from pipeline...` 在 `python pipeline/run.py` 调用下生效
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from pipeline.config import DATA_DIR, SOURCES, get_collector
from pipeline.contracts import RawItem
from pipeline.modules import p1_interval, p3_extract


def main() -> int:
    parser = argparse.ArgumentParser(description="兰台观局 v3.0 内核")
    parser.add_argument("--from", dest="from_", help="区间起点 YYYY-MM-DD")
    parser.add_argument("--to", dest="to", help="区间终点 YYYY-MM-DD")
    parser.add_argument("--days", type=int, help="最近 N 天(与 --from/--to 互斥)")
    parser.add_argument("--reextract", action="store_true", help="跳过 P2,从已有 raw 重跑 P3")
    args = parser.parse_args()

    try:
        days = p1_interval.parse_range(args)
    except ValueError as e:
        print(f"[p1] 区间错误: {e}", file=sys.stderr)
        return 2
    period = p1_interval.period_label(days)
    out_dir = DATA_DIR / period
    (out_dir / "raw").mkdir(parents=True, exist_ok=True)
    log_path = out_dir / "run.log"

    def log(msg: str) -> None:
        line = f"[{time.strftime('%H:%M:%S')}] {msg}"
        print(line)
        with open(log_path, "a", encoding="utf-8") as f:
            f.write(line + "\n")

    log(f"=== 兰台观局 v3.0 开跑 period={period} days={len(days)} ===")

    p2_fail = False
    if not args.reextract:
        for date_str in days:
            for source in SOURCES:
                collector = get_collector(source)
                try:
                    items, logs = collector.fetch(date_str)
                except Exception as e:
                    log(f"[p2/{source}/{date_str}] 异常: {e}\n{traceback.format_exc()}")
                    p2_fail = True
                    continue
                for line in logs:
                    log(line)
                _write_raw(out_dir, source, date_str, items)
                log(f"[p2/{source}/{date_str}] 落盘 {len(items)} 条")
    else:
        log("--reextract:跳过 P2,使用已有 raw")

    # P3:从 raw 落盘读回,独立可重跑
    raw_items = _load_all_raw(out_dir)
    log(f"[p3] 读回 {len(raw_items)} 条 raw,开始抽取")
    try:
        ledger = p3_extract.extract(raw_items, period)
    except Exception as e:
        log(f"[p3] 异常: {e}\n{traceback.format_exc()}")
        return 1 if p2_fail else 1

    ledger_path = out_dir / "ledger.json"
    ledger_path.write_text(ledger.to_json(), encoding="utf-8")
    log(f"[p3] 台账写入 {ledger_path} points={len(ledger.points)}")

    # M2 接入点:在此串联 p4_analyze(ledger, raw_items) → analysis.json
    # M3 接入点:再接 p5_publish(analysis) → report.md

    rc = 0 if not p2_fail else 1
    log(f"=== 完成 退出码={rc} ===")
    return rc


def _write_raw(out_dir: Path, source: str, date_str: str, items: list[RawItem]) -> None:
    sub = out_dir / "raw" / source
    sub.mkdir(parents=True, exist_ok=True)
    for idx, item in enumerate(items, 1):
        fn = sub / f"{date_str}_{idx:03d}.json"
        fn.write_text(
            json.dumps(asdict(item), ensure_ascii=False, indent=2),
            encoding="utf-8",
        )


def _load_all_raw(out_dir: Path) -> list[RawItem]:
    raw_root = out_dir / "raw"
    items: list[RawItem] = []
    if not raw_root.exists():
        return items
    for src_dir in sorted(raw_root.iterdir()):
        if not src_dir.is_dir():
            continue
        for fp in sorted(src_dir.glob("*.json")):
            try:
                d = json.loads(fp.read_text(encoding="utf-8"))
            except json.JSONDecodeError:
                continue
            items.append(
                RawItem(
                    source=d.get("source", src_dir.name),
                    date=d.get("date", ""),
                    title=d.get("title", ""),
                    url=d.get("url", ""),
                    body=d.get("body", ""),
                    published_at=d.get("published_at"),
                    summary=d.get("summary"),
                    fetched_bytes=d.get("fetched_bytes", 0),
                )
            )
    return items


if __name__ == "__main__":
    raise SystemExit(main())
