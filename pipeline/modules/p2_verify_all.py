"""P2.5 核验源闭环:按当期台账主要领域回查官方核验源。

在 P3 抽取之后、P4 研判之前跑。让 P0-2 的 verify 模块从「独立查询」变成
「主流水线自动闭环」:每期出刊前,自动挑出当期台账主要领域,用领域对应的
具体核验关键词回查发改委/文旅官方口径,把命中文档写进 verify.json
供 P5 出刊时展示为附录。

设计要点:
- 不重造 verify.py 逻辑,复用 verify.search()
- 关键词从当期台账主要领域派生(不预设硬编码列表)——避免与当期内容无关的浪费
- 每个领域挑 1 个具体关键词(不用领域名本身,如"增长"太泛,用"GDP""工业增加值")
- 每关键词限 hits、总量 cap,防止报告附录无限膨胀
- 单个关键词失败不影响其它(网络错、超时、非 200 都跳)
"""

from __future__ import annotations

import json
from pathlib import Path

from pipeline.contracts import Ledger
from pipeline.modules import verify
from pipeline.modules.p3_extract import matched_categories

# 领域 → 具体核验关键词。发改委/文旅常发文的具体口径,检索效率高。
# 不用领域名本身("增长"太泛),挑该领域下最常见的具体指标名。
DOMAIN_TO_KEYWORDS = {
    "增长": ["GDP", "工业增加值", "生产总值"],
    "投资": ["固定资产投资", "投资", "制造业投资"],
    "消费": ["社会消费品零售", "零售额", "消费"],
    "外贸": ["进出口", "外贸", "出口"],
    "物价": ["CPI", "PPI"],
    "金融": ["M2", "社融", "人民币贷款"],
    "能源": ["用电量", "发电量", "能源消费"],
    "财政": ["财政收入", "税收"],
    "就业": ["就业", "失业率"],
}


def derive_search_terms(ledger: Ledger, max_terms: int = 8) -> list[str]:
    """从当期台账主要领域挑具体核验关键词。

    先跑 matched_categories 挑出当期领域,再对每个领域从 DOMAIN_TO_KEYWORDS
    里选该领域下首个在台账文本中出现的关键词(避免搜不存在于本期的口径)。
    """
    text = " ".join((p.indicator or "") + " " + (p.raw_text or "") for p in ledger.points)
    domains = matched_categories(text)

    terms: list[str] = []
    for domain in domains:
        for kw in DOMAIN_TO_KEYWORDS.get(domain, []):
            if kw in text:
                terms.append(kw)
                break
        if len(terms) >= max_terms:
            break
    return terms


def verify_from_ledger(
    ledger: Ledger,
    max_terms: int = 8,
    max_hits_per_term: int = 3,
    total_limit: int = 30,
) -> list[dict]:
    """对每个关键词回查核验源,汇总去重后返回 hits(dict 列表)。

    每条 hit 附加 `keyword` 字段标明触发命中的台账领域关键词。
    """
    terms = derive_search_terms(ledger, max_terms=max_terms)
    out: list[dict] = []
    seen_urls: set[str] = set()

    for term in terms:
        try:
            hits = verify.search(term, max_pages=2)
        except Exception:
            # 单个关键词失败(网络错、编码异常)不阻断其它
            continue

        count = 0
        for h in hits:
            if h.url in seen_urls or count >= max_hits_per_term:
                continue
            seen_urls.add(h.url)
            row = {"keyword": term, "title": h.title, "url": h.url,
                   "source": h.source, "date": h.date, "agency": h.agency}
            out.append(row)
            count += 1
            if len(out) >= total_limit:
                return out

    return out


def save(verify_path: Path, hits: list[dict]) -> None:
    """写 verify.json 到 period 目录。"""
    verify_path.write_text(
        json.dumps({"hits": hits, "count": len(hits)}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
