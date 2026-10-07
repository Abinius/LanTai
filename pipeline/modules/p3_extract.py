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

# URL 里显式的日期:rmrb 正文路径 content/YYYYMM/DD/,通用 tYYYYMMDD 参数
_URL_DATE_CONTENT = re.compile(r"/content/(\d{6})/(\d{2})/")
_URL_DATE_QUERY = re.compile(r"[?&]t=(\d{8})")

# 地级市/自治州 → 省级。订阅地区轴只有省级候选,地市级数据归到所属省才可订阅。
# 覆盖台账里常见地名(含民族自治州与特殊功能区)。
CITY_TO_PROVINCE = {
    # 直辖市本身即省级
    "北京": "北京", "上海": "上海", "天津": "天津", "重庆": "重庆",
    # 江苏
    "南京": "江苏", "苏州": "江苏", "无锡": "江苏", "常州": "江苏", "徐州": "江苏",
    "南通": "江苏", "扬州": "江苏", "镇江": "江苏", "泰州": "江苏",
    # 浙江
    "杭州": "浙江", "宁波": "浙江", "温州": "浙江", "嘉兴": "浙江",
    "绍兴": "浙江", "金华": "浙江", "台州": "浙江",
    # 广东
    "深圳": "广东", "广州": "广东", "珠海": "广东", "佛山": "广东",
    "东莞": "广东", "惠州": "广东", "汕头": "广东", "湛江": "广东",
    "中山": "广东", "肇庆": "广东", "江门": "广东",
    "前海合作区": "广东", "横琴粤澳": "广东", "粤港澳大湾区": "广东",
    # 福建
    "厦门": "福建", "福州": "福建", "泉州": "福建",
    # 山东
    "青岛": "山东", "济南": "山东", "烟台": "山东", "威海": "山东",
    "临沂": "山东", "潍坊": "山东", "淄博": "山东",
    # 川渝
    "成都": "四川", "绵阳": "四川", "重庆": "重庆", "万州": "重庆",
    # 华中
    "武汉": "湖北", "宜昌": "湖北", "长沙": "湖南", "株洲": "湖南",
    "郑州": "河南", "洛阳": "河南", "合肥": "安徽", "芜湖": "安徽",
    "南昌": "江西", "太原": "山西",
    # 东北
    "沈阳": "辽宁", "大连": "辽宁", "长春": "吉林", "哈尔滨": "黑龙江",
    "齐齐哈尔": "黑龙江", "大庆": "黑龙江",
    # 西部
    "西安": "陕西", "咸阳": "陕西", "兰州": "甘肃", "乌鲁木齐": "新疆",
    "昆明": "云南", "贵阳": "贵州", "南宁": "广西", "海口": "海南",
    "拉萨": "西藏", "银川": "宁夏", "呼和浩特": "内蒙古",
    "西宁": "青海", "银川": "宁夏",
    # 边境口岸/特殊功能区
    "吉隆口岸": "云南", "霍尔果斯": "新疆", "满洲里": "内蒙古",
    "霍尔果斯口岸": "新疆",
    # 民族自治州(地级)
    "湘西": "湖南", "黔东南": "贵州", "黔南": "贵州", "黔西南": "贵州",
    "文山": "云南", "湘西州": "湖南", "延边": "吉林", "延边朝鲜族": "吉林",
}


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
    ledger.points = _dedupe_points(ledger.points)
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
        indicator = str(_clean(dp.get("indicator")) or "")
        value = str(_clean(dp.get("value")) or "")
        raw_text = _find_snippet(candidates, value)
        points.append(
            DataPoint(
                indicator=indicator,
                value=value,
                source_url=item.url,
                raw_text=raw_text,
                agency=_clean(dp.get("agency")) or "",
                unit=_validate_unit(_clean(dp.get("unit")), raw_text),
                scope=_clean(dp.get("scope")),
                yoy=_clean(dp.get("yoy")),
                mom=_clean(dp.get("mom")),
                # pub_date 三级回落:LLM 抽到的真实日期 → URL 里的显式日期(rmrb 正文路径/YYYYMM/DD/)
                # → 采集目标日(xwlb 无 URL 日期模式,广播日是最接近的合法日期)。
                pub_date=_clean(dp.get("pub_date")) or _url_date(item.url) or _safe_date(item.date),
                region=_normalize_region(_clean(dp.get("region")), indicator),
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
REGION_SUFFIXES = ("特别行政区", "自治区", "自治州", "省", "市", "县")
# 带民族名的自治区:直接按后缀剥会得到"广西壮族""新疆维吾尔"这类
# 既不在候选表、又与裸名写法分裂的标签,故显式映射。
REGION_ALIASES = {
    "广西壮族自治区": "广西",
    "宁夏回族自治区": "宁夏",
    "新疆维吾尔自治区": "新疆",
}


def _normalize_region(region: Optional[str], indicator: str = "") -> Optional[str]:
    """海南省→海南、内蒙古自治区→内蒙古;县级/县级行政区→所属省份;封闭类别与「京津冀地区」等不受影响。

    地区标签只认省级候选(站端 REGIONS 表),因此地市级数据要归到所属省;
    县域点原本只标类别不带具体地名,升级尝试失败则原样返回「县域」。
    """
    if not region:
        return region
    if region in REGION_CATEGORIES:
        # 县域点若指标名里带可识别地名(如「前海合作区」「苏州」),升级为所属省,
        # 让这类点也可被订阅地区轴命中。
        if region == "县域" and indicator:
            city = _extract_city_name(indicator)
            if city:
                return CITY_TO_PROVINCE.get(city)
        return region
    if region in REGION_ALIASES:
        return REGION_ALIASES[region]
    for suf in REGION_SUFFIXES:
        if region.endswith(suf) and len(region) > len(suf):
            bare = region[: -len(suf)]
            # 后缀剥完后是地市名(如「南京市」→「南京」),继续归到省级
            if bare in CITY_TO_PROVINCE:
                return CITY_TO_PROVINCE[bare]
            return bare
    # 裸地市名(如 LLM 直接吐「苏州」/「深圳」)
    return CITY_TO_PROVINCE.get(region, region)


def _extract_city_name(indicator: str) -> Optional[str]:
    """从指标名里找首个命中的 CITY_TO_PROVINCE 键。"""
    if not indicator:
        return None
    for city in CITY_TO_PROVINCE:
        if city in indicator:
            return city
    return None


def _url_date(url: str) -> Optional[str]:
    """从 URL 里提取显式日期(rmrb 正文路径 /YYYYMM/DD/ 或 ?t=YYYYMMDD)。解析不出返 None,不造假。"""
    if not url:
        return None
    m = _URL_DATE_CONTENT.search(url)
    if m:
        return f"{m.group(1)}{m.group(2)}"
    m = _URL_DATE_QUERY.search(url)
    if m:
        return _safe_date(m.group(1))
    return None


def _safe_date(s: Optional[str]) -> Optional[str]:
    """校验 YYYYMMDD 合法性。RawItem.date 由 p1 生成恒合法,仍加守卫避免未来格式漂移。"""
    if not s or len(s) != 8 or not s.isdigit():
        return None
    m, d = int(s[4:6]), int(s[6:8])
    if not (1 <= m <= 12 and 1 <= d <= 31):
        return None
    return s


def _validate_unit(unit: Optional[str], raw_text: str) -> Optional[str]:
    """修正 LLM 拆分「4323.1 亿美元」时把「亿」当数量词丢掉、unit 只吐「美元」的情况。
    其他单位一律原样保留——过度白名单校验会误杀真单位(如「美元/吨」)。"""
    if not unit or not raw_text:
        return unit
    if unit == "美元":
        if "亿美元" in raw_text:
            return "亿美元"
        if "万美元" in raw_text:
            return "万美元"
    return unit


def _dedupe_points(points: list[DataPoint]) -> list[DataPoint]:
    """按 (indicator, value, source_url) 去重。同一 URL 里同一指标被 LLM 抽两次的情况;
    同一段 raw_text 拆出的不同指标不算重复(是不同维度)。"""
    seen: set[tuple[str, str, str]] = set()
    out: list[DataPoint] = []
    for p in points:
        key = (p.indicator, p.value, p.source_url)
        if key in seen:
            continue
        seen.add(key)
        out.append(p)
    return out
