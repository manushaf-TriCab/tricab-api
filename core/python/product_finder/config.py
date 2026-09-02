"""Environment configuration.

Every value comes from `.env` (see `.env.example`). Nothing is hardcoded to a
host or a credential. Missing optional config degrades a feature rather than
failing the process: no MySQL means no sizing, no API key means no AI tier.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from dotenv import load_dotenv

import tricab_cable_rules

ROOT = Path(__file__).resolve().parent.parent

# On the App Service every value arrives as an app setting, already in the
# environment; `.env` is a local-development convenience. `load_dotenv` does not
# overwrite what is already set, so loading it unconditionally is safe, and
# PRODUCT_FINDER_ENV_FILE lets a checkout keep its .env outside this tree.
load_dotenv(os.environ.get("PRODUCT_FINDER_ENV_FILE") or (ROOT / ".env"))


def _str(name: str, default: str = "") -> str:
    return (os.environ.get(name) or default).strip()


def _int(name: str, default: int) -> int:
    raw = _str(name)
    try:
        return int(raw) if raw else default
    except ValueError:
        return default


def _float(name: str, default: float) -> float:
    raw = _str(name)
    try:
        return float(raw) if raw else default
    except ValueError:
        return default


def _bool(name: str, default: bool = False) -> bool:
    raw = _str(name).lower()
    if not raw:
        return default
    return raw in {"1", "true", "yes", "on"}


@dataclass(frozen=True)
class LegacyDbConfig:
    """Legacy TriCab MySQL — the ampacity source (`tc.rating`, `tc.cable_specs`)."""

    host: str
    port: int
    user: str
    password: str
    name: str

    @property
    def configured(self) -> bool:
        return bool(self.host and self.user and self.name)


@dataclass(frozen=True)
class CostApiConfig:
    url: str
    timeout_seconds: float
    cache_seconds: int
    default_region: str


@dataclass(frozen=True)
class LlmConfig:
    enabled: bool
    api_key: str
    base_url: str
    model: str
    app_title: str
    referer: str

    @property
    def usable(self) -> bool:
        return self.enabled and bool(self.api_key)


@dataclass(frozen=True)
class CatalogConfig:
    """The three catalogue files the selection rules read.

    They ship inside `tricab_cable_rules`, so the defaults need no configuration
    and no path outside the deployed artifact — that absolute `RFQ_MATCHER_PATH`
    on one developer's Mac is what kept the finder from running anywhere else.
    The environment overrides remain so a newer export can be pointed at without
    rebuilding.
    """

    product_csv: Path | None
    style_xlsx: Path | None
    marine_xlsx: Path | None

    @property
    def configured(self) -> bool:
        return bool(
            self.product_csv
            and self.product_csv.exists()
            and self.style_xlsx
            and self.style_xlsx.exists()
        )


@dataclass(frozen=True)
class Config:
    legacy_db: LegacyDbConfig
    cost_api: CostApiConfig
    llm: LlmConfig
    catalog: CatalogConfig
    host: str
    port: int
    debug: bool
    tricab_api_base_url: str
    sizing_source: str


def _optional_path(name: str) -> Path | None:
    raw = _str(name)
    return Path(raw).expanduser() if raw else None


def load_config() -> Config:
    product_csv = _optional_path("PRODUCT_CSV") or tricab_cable_rules.DEFAULT_PRODUCT_CSV
    style_xlsx = _optional_path("STYLE_XLSX") or tricab_cable_rules.DEFAULT_STYLE_XLSX
    marine_xlsx = _optional_path("MARINE_XLSX") or tricab_cable_rules.DEFAULT_MARINE_XLSX

    return Config(
        legacy_db=LegacyDbConfig(
            host=_str("TRICAB_DB_HOST"),
            port=_int("TRICAB_DB_PORT", 3306),
            user=_str("TRICAB_DB_USER"),
            password=_str("TRICAB_DB_PASSWORD"),
            name=_str("TRICAB_DB_NAME", "tc"),
        ),
        cost_api=CostApiConfig(
            url=_str("TRICAB_COST_API_URL", "https://www.tricab.com/cost-api/"),
            timeout_seconds=_float("TRICAB_COST_API_TIMEOUT_SECONDS", 12.0),
            cache_seconds=_int("TRICAB_COST_API_CACHE_SECONDS", 300),
            default_region=_str("TRICAB_DEFAULT_REGION", "australia"),
        ),
        llm=LlmConfig(
            enabled=_bool("PRODUCT_FINDER_LLM_ENABLED", False),
            api_key=_str("OPENROUTER_API_KEY"),
            base_url=_str(
                "OPENROUTER_BASE_URL",
                "https://openrouter.ai/api/v1/chat/completions",
            ),
            model=_str("OPENROUTER_MODEL", "anthropic/claude-sonnet-4.5"),
            app_title=_str("OPENROUTER_APP_TITLE", "TriCab Product Finder"),
            referer=_str("OPENROUTER_REFERER", "https://www.tricab.com"),
        ),
        catalog=CatalogConfig(
            product_csv=product_csv,
            style_xlsx=style_xlsx,
            marine_xlsx=marine_xlsx,
        ),
        host=_str("PRODUCT_FINDER_HOST", "127.0.0.1"),
        port=_int("PRODUCT_FINDER_PORT", 5001),
        debug=_bool("PRODUCT_FINDER_DEBUG", True),
        tricab_api_base_url=_str("TRICAB_API_BASE_URL", "http://localhost:8080"),
        # json | mysql | auto — see product_finder.specs.
        sizing_source=_str("PRODUCT_FINDER_SIZING_SOURCE", "json").lower(),
    )


CONFIG = load_config()
