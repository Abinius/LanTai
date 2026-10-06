"""sensenova LLM 客户端(OpenAI 兼容,stdlib urllib,零依赖)。

避雷 5:body 永远带 thinking:disabled,否则 token 全花在 reasoning、content 为空。
"""

from __future__ import annotations

import json
import time
import urllib.error
import urllib.request
from typing import Any, Optional

from pipeline.config import LLM_BASE_URL, LLM_MODEL, load_llm_key


class LLMError(Exception):
    pass


class LLMRateLimitError(LLMError):
    pass


def chat(messages: list[dict], *, max_tokens: int = 2000, retries: int = 3) -> str:
    """同步调用 sensenova。返回 assistant content(str)。

    限流时指数退避;耗尽抛 LLMRateLimitError 让 p3 走降级。
    """
    key = load_llm_key()
    if not key:
        raise LLMError("LLM 凭证缺失:未找到 LANTAI_LLM_KEY 环境变量或 llm key.txt")

    body = {
        "model": LLM_MODEL,
        "messages": messages,
        "max_tokens": max_tokens,
        # 避雷 5:sensenova 必须禁用 thinking,否则 max_tokens 全花在 reasoning
        "thinking": {"type": "disabled"},
    }
    payload = json.dumps(body, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        LLM_BASE_URL,
        data=payload,
        headers={
            "Authorization": f"Bearer {key}",
            "Content-Type": "application/json",
        },
        method="POST",
    )

    last_err: Optional[Exception] = None
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(req, timeout=60) as resp:
                raw = resp.read().decode("utf-8")
            data = json.loads(raw)
            if isinstance(data, dict) and "choices" in data:
                return data["choices"][0]["message"]["content"] or ""
            raise LLMError(f"非预期响应结构: {raw[:200]}")
        except urllib.error.HTTPError as e:
            last_err = e
            # 429 / 5xx 退避;4xx(除 429)直接抛
            if e.code == 429 or 500 <= e.code < 600:
                wait = 2 ** attempt
                time.sleep(wait)
                continue
            raise LLMError(f"HTTP {e.code}: {e.read().decode('utf-8', errors='replace')[:200]}") from e
        except (urllib.error.URLError, TimeoutError) as e:
            last_err = e
            time.sleep(2 ** attempt)
            continue
    raise LLMRateLimitError(f"重试 {retries} 次仍失败: {last_err}")


def chat_json(messages: list[dict], *, max_tokens: int = 2000) -> Any:
    """调用并解析为 JSON。失败返回 None(不抛,由调用方降级)。"""
    try:
        content = chat(messages, max_tokens=max_tokens)
    except LLMRateLimitError:
        return None
    # 容错:剥离 ```json ... ``` 围栏
    text = content.strip()
    if text.startswith("```"):
        text = text.split("```", 2)
        if len(text) >= 2:
            inner = text[1]
            if inner.startswith("json"):
                inner = inner[4:]
            content_str = inner.strip()
        else:
            content_str = content
    else:
        content_str = text
    try:
        return json.loads(content_str)
    except json.JSONDecodeError:
        return None
