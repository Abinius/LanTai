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
from pipeline.contracts import Ledger, RawItem
from pipeline.modules import p1_interval, p3_extract, p4_analyze, p5_publish


def main() -> int:
    parser = argparse.ArgumentParser(description="兰台观局 v3.0 内核")
    parser.add_argument("--from", dest="from_", help="区间起点 YYYY-MM-DD")
    parser.add_argument("--to", dest="to", help="区间终点 YYYY-MM-DD")
    parser.add_argument("--days", type=int, help="最近 N 天(与 --from/--to 互斥)")
    parser.add_argument("--reextract", action="store_true", help="跳过 P2,从已有 raw 重跑 P3")
    parser.add_argument("--reanalyze", action="store_true", help="跳过 P2/P3,从已有 ledger+raw 重跑 P4")
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
    run_p3 = not args.reanalyze
    if not args.reextract and not args.reanalyze:
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
        skipped = "P2/P3" if args.reanalyze else "P2"
        log(f"--{'reanalyze' if args.reanalyze else 'reextract'}:跳过 {skipped},使用已有数据")

    # P3:从 raw 落盘读回,独立可重跑
    raw_items = _load_all_raw(out_dir)
    if run_p3:
        log(f"[p3] 读回 {len(raw_items)} 条 raw,开始抽取")
        try:
            ledger = p3_extract.extract(raw_items, period)
        except Exception as e:
            log(f"[p3] 异常: {e}\n{traceback.format_exc()}")
            return 1

        ledger_path = out_dir / "ledger.json"
        ledger_path.write_text(ledger.to_json(), encoding="utf-8")
        log(f"[p3] 台账写入 {ledger_path} points={len(ledger.points)}")
    else:
        ledger = _load_ledger(out_dir)
        if ledger is None:
            log("[p4] 无 ledger.json,无法研判")
            return 2
        log(f"[p4] 读回 ledger {len(ledger.points)} 点,跳过 P3")

    # P4:三维交叉研判
    log(f"[p4] 读回 {len(raw_items)} 条 raw,开始研判")
    try:
        analysis = p4_analyze.analyze(ledger, raw_items, period)
    except Exception as e:
        log(f"[p4] 异常: {e}\n{traceback.format_exc()}")
        analysis = p4_analyze.Analysis(period=period)

    analysis_path = out_dir / "analysis.json"
    analysis_path.write_text(analysis.to_json(), encoding="utf-8")
    log(
        f"[p4] 研判写入 {analysis_path} "
        f"judgments={len(analysis.core_judgments)} "
        f"findings={len(analysis.structural_findings)} "
        f"predictions={len(analysis.predictions)}"
    )

    # P5:出刊引擎(模板驱动 + 变量注入)
    log("[p5] 开始出刊")
    try:
        report = p5_publish.publish(analysis, ledger, raw_items, period)
    except Exception as e:
        log(f"[p5] 异常: {e}\n{traceback.format_exc()}")
        report = p5_publish.Report(period=period, title=f"{period} 报告(出刊失败)", content_md="")

    report_path = out_dir / "report.md"
    report_meta_path = out_dir / "report.json"
    report_path.write_text(report.content_md, encoding="utf-8")
    report_meta_path.write_text(report.to_json(), encoding="utf-8")
    log(f"[p5] 报告写入 {report_path} ({len(report.content_md)} 字, sources={report.source_count})")

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


def _load_ledger(out_dir: Path) -> Ledger | None:
    fp = out_dir / "ledger.json"
    if not fp.exists():
        return None
    try:
        d = json.loads(fp.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return None
    from pipeline.contracts import DataPoint

    points = [
        DataPoint(
            indicator=p.get("indicator", ""),
            value=p.get("value", ""),
            source_url=p.get("source_url", ""),
            raw_text=p.get("raw_text", ""),
            agency=p.get("agency", "") or "",
            unit=p.get("unit"),
            scope=p.get("scope"),
            yoy=p.get("yoy"),
            mom=p.get("mom"),
            pub_date=p.get("pub_date"),
            llm_unverified=p.get("llm_unverified", False),
        )
        for p in d.get("points", [])
        if isinstance(p, dict)
    ]
    return Ledger(period=d.get("period", ""), points=points)


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
