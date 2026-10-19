#!/usr/bin/env python3
"""Read-only AU catalogue gap audit. Writes the report files in this directory."""

from __future__ import annotations

import csv
import json
import re
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

OUT = Path(__file__).resolve().parent
API = Path("/Users/thilanh/Documents/Projects/tricab-core/tricab-api")
LEGACY_PLAN = Path("/tmp/tricab-nav/match-plan.json")
LEGACY_PARSED = Path("/tmp/tricab-nav/crawl/legacy-AU-parsed.json")
ACCESSORY_SITEMAP = Path("/tmp/tricab-nav/wp-sitemap-posts-accessory-1.xml")
CABLE_SITEMAP = Path("/tmp/tricab-nav/wp-sitemap-posts-cable-1.xml")
BATCH_E_SNAPSHOT = API / "var/au-catalogue-population/2026-10-15-batch-e/after-snapshot.json"
PUBLIC_BASE = "https://tricab.ai/api/v1/catalogue"

CATEGORY_ROOT = {
    "Accessories": "PROD-ACCESSORIES",
    "ALI-FLEX System": "PROD-ALI-FLEX-SYSTEMS",
    "Control": "PROD-CONTROL",
    "Electrical Contracting": "PROD-ELECTRICAL-CONTRACTING",
    "Fire Resistant": "PROD-FIRE-RESISTANT",
    "Food & Beverage": "SPEC-FOOD-BEVERAGE",
    "Industrial & Marine Switchboard Wire": "SPEC-INDUSTRIAL-MARINE-SWITCHBOARD-WIRE",
    "Instrumentation": "PROD-INSTRUMENTATION",
    "Marine IEC LSZH": "PROD-MARINE-IEC-LSZH",
    "Marine NEK 606": "PROD-MARINE-NEK-606",
    "Medium Voltage": "PROD-MEDIUM-VOLTAGE",
    "Ship Loaders & Cranes": "SPEC-SHIP-LOADERS-CRANES",
    "Shore Power": "SPEC-SHORE-POWER",
    "Solar": "PROD-SOLAR",
    "Steel/Alum Armour": "PROD-STEEL-ALUM-ARMOUR",
    "Submersible/Potable": "PROD-SUBMERSIBLE-PORTABLE",
    "Switchboard & Panel": "PROD-SWITCH-BOARD-PANEL",
    "Telecoms": "PROD-TELECOMS",
    "Temporary Power": "SPEC-TEMPORARY-POWER",
    "Termite Res/Nylon": "PROD-TERMITE-RES-NYLON",
    "VFD/VSD": "PROD-VFD-VSD",
    "Water Treatment": "SPEC-WATER-TREATMENT",
    "Welding/Battery": "PROD-WELDING-BATTERY",
}

SEGMENT_ALIASES = {
    "lugs-and-links": "lugs and links",
    "heat-shrink-and-cold-shrink": "heat shrink",
    "plug-socket": "plugs and sockets",
    "pre-assembled_lead": "leads",
    "pre-assembled lead": "leads",
    "insulators": "switchboard",
    "cable-management": "cable management",
    "solar-components": "solar",
    "tools-dies": "tools and dies",
    "safety-security-accessories": "safety",
    "ceeform leads": "ceeform leads",
}

GENERIC_SEGMENTS = {
    "copper",
    "aluminium",
    "aluminum",
    "low voltage",
    "voltage rating",
}

STOP = {
    "and",
    "the",
    "for",
    "with",
    "low",
    "voltage",
    "rating",
    "flexible",
    "cables",
    "system",
    "components",
}

PRESENT = "PRESENT"
MISSING = "MISSING PRODUCT"
DRAFT = "DRAFT"
MISSING_PLACEMENT = "MISSING PLACEMENT"
VISIBILITY = "REGION / VISIBILITY ISSUE"
REVIEW = "NEEDS REVIEW"


def norm(value: str) -> str:
    text = value.lower().replace("&", " and ").replace("_", " ").replace("-", " ")
    text = re.sub(r"\s*/\s*", "/", text)
    text = re.sub(r"[^a-z0-9/]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def voltages(value: str) -> list[str]:
    found = re.findall(r"\d+(?:\.\d+)?(?:\s*/\s*\d+(?:\.\d+)?)?\s*k?v", value, flags=re.I)
    return [re.sub(r"\s+", "", item).lower() for item in found]


def split_sub(sub: str) -> list[str]:
    if not sub or not sub.strip():
        return []
    protected = sub
    holders: list[str] = []

    def hold(match: re.Match[str]) -> str:
        holders.append(match.group(0))
        return f" __V{len(holders) - 1}__ "

    protected = re.sub(r"\d+(?:\.\d+)?(?:\s*/\s*\d+(?:\.\d+)?)?\s*k?v", hold, protected, flags=re.I)
    parts = re.split(r"\s*(?:>|\|)\s*|\s+/\s+", protected)
    restored = []
    for part in parts:
        for index, original in enumerate(holders):
            part = part.replace(f"__V{index}__", original)
        restored.append(part.strip())
    return [part for part in restored if part]


def segment_text(segment: str) -> str:
    key = norm(segment)
    return SEGMENT_ALIASES.get(segment.strip().lower(), SEGMENT_ALIASES.get(key, key))


def content_tokens(segment: str) -> list[str]:
    text = segment_text(segment)
    words = re.findall(r"[a-z][a-z0-9]*", text)
    return [word for word in words if word not in STOP and word not in {"kv", "v"}]


def is_generic(segment: str) -> bool:
    return segment_text(segment) in GENERIC_SEGMENTS or set(content_tokens(segment)) <= {"copper", "aluminium", "aluminum"}


def ancestor_matches(segment: str, ancestors: list[str]) -> bool:
    tokens = content_tokens(segment)
    volts = voltages(segment)
    if not tokens and not volts:
        return True
    for ancestor in ancestors:
        name = norm(ancestor)
        ancestor_volts = voltages(ancestor)
        tokens_ok = all(token in name for token in tokens) if tokens else True
        volts_ok = all(volt in ancestor_volts or volt in name.replace(" ", "") for volt in volts) if volts else True
        if tokens_ok and volts_ok and (tokens or volts):
            return True
    return False


def load_sitemap_urls(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}
    xml_text = path.read_text(encoding="utf-8", errors="replace").lstrip("\ufeff \n\r\t")
    root = ET.fromstring(xml_text)
    namespace = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    urls = {}
    for loc in root.findall(".//sm:loc", namespace):
        href = (loc.text or "").strip()
        slug = href.rstrip("/").rsplit("/", 1)[-1]
        if slug:
            urls[slug] = href
    return urls


def fetch_json(url: str):
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "tricab-catalogue-audit"})
    with urllib.request.urlopen(request, timeout=90) as response:
        payload = json.load(response)
        status = response.status
    if isinstance(payload, dict) and "data" in payload:
        return status, payload["data"]
    return status, payload


def public_codes() -> dict:
    tabs_status, tabs = fetch_json(f"{PUBLIC_BASE}/tabs")
    tab_items = tabs if isinstance(tabs, list) else tabs.get("items") or []
    codes: set[str] = set()
    node_codes: set[str] = set()
    product_nodes = 0
    for tab in tab_items:
        status, tree = fetch_json(f"{PUBLIC_BASE}/tabs/{tab['slug']}/tree?regionCode=AU&includeProducts=true")
        if status != 200:
            raise RuntimeError(f"Public tree {tab['slug']} returned {status}")

        def walk(nodes: list) -> None:
            nonlocal product_nodes
            for node in nodes:
                if node.get("nodeCode"):
                    node_codes.add(node["nodeCode"])
                products = node.get("products") or []
                if products:
                    product_nodes += 1
                for product in products:
                    code = product.get("internalCode") or product.get("code")
                    if code:
                        codes.add(str(code).upper())
                walk(node.get("children") or [])

        walk(tree.get("items") or [])
    return {
        "tabsStatus": tabs_status,
        "tabs": [tab.get("slug") for tab in tab_items],
        "publicInternalCodes": sorted(codes),
        "publicNodeCodes": sorted(node_codes),
        "nodesWithProducts": product_nodes,
    }


def main() -> None:
    baseline = json.loads((OUT / "baseline.json").read_text())
    if not baseline.get("ok"):
        raise SystemExit("Baseline file is not ok")
    inventory = json.loads((OUT / "new-inventory.json").read_text())
    plan = json.loads(LEGACY_PLAN.read_text())
    parsed = json.loads(LEGACY_PARSED.read_text())
    accessory_urls = load_sitemap_urls(ACCESSORY_SITEMAP)
    cable_urls = load_sitemap_urls(CABLE_SITEMAP)
    public = public_codes()

    nodes = {int(node["CatalogueNodeId"]): node for node in inventory["nodes"]}
    products = list(inventory["products"])
    by_code = {product["InternalCode"].upper(): product for product in products}

    def ancestors(node_id: int) -> list[dict]:
        chain = []
        seen = set()
        current = node_id
        while current and current not in seen:
            seen.add(current)
            node = nodes[current]
            chain.append(node)
            parent = node["ParentNodeId"]
            current = int(parent) if parent else 0
        return list(reversed(chain))

    placements_by_product: dict[int, list[dict]] = defaultdict(list)
    for placement in inventory["placements"]:
        chain = ancestors(int(placement["CatalogueNodeId"]))
        placements_by_product[int(placement["ProductId"])].append({
            "placementId": int(placement["CatalogueNodeProductId"]),
            "nodeCode": chain[-1]["NodeCode"],
            "nodeSlug": chain[-1]["Slug"],
            "rootCode": chain[0]["NodeCode"],
            "path": " / ".join(node["NodeName"] for node in chain),
            "ancestors": [node["NodeName"] for node in chain],
        })

    nodes_by_root: dict[str, list[list[str]]] = defaultdict(list)
    for node in inventory["nodes"]:
        chain = ancestors(int(node["CatalogueNodeId"]))
        nodes_by_root[chain[0]["NodeCode"]].append([item["NodeName"] for item in chain])

    href_by_key = {}
    for category in parsed:
        for group in category.get("groups") or []:
            for product in group.get("products") or []:
                href_by_key[(category["name"], product.get("slug"))] = product.get("href")

    slug_codes: dict[str, set[str]] = defaultdict(set)
    for item in plan["items"]:
        if item.get("code"):
            slug_codes[item["slug"]].add(item["code"].upper())

    def legacy_url(item: dict, code: str | None) -> str:
        href = href_by_key.get((item["category"], item["slug"]))
        if href:
            return href
        if item["slug"] in accessory_urls:
            return accessory_urls[item["slug"]]
        if item["slug"] in cable_urls:
            return cable_urls[item["slug"]]
        if item.get("source") == "overlay":
            return f"https://www2.tricab.com/accessory/{item['slug']}/"
        return f"https://www2.tricab.com/cable/{item['slug']}/"

    rows = []
    for item in plan["items"]:
        slug = item["slug"] or ""
        if slug.startswith("http") and "certificates" in slug:
            continue
        codes = []
        resolution = "card code"
        if item.get("code"):
            codes = [item["code"].upper()]
        else:
            tokens = [token.upper() for token in re.findall(r"tricab-([a-z0-9]+)", slug.lower())]
            tokens = [token for token in tokens if re.fullmatch(r"[A-Z]{2,4}[A-Z0-9]*", token)]
            if tokens:
                codes = tokens
                resolution = "legacy URL code token"
            elif len(slug_codes.get(slug, set())) == 1:
                codes = sorted(slug_codes[slug])
                resolution = "same legacy slug as a coded card"
            else:
                codes = [None]
                resolution = "no verified code"
        for code in codes:
            rows.append({
                "category": item["category"],
                "sub": item.get("sub") or "",
                "code": code,
                "title": item.get("title") or "",
                "slug": slug,
                "source": item.get("source"),
                "url": legacy_url(item, code),
                "resolution": resolution,
                "key": code if code else f"slug:{slug}",
            })

    collapsed = {}
    order = []
    for row in rows:
        identity = (row["category"], row["sub"], row["key"])
        if identity not in collapsed:
            row["urls"] = [row["url"]]
            row["slugs"] = [row["slug"]]
            collapsed[identity] = row
            order.append(identity)
        else:
            existing = collapsed[identity]
            if row["url"] not in existing["urls"]:
                existing["urls"].append(row["url"])
            if row["slug"] not in existing["slugs"]:
                existing["slugs"].append(row["slug"])
            if row["title"] and (not existing["title"] or existing["title"] == existing["slug"]):
                existing["title"] = row["title"]
    legacy_rows = [collapsed[identity] for identity in order]

    def suffix_candidates(code: str) -> list[dict]:
        if not re.fullmatch(r"[A-Z]{2}", code):
            return []
        found = []
        for product in products:
            internal = product["InternalCode"].upper()
            if internal.startswith(code) and len(internal) == len(code) + 1 and internal[-1] in "ACMPXT":
                found.append(product)
        return found

    def classify_placement(row: dict, product: dict | None) -> tuple[str, str]:
        root = CATEGORY_ROOT[row["category"]]
        if product is None:
            return MISSING, "No product record uses this legacy code."
        status = product["StatusCode"]
        if status != "published":
            return DRAFT, f"Product {product['ProductId']} exists with status {status}."
        if int(product["PublicAu"]) != 1:
            return VISIBILITY, f"Product {product['ProductId']} is published and is not in the AU public window."
        own = [item for item in placements_by_product[int(product["ProductId"])] if item["rootCode"] == root]
        segments = split_sub(row["sub"])
        specific = [segment for segment in segments if not is_generic(segment)]

        def matched(ancestors_names: list[str]) -> bool:
            return all(ancestor_matches(segment, ancestors_names) for segment in specific)

        if any(matched(item["ancestors"]) for item in own):
            return PRESENT, "Published, AU-visible, and placed in this legacy category."
        if len(specific) >= 2:
            parent_segments = specific[:-1]
            last_segment = specific[-1]
            parent_hits = [
                item for item in own
                if all(ancestor_matches(segment, item["ancestors"]) for segment in parent_segments)
            ]
            last_node_exists = any(
                ancestor_matches(last_segment, ancestors_names) for ancestors_names in nodes_by_root[root]
            )
            if parent_hits and not last_node_exists:
                return PRESENT, "Published, AU-visible, and placed under the matching parent category. This legacy leaf label has no separate navigation node."
        root_has_destination = any(matched(ancestors_names) for ancestors_names in nodes_by_root[root])
        if own and not specific:
            return PRESENT, "Published and placed under this main category."
        if own and not root_has_destination:
            return REVIEW, "Placed under this main category, but the legacy subcategory has no matching navigation node."
        if root_has_destination or not own:
            current = "; ".join(item["path"] for item in placements_by_product[int(product["ProductId"])]) or "no navigation placement"
            return MISSING_PLACEMENT, f"Published product is not in this category. Current placement: {current}"
        return REVIEW, "Category placement could not be confirmed."

    classified = []
    for row in legacy_rows:
        product = by_code.get(row["code"]) if row["code"] else None
        candidates = suffix_candidates(row["code"]) if row["code"] and product is None else []
        if row["code"] is None:
            status, reason = REVIEW, "The legacy card has no verified product code."
        elif product is None and candidates:
            names = ", ".join(
                f"{item['InternalCode']} ({item['StatusCode']}, product {item['ProductId']})" for item in candidates
            )
            status, reason = REVIEW, f"Legacy code {row['code']} was not equated to suffix records: {names}."
        else:
            status, reason = classify_placement(row, product)
        classified.append({**row, "status": status, "reason": reason, "product": product, "candidates": candidates})

    ambiguous = []
    by_code_rows: dict[str, list[dict]] = defaultdict(list)
    for row in classified:
        if row["code"]:
            by_code_rows[row["code"]].append(row)
    for code, group in sorted(by_code_rows.items()):
        slugs = sorted({slug for item in group for slug in item["slugs"] if slug})
        titles = sorted({item["title"] for item in group if item["title"]})
        if len(slugs) > 1:
            ambiguous.append({
                "code": code,
                "slugs": slugs,
                "titles": titles,
                "categories": sorted({item["category"] for item in group}),
                "note": "One legacy code is used by more than one legacy URL.",
            })

    def product_public(product: dict | None) -> dict:
        if product is None:
            return {}
        places = placements_by_product[int(product["ProductId"])]
        slug = (product.get("slugs") or [None])[0]
        node_slug = places[0]["nodeSlug"] if places else None
        return {
            "productId": int(product["ProductId"]),
            "publicId": product["PublicId"],
            "internalCode": product["InternalCode"],
            "name": product["ProductName"],
            "status": product["StatusCode"],
            "regionAu": bool(int(product["RegionAu"])),
            "publicAu": bool(int(product["PublicAu"])),
            "legacyProductId": product.get("LegacyProductId"),
            "productSlug": slug,
            "catalogueUrls": [
                f"https://tricab.ai/api/v1/catalogue/nodes/{item['nodeSlug']}?regionCode=AU" for item in places
            ],
            "placements": [item["path"] for item in places],
        }

    summary_order = []
    summary = {}
    for row in classified:
        key = (row["category"], row["sub"])
        if key not in summary:
            summary[key] = {
                "legacy": set(),
                "present": set(),
                "missing": set(),
                "draft": set(),
                "placement": set(),
                "visibility": set(),
                "review": set(),
            }
            summary_order.append(key)
        bucket = summary[key]
        bucket["legacy"].add(row["key"])
        target = {
            PRESENT: "present",
            MISSING: "missing",
            DRAFT: "draft",
            MISSING_PLACEMENT: "placement",
            VISIBILITY: "visibility",
            REVIEW: "review",
        }[row["status"]]
        bucket[target].add(row["key"])

    summary_rows = []
    for category, sub in summary_order:
        bucket = summary[(category, sub)]
        legacy = len(bucket["legacy"])
        present = len(bucket["present"])
        missing = len(bucket["missing"])
        draft = len(bucket["draft"])
        placement = len(bucket["placement"])
        visibility = len(bucket["visibility"])
        review = len(bucket["review"])
        matching = present + draft + placement + visibility
        if legacy != present + missing + draft + placement + visibility + review:
            raise SystemExit(f"Category reconciliation failed for {category} / {sub}")
        if legacy != matching + missing + review:
            raise SystemExit(f"Matching reconciliation failed for {category} / {sub}")
        summary_rows.append({
            "Main category": category,
            "Subcategory": sub or "(category root)",
            "Legacy unique products": legacy,
            "New matching products": matching,
            "Missing products": missing,
            "Draft products": draft,
            "Missing placements": placement,
            "Visibility issues": visibility,
            "Needs review": review,
            "Present": present,
        })

    unique_status: dict[str, set[str]] = defaultdict(set)
    unique_row: dict[str, dict] = {}
    for row in classified:
        unique_status[row["key"]].add(row["status"])
        unique_row.setdefault(row["key"], row)

    def unique_class(key: str) -> str:
        statuses = unique_status[key]
        if MISSING in statuses and statuses <= {MISSING}:
            return MISSING
        if REVIEW in statuses and by_code.get(key) is None:
            return REVIEW
        product = by_code.get(key)
        if product is None:
            return REVIEW if REVIEW in statuses else MISSING
        if product["StatusCode"] != "published":
            return DRAFT
        if int(product["PublicAu"]) != 1:
            return VISIBILITY
        return "MATCHED"

    classes = {key: unique_class(key) for key in unique_status}
    legacy_unique = len(classes)
    missing_unique = [key for key, value in classes.items() if value == MISSING]
    review_unique = [key for key, value in classes.items() if value == REVIEW]
    draft_unique = [key for key, value in classes.items() if value == DRAFT]
    visibility_unique = [key for key, value in classes.items() if value == VISIBILITY]
    matched_unique = [key for key, value in classes.items() if value in {"MATCHED", DRAFT, VISIBILITY}]
    if len(matched_unique) + len(missing_unique) + len(review_unique) != legacy_unique:
        raise SystemExit("Unique reconciliation failed")

    placement_rows = [row for row in classified if row["status"] == MISSING_PLACEMENT]
    public_code_set = set(public["publicInternalCodes"])
    published_codes = {product["InternalCode"].upper() for product in products if product["StatusCode"] == "published"}
    published_absent = sorted(published_codes - public_code_set)
    present_not_public = sorted({
        row["code"] for row in classified
        if row["status"] == PRESENT and row["code"] and row["code"] not in public_code_set
    })
    draft_on_public = sorted({
        row["code"] for row in classified
        if row["status"] == DRAFT and row["code"] and row["code"] in public_code_set
    })

    def write_csv(name: str, fieldnames: list[str], records: list[dict]) -> None:
        with (OUT / name).open("w", newline="", encoding="utf-8") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(records)

    write_csv(
        "category-summary.csv",
        ["Main category", "Subcategory", "Legacy unique products", "New matching products", "Missing products", "Draft products", "Missing placements", "Visibility issues", "Needs review"],
        summary_rows,
    )

    missing_records = []
    for key in sorted(missing_unique):
        group = [row for row in classified if row["key"] == key]
        missing_records.append({
            "Legacy code": key,
            "Name": next((row["title"] for row in group if row["title"]), ""),
            "Categories": " | ".join(sorted({f"{row['category']} > {row['sub']}" if row["sub"] else row["category"] for row in group})),
            "URL": " | ".join(sorted({url for row in group for url in row["urls"]})),
            "Match status": MISSING,
            "Recommended action": "Create one product record from the legacy card, then place it in each listed category.",
        })
    write_csv(
        "missing-products.csv",
        ["Legacy code", "Name", "Categories", "URL", "Match status", "Recommended action"],
        missing_records,
    )

    placement_records = []
    for row in placement_rows:
        info = product_public(row["product"])
        placement_records.append({
            "Legacy code": row["code"],
            "Name": row["title"],
            "ProductId": info.get("productId"),
            "InternalCode": info.get("internalCode"),
            "Publication status": info.get("status"),
            "AU visibility": "visible" if info.get("publicAu") else "not visible",
            "Intended category": f"{row['category']} > {row['sub']}" if row["sub"] else row["category"],
            "Current placements": " | ".join(info.get("placements") or ["none"]),
            "Legacy URL": " | ".join(row["urls"]),
            "Recommended action": "Add a navigation placement for this existing product in the intended category.",
        })
    write_csv(
        "missing-category-placements.csv",
        ["Legacy code", "Name", "ProductId", "InternalCode", "Publication status", "AU visibility", "Intended category", "Current placements", "Legacy URL", "Recommended action"],
        placement_records,
    )

    draft_records = []
    for key in sorted(draft_unique):
        product = by_code[key]
        info = product_public(product)
        group = [row for row in classified if row["key"] == key]
        draft_records.append({
            "ProductId": info["productId"],
            "InternalCode": info["internalCode"],
            "Name": info["name"],
            "Publication status": info["status"],
            "AU region visible": info["regionAu"],
            "AU public": info["publicAu"],
            "Legacy categories": " | ".join(sorted({f"{row['category']} > {row['sub']}" if row["sub"] else row["category"] for row in group})),
            "Legacy URLs": " | ".join(sorted({url for row in group for url in row["urls"]})),
            "Current placements": " | ".join(info["placements"]) or "none",
            "Recommended action": "Review the existing draft. Publishing is a separate decision from creating a product.",
        })
    write_csv(
        "draft-products.csv",
        ["ProductId", "InternalCode", "Name", "Publication status", "AU region visible", "AU public", "Legacy categories", "Legacy URLs", "Current placements", "Recommended action"],
        draft_records,
    )

    category_missing_counts = []
    for category in dict.fromkeys(row["category"] for row in classified):
        keys = {row["key"] for row in classified if row["category"] == category}
        category_missing_counts.append({
            "category": category,
            "legacyUnique": len(keys),
            "missingProducts": len([key for key in keys if classes[key] == MISSING]),
            "drafts": len([key for key in keys if classes[key] == DRAFT]),
            "missingPlacements": len([row for row in classified if row["category"] == category and row["status"] == MISSING_PLACEMENT]),
            "needsReview": len([key for key in keys if classes[key] == REVIEW]),
            "matched": len([key for key in keys if classes[key] in {"MATCHED", DRAFT, VISIBILITY}]),
        })

    snapshot = json.loads(BATCH_E_SNAPSHOT.read_text())
    evidence = {
        "timestampUtc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "auditStatus": "COMPLETE",
        "baseline": baseline,
        "baselineFingerprints": {
            "source": str(BATCH_E_SNAPSHOT),
            "counts": snapshot.get("counts"),
            "productStateSha256": snapshot.get("productStateSha256"),
            "productRegionStateSha256": snapshot.get("productRegionStateSha256"),
            "stableNodeIdentitySha256": snapshot.get("stableNodeIdentitySha256"),
            "allPlacementSha256": snapshot.get("allPlacementSha256"),
            "accessoryPlacementSha256": snapshot.get("accessoryPlacementSha256"),
        },
        "sources": {
            "legacyCards": str(LEGACY_PLAN),
            "legacyParsedHierarchy": str(LEGACY_PARSED),
            "accessorySitemap": str(ACCESSORY_SITEMAP),
            "cableSitemap": str(CABLE_SITEMAP),
            "newInventory": str(OUT / "new-inventory.json"),
            "publicApi": f"{PUBLIC_BASE}/tabs/{{tab}}/tree?regionCode=AU&includeProducts=true",
            "database": "tricab-mel / tc",
        },
        "matchingRules": [
            "Exact InternalCode is the product match.",
            "LegacyProductId is recorded as supporting evidence and is not used to merge different codes.",
            "A combined legacy URL contributes one product per tricab-{code} token.",
            "A card with no code inherits a code only when the same slug already has one verified code.",
            "Cards with no verified code stay NEEDS REVIEW.",
            "A two-letter legacy code with InternalCode plus one family suffix (A, C, M, P, X, T) stays NEEDS REVIEW and is not treated as the same product.",
            "The same code in several legacy categories counts once in the unique totals and once per category placement.",
            "A published AU-visible product placed under a different main category is a missing placement, not a missing product.",
            "Draft status takes precedence over placement and visibility.",
            "Titles are recorded as evidence and are not used to merge codes.",
            "Legacy Accessories > Insulators > Hardware & Fittings and Wire Management map to Accessories > Switchboard, which is the completed Batch C placement.",
            "A published product under the matching parent is present when the legacy leaf label has no navigation node of its own.",
        ],
        "counts": {
            "legacyPlacementRows": len(classified),
            "legacyUniqueProducts": legacy_unique,
            "matchedUniqueProducts": len(matched_unique),
            "missingProductRecords": len(missing_unique),
            "draftProducts": len(draft_unique),
            "missingCategoryPlacements": len(placement_rows),
            "auVisibilityIssues": len(visibility_unique),
            "unresolvedMatches": len(review_unique),
        },
        "publicApi": {
            "tabs": public["tabs"],
            "publicProductCodes": len(public["publicInternalCodes"]),
            "publicNodeCodes": len(public["publicNodeCodes"]),
            "presentCodesAbsentFromPublicTree": present_not_public,
            "draftCodesPresentInPublicTree": draft_on_public,
            "publishedCodesAbsentFromPublicTree": published_absent,
        },
        "ambiguousCodes": ambiguous,
        "categoryReconciliation": summary_rows,
        "classifiedRows": [
            {
                "key": row["key"],
                "code": row["code"],
                "category": row["category"],
                "subcategory": row["sub"],
                "title": row["title"],
                "slug": row["slug"],
                "urls": row["urls"],
                "resolution": row["resolution"],
                "status": row["status"],
                "reason": row["reason"],
                "productId": int(row["product"]["ProductId"]) if row["product"] else None,
                "candidateCodes": [item["InternalCode"] for item in row["candidates"]],
            }
            for row in classified
        ],
    }
    (OUT / "audit-evidence.json").write_text(json.dumps(evidence, indent=2) + "\n")
    (OUT / "public-catalogue-codes.json").write_text(json.dumps({
        "internalCodes": public["publicInternalCodes"],
        "nodeCount": len(public["publicNodeCodes"]),
        "tabs": public["tabs"],
    }, indent=2) + "\n")

    print(json.dumps({
        "legacyUnique": legacy_unique,
        "matched": len(matched_unique),
        "missing": len(missing_unique),
        "draft": len(draft_unique),
        "placements": len(placement_rows),
        "visibility": len(visibility_unique),
        "review": len(review_unique),
        "rows": len(classified),
        "presentNotPublic": present_not_public,
        "draftOnPublic": draft_on_public,
        "publishedAbsent": published_absent,
        "publicCodes": len(public["publicInternalCodes"]),
        "ambiguous": len(ambiguous),
        "categoryMissing": category_missing_counts,
    }, indent=2))


if __name__ == "__main__":
    main()
