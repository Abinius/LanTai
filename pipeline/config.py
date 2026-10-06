"""全局配置:信源注册、LLM 凭证读取。凭证不入库。"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Optional

# 仓库根:pipeline/ 的父目录
REPO_ROOT = Path(__file__).resolve().parent.parent
PIPELINE_ROOT = Path(__file__).resolve().parent
DATA_DIR = PIPELINE_ROOT / "data"
PROMPTS_DIR = PIPELINE_ROOT / "prompts"
TEMPLATES_DIR = PIPELINE_ROOT / "templates"

# LLM 凭证文件(上级目录,已 gitignore)。优先环境变量覆盖。
LLM_KEY_FILE = REPO_ROOT.parent / "llm key.txt"
LLM_BASE_URL = "https://token.sensenova.cn/v1/chat/completions"
LLM_MODEL = "sensenova-6.8-flash-lite"


def load_llm_key() -> Optional[str]:
    env = os.environ.get("LANTAI_LLM_KEY")
    if env:
        return env.strip()
    if LLM_KEY_FILE.exists():
        for line in LLM_KEY_FILE.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line.startswith("sk-"):
                return line
    return None


# 信源注册:source 名 → 适配器导入路径
SOURCES = {
    "xwlb": "pipeline.modules.p2_collect.xwlb.XwlbCollector",
    "rmrb": "pipeline.modules.p2_collect.rmrb.RmrbCollector",
}


def get_collector(source: str):
    """按注册表导入适配器类并实例化。"""
    import importlib

    if source not in SOURCES:
        raise ValueError(f"未知信源: {source}")
    module_path, cls_name = SOURCES[source].rsplit(".", 1)
    module = importlib.import_module(module_path)
    return getattr(module, cls_name)()
