# Generated from the RFQ parser's matcher/product_search.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

import re
from typing import Any


def search_products(products: list[Any], query: str, limit: int = 20) -> list[dict[str, Any]]:
    q = str(query or "").strip().upper()
    limit = max(1, min(50, int(limit or 20)))
    if not q:
        return []

    wildcard = _wildcard_regex(q) if "*" in q else None
    terms = [term for term in q.replace("*", " ").replace("/", " ").replace("-", " ").split() if term]
    matches = []
    for index, product in enumerate(products):
        code = (product.product_code or "").upper()
        haystack = " ".join([code, (product.style or "").upper(), (product.desc2 or "").upper()])
        score = None
        if code.startswith(q):
            score = 0
        elif wildcard and wildcard.search(code):
            score = 1
        elif q in code:
            score = 2
        elif terms and all(term in haystack for term in terms):
            score = 3
        if score is not None:
            matches.append(
                (
                    score,
                    index,
                    {
                        "code": product.product_code,
                        "style": product.style,
                        "desc2": product.desc2,
                    },
                )
            )
    matches.sort(key=lambda item: (item[0], item[1]))
    return [match for _score, _index, match in matches[:limit]]


def _wildcard_regex(query: str) -> re.Pattern[str] | None:
    parts = [re.escape(part) for part in query.split("*")]
    pattern = ".*".join(parts)
    if not pattern.replace(".*", ""):
        return None
    return re.compile(pattern)
