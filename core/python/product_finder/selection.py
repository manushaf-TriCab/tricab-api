"""The orchestrator — requirement in, product code out.

This is the join the two source tools cannot make on their own. The RFQ parser
picks a style and assembles a valid product code but never chooses a size; the
Cable Calculator sizes a conductor but emits a price mask, not a sellable code.

Order of work:

1. Read the requirement (free text or parameters).
2. Choose a style — the matcher's ranked selection where construction wording is
   available, the calculator's type mapping otherwise.
3. Settle the cross-section — honour a stated one and verify it, or size from
   the electrical duty.
4. Fill everything still unstated from the style's registered defaults.
5. Assemble the product code and say whether it is stocked or made to order.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass, field
from typing import Any

from . import nl, rules
from .constants import (
    CABLE_TYPES,
    SDI_TO_MULTI,
    TYPE_DESCRIPTIONS,
    is_sdi,
    suggest_styles,
)
from .sizer import SIZER, SizingRequest, SizingResult
from .specs import SPECS, describe_source

log = logging.getLogger(__name__)

MAX_CONFIDENCE_WITH_INFERENCE = 0.80

# Applied only where neither the caller nor the text supplied a value. Kept out
# of FinderRequest so that "the caller said 400 V" and "nobody said anything"
# stay distinguishable — see the note on FinderRequest.
DUTY_DEFAULTS: dict[str, Any] = {
    "voltage": 400,
    "phase": 3,
    "ambient_c": 40,
    "install": 1,
    "operating_c": 90,
    "max_volt_drop_pct": 5.0,
    "power_factor": 0.85,
    "fault_duration_s": 1.0,
}


def duty(electrical: dict[str, Any], key: str) -> Any:
    """A duty parameter: what was read or supplied, else the standard default."""
    value = electrical.get(key)
    return DUTY_DEFAULTS.get(key) if value is None else value


def _format_size(value: Any) -> str:
    """Render a cross-section the way product codes carry it.

    Sub-1 metric sizes drop the leading zero (0.5 -> .5), which is the code
    grammar's own convention, and whole numbers lose the decimal point.
    """
    try:
        number = float(value)
    except (TypeError, ValueError):
        return str(value)
    text = f"{number:g}"
    return text[1:] if text.startswith("0.") else text


@dataclass
class FinderRequest:
    """A requirement, however it arrived."""

    text: str = ""

    # Construction
    style: str = ""
    cable_type: int | None = None
    cores: int | None = None
    cross_section: float | None = None
    colour: str = ""
    conductor: str = ""
    armour: str = ""

    # Electrical duty.
    #
    # These are all Optional with a None default on purpose. They used to carry
    # real defaults (voltage=400, install=1, ambient=40), which meant that on the
    # text path the dataclass default silently overwrote whatever the customer
    # actually wrote — "buried in conduit at 45 degrees" was read correctly and
    # then replaced with "on a tray at 40". A default here is indistinguishable
    # from a caller-supplied value; None is not. Defaults are applied at the
    # point of use instead, via DUTY_DEFAULTS.
    load_amps: float | None = None
    length_m: float | None = None
    voltage: int | None = None
    phase: int | None = None
    ambient_c: float | None = None
    install: int | None = None
    operating_c: int | None = None
    max_volt_drop_pct: float | None = None
    power_factor: float | None = None
    max_op_temp_c: float | None = None
    fixed_runs: int | None = None
    strategy: str = "min-runs"
    aluminium_active: bool = False
    aluminium_earth: bool = False
    fault_duration_s: float | None = None
    region: str = "australia"

    allow_ai: bool = True

    @classmethod
    def from_payload(cls, payload: dict[str, Any]) -> FinderRequest:
        def num(key: str, default: Any = None) -> Any:
            value = payload.get(key)
            if value in (None, "", "auto"):
                return default
            try:
                return float(value)
            except (TypeError, ValueError):
                return default

        def integer(key: str, default: Any = None) -> Any:
            value = num(key, None)
            return int(value) if value is not None else default

        return cls(
            text=str(payload.get("text") or "").strip(),
            style=str(payload.get("style") or "").strip().upper(),
            cable_type=integer("cableType"),
            cores=integer("cores"),
            cross_section=num("crossSection"),
            colour=str(payload.get("colour") or "").strip().upper(),
            conductor=str(payload.get("conductor") or "").strip().upper(),
            armour=str(payload.get("armour") or "").strip().upper(),
            load_amps=num("load"),
            length_m=num("length"),
            voltage=integer("voltage"),
            phase=integer("phase"),
            ambient_c=num("ambient"),
            install=integer("install"),
            operating_c=integer("operating"),
            max_volt_drop_pct=num("maxVoltDrop"),
            power_factor=num("powerFactor"),
            max_op_temp_c=num("maxOpTemp"),
            fixed_runs=integer("runs"),
            strategy=str(payload.get("strategy") or "min-runs"),
            aluminium_active=bool(payload.get("aluminiumActive")),
            aluminium_earth=bool(payload.get("aluminiumEarth")),
            fault_duration_s=num("faultDuration"),
            region=str(payload.get("region") or "australia"),
            allow_ai=payload.get("allowAi", True) is not False,
        )


@dataclass
class FinderResult:
    status: str  # matched | needs-clarification | unresolved
    product_code: str | None = None
    stocked: bool = False
    confidence: float = 0.0
    style: dict[str, Any] | None = None
    code_segments: dict[str, Any] | None = None
    description: str = ""
    sizing: dict[str, Any] | None = None
    understood: dict[str, Any] | None = None
    inferred_fields: list[str] = field(default_factory=list)
    warnings: list[str] = field(default_factory=list)
    alternatives: list[dict[str, Any]] = field(default_factory=list)
    detail: str = ""

    def public(self) -> dict[str, Any]:
        return {
            "status": self.status,
            "productCode": self.product_code,
            "stocked": self.stocked,
            "confidence": round(self.confidence, 2),
            "style": self.style,
            "codeSegments": self.code_segments,
            "description": self.description,
            "sizing": self.sizing,
            "understood": self.understood,
            "inferredFields": self.inferred_fields,
            "warnings": self.warnings,
            "alternatives": self.alternatives,
            "detail": self.detail,
        }


class ProductFinder:
    def find(self, request: FinderRequest) -> FinderResult:
        warnings: list[str] = []
        inferred: list[str] = []

        fields, electrical, understood = self._read_requirement(request, warnings)

        # ── style ────────────────────────────────────────────────────────────
        style_obj, style_score, style_source = self._choose_style(
            request, fields, electrical, warnings
        )
        if style_obj is None:
            return FinderResult(
                status="unresolved",
                understood=understood,
                warnings=warnings,
                detail=(
                    "Could not determine a cable style. Give a construction "
                    "(for example 'XLPE 90 degC armoured') or pick a cable type."
                ),
            )

        style_code = getattr(style_obj, "code", str(style_obj))
        if style_source == "inferred":
            inferred.append("style")

        # ── cross-section ────────────────────────────────────────────────────
        sizing_result, cross_section, size_source = self._settle_size(
            request, fields, electrical, style_code, warnings
        )
        if cross_section is None:
            return FinderResult(
                status="needs-clarification",
                style=self._style_public(style_obj),
                understood=understood,
                sizing=sizing_result.public() if sizing_result else None,
                warnings=warnings,
                detail=(
                    "No conductor size was given and there is not enough "
                    "electrical detail to choose one. Provide a cross-section, "
                    "or a load and run length."
                ),
            )
        if size_source == "sized":
            inferred.append("cross_section")

        # ── assemble the code ────────────────────────────────────────────────
        # The RFQ parser's `match_line` is the authority on everything except
        # the size. Delegating to it — rather than re-deriving sheath, config,
        # armour and colour here — is what keeps the finder from drifting away
        # from the parser as its rules change. `field_overrides` exists for
        # exactly this: we inject the cross-section we settled and let the
        # parser's own rules decide the rest.
        overrides: dict[str, Any] = {"cross_section": _format_size(cross_section)}

        cores = self._resolve_cores(request, fields, sizing_result, electrical)
        if cores:
            overrides["count"] = cores
        if request.style:
            overrides["style_hint"] = request.style

        # Which line to hand the matcher. Real construction wording goes through
        # verbatim, so the parser sees exactly what the customer wrote. But prose
        # that only states a duty ("feed a motor 80 m away at 200 A") describes no
        # cable — the parser reads it as an accessory line and returns nothing. In
        # that case the construction is *our* assumption, so the synthesised
        # description is the honest input. Try the better candidate first and fall
        # back, rather than betting on one.
        synthesised = self._synthesise_line(request, fields, electrical)
        candidates_lines = (
            [synthesised, request.text]
            if (style_source == "inferred" or not request.text)
            else [request.text, synthesised]
        )

        resolved: dict[str, Any] | None = None
        try:
            for line in candidates_lines:
                if not line:
                    continue
                attempt = rules.match_line_with_overrides(line, overrides)
                if attempt.get("selected_product_code"):
                    resolved = attempt
                    break
                resolved = resolved or attempt
        except Exception as exc:  # noqa: BLE001 - reported to the user
            return FinderResult(
                status="needs-clarification",
                style=self._style_public(style_obj),
                understood=understood,
                sizing=sizing_result.public() if sizing_result else None,
                warnings=warnings,
                detail=f"Could not assemble a product code: {exc}",
            )

        resolved = resolved or {}
        product_code = resolved.get("selected_product_code")
        if not product_code:
            return FinderResult(
                status=resolved.get("status") or "needs-clarification",
                style=self._style_public(style_obj),
                understood=understood,
                sizing=sizing_result.public() if sizing_result else None,
                warnings=warnings + list(resolved.get("warnings") or []),
                detail=(
                    "The construction resolved to no sellable product code. "
                    "Check the core count and cross-section against what is "
                    "manufactured in this style."
                ),
            )

        inferred.extend(resolved.get("inferred_fields") or [])
        warnings.extend(resolved.get("warnings") or [])

        selected = resolved.get("selected_product")
        stocked = rules.product_by_code(product_code) is not None
        if not stocked:
            warnings.append(
                "This construction is not a catalogue line. It can be made — "
                "confirm with TSD before quoting."
            )

        # The parser may have landed on a different style than the one we sized
        # against; report the one that actually produced the code.
        final_style = rules.style_by_code(product_code[:2]) or style_obj
        segments, description = self._describe(product_code, final_style)

        confidence = min(
            resolved.get("confidence") or 1.0,
            self._confidence(style_score, inferred, stocked, size_source),
        )

        return FinderResult(
            status="matched",
            product_code=product_code,
            stocked=stocked,
            confidence=confidence,
            style=self._style_public(final_style),
            code_segments=segments,
            description=description,
            sizing=sizing_result.public() if sizing_result else None,
            understood=understood,
            inferred_fields=sorted(set(inferred)),
            warnings=warnings,
            alternatives=[
                {
                    "productCode": p.get("product_code"),
                    "style": p.get("style"),
                    "cores": p.get("no_core"),
                    "crossSection": p.get("cross_core"),
                    "colour": p.get("colour"),
                }
                for p in (resolved.get("candidates") or [])[:6]
                if p.get("product_code") != product_code
            ],
        )

    def _synthesise_line(
        self,
        request: FinderRequest,
        fields: dict[str, Any],
        electrical: dict[str, Any],
    ) -> str:
        """Turn dropdown selections into a line the matcher can rank.

        The parameter path and the text path then run through identical rules,
        so the two cannot give different answers for the same requirement.
        """
        parts: list[str] = []

        cores = request.cores or fields.get("count")
        if cores:
            parts.append(f"{int(cores)} core")

        size = request.cross_section or fields.get("cross_section")
        if size:
            parts.append(f"{_format_size(size)}mm2")

        cable_type = electrical.get("cable_type")
        if cable_type is not None:
            parts.append(TYPE_DESCRIPTIONS.get(int(cable_type), ""))

        if int(duty(electrical, "operating_c")) >= 110:
            parts.append("110 deg C")

        if request.aluminium_active or electrical.get("aluminium_active"):
            parts.append("aluminium")
        elif request.conductor == "D":
            parts.append("tinned")

        if request.armour and request.armour not in ("", "X"):
            parts.append("armoured")

        if request.colour:
            parts.append(request.colour)

        # System voltage and cable rated voltage are different quantities. The
        # calculator's `voltage` is the system it feeds (used for volt drop); the
        # parser reads a stated voltage as the cable's own rating and hard-gates
        # styles on it. Passing 400 V through would eliminate every 600/1000 V
        # style — which is exactly what you want for a 400 V system. So only
        # forward it above 1 kV, where it genuinely constrains the construction.
        voltage = duty(electrical, "voltage")
        if voltage and int(voltage) > 1000:
            parts.append(f"{int(voltage)}V")

        return " ".join(p for p in parts if p)

    # ── steps ────────────────────────────────────────────────────────────────

    def _read_requirement(
        self, request: FinderRequest, warnings: list[str]
    ) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
        """Merge free text and explicit parameters. Parameters always win."""
        fields: dict[str, Any] = {}
        electrical: dict[str, Any] = {}
        understood: dict[str, Any] = {"mode": "parameters"}

        if request.text:
            parsed = nl.parse_requirement(request.text, allow_ai=request.allow_ai)
            fields = dict(parsed.fields)
            electrical = dict(parsed.electrical)
            warnings.extend(parsed.warnings)
            understood = parsed.public()
            understood["mode"] = "text"

        # Explicit parameters override anything read from prose.
        overrides = {
            "load_amps": request.load_amps,
            "length_m": request.length_m,
            "voltage": request.voltage,
            "phase": request.phase,
            "ambient_c": request.ambient_c,
            "install": request.install,
            "cable_type": request.cable_type,
            "operating_c": request.operating_c,
            "power_factor": request.power_factor,
            "max_volt_drop_pct": request.max_volt_drop_pct,
            "fault_duration_s": request.fault_duration_s,
            "fixed_runs": request.fixed_runs,
            "aluminium_active": request.aluminium_active or None,
        }
        for key, value in overrides.items():
            if value is not None:
                electrical[key] = value
                understood.setdefault("sources", {})[key] = "parameter"

        if request.cores:
            fields["count"] = request.cores
        if request.cross_section:
            fields["cross_section"] = request.cross_section
        if request.colour:
            fields["colour"] = request.colour
        if request.conductor:
            fields["conductor"] = request.conductor
        if request.armour:
            fields["armour"] = request.armour
            fields["armoured"] = request.armour not in ("", "X")

        self._prefer_multicore(request, fields, electrical, warnings)

        understood["electrical"] = electrical
        return fields, electrical, understood

    def _prefer_multicore(
        self,
        request: FinderRequest,
        fields: dict[str, Any],
        electrical: dict[str, Any],
        warnings: list[str],
    ) -> None:
        """Promote a single-core construction to its multicore equivalent.

        Application wording alone cannot distinguish the two: "fire rated cable,
        150 A, three phase" matches the fire pattern, which maps to SDI FIRE, and
        an AC feeder then comes back as a single core. Promote unless the
        requirement actually points at single core.
        """
        cable_type = electrical.get("cable_type")
        if cable_type is None or int(cable_type) not in SDI_TO_MULTI:
            return

        # An explicitly single-core requirement stays single core.
        cores = request.cores or fields.get("count")
        if cores and int(cores) == 1:
            return
        if request.text and re.search(
            r"\bsingle[\s-]*core\b|\bSDI\b|\b1\s*[cC]\b|\bone\s+core\b", request.text
        ):
            return
        # DC single-core runs are ordinary; only promote AC feeders.
        if int(duty(electrical, "phase")) == 5:
            return

        promoted = SDI_TO_MULTI[int(cable_type)]
        electrical["cable_type"] = promoted
        warnings.append(
            f"Read as {CABLE_TYPES[promoted]} rather than "
            f"{CABLE_TYPES[int(cable_type)]} — the requirement is a multi-core "
            "AC circuit. Say 'single core' if separate cores are wanted."
        )

    def _choose_style(
        self,
        request: FinderRequest,
        fields: dict[str, Any],
        electrical: dict[str, Any],
        warnings: list[str],
    ) -> tuple[Any | None, float, str]:
        """Prefer the matcher's ranked selection; fall back to the type mapping."""
        # 1. Explicitly named style.
        if request.style:
            style = rules.style_by_code(request.style)
            if style is not None:
                return style, 1.0, "stated"
            warnings.append(f"Style {request.style} is not a registered style.")

        # 2. The RFQ parser's ranked selection, from construction wording.
        normalized = fields.get("_normalized_text") or request.text
        if normalized:
            try:
                ranked = rules.rank_styles(normalized, fields)
                if ranked:
                    top_style, top_score = ranked[0]
                    if len(ranked) > 1 and abs(ranked[1][1] - top_score) < 0.02:
                        warnings.append(
                            f"{top_style.code} and {ranked[1][0].code} scored "
                            "almost equally — worth a human check."
                        )
                    return top_style, top_score, "ranked"
            except Exception as exc:  # noqa: BLE001 - fall through to the mapping
                warnings.append(f"Style ranking unavailable: {exc}")

        # 3. The calculator's cable-type mapping.
        cable_type = electrical.get("cable_type")

        # 3a. Duty stated but no construction. The RFQ parser's temperature
        # ladder makes XLPE 90 degC the house default, so use it rather than
        # refusing — but say so, because it is an assumption about the product.
        if cable_type is None and electrical.get("load_amps"):
            phase = int(duty(electrical, "phase"))
            cable_type = 1 if phase == 5 else 4
            electrical["cable_type"] = cable_type
            warnings.append(
                f"No construction stated — assumed "
                f"{CABLE_TYPES[cable_type]} at 90 degC, the house default. "
                "Confirm before quoting."
            )

        if cable_type is None:
            return None, 0.0, "none"

        styles, _earth, _operating = suggest_styles(
            int(cable_type),
            int(duty(electrical, "phase")),
            int(duty(electrical, "operating_c")),
        )
        if not styles:
            return None, 0.0, "none"

        style = rules.style_by_code(styles[0])
        if style is None:
            warnings.append(
                f"Style {styles[0]} is offered by the calculator but is not a "
                "registered style in the product catalogue."
            )
            return None, 0.0, "none"
        return style, 0.6, "inferred"

    def _settle_size(
        self,
        request: FinderRequest,
        fields: dict[str, Any],
        electrical: dict[str, Any],
        style_code: str,
        warnings: list[str],
    ) -> tuple[SizingResult | None, float | None, str]:
        """Honour a stated cross-section, or size one from the duty."""
        stated = (
            request.cross_section
            or fields.get("cross_section")
            or electrical.get("cross_section")
        )
        load = electrical.get("load_amps")
        length = electrical.get("length_m")

        can_size = bool(load and length and SIZER.available)

        if stated and not can_size:
            return None, float(stated), "stated"

        if not stated and not can_size:
            if load and length and not SIZER.available:
                # Name the source that is actually missing. Under the default
                # snapshot source that is a file in the artifact, not a database
                # behind the VPN, and sending someone to check the VPN over a
                # missing JSON file wastes an afternoon.
                source = describe_source()
                missing = (
                    "the pinned cable-calculator dataset"
                    if source.get("source") == "snapshot"
                    else "the legacy MySQL database"
                )
                warnings.append(
                    f"Sizing needs {missing} and it is unavailable; only matching ran."
                )
            return None, None, "none"

        sizing_request = SizingRequest(
            load_amps=float(load),
            length_m=float(length),
            voltage=int(duty(electrical, "voltage")),
            phase=int(duty(electrical, "phase")),
            cable_type=int(electrical.get("cable_type") or request.cable_type or 1),
            install=int(duty(electrical, "install")),
            ambient_c=float(duty(electrical, "ambient_c")),
            operating_c=int(duty(electrical, "operating_c")),
            max_volt_drop_pct=float(
                duty(electrical, "max_volt_drop_pct")
            ),
            power_factor=float(duty(electrical, "power_factor")),
            max_op_temp_c=request.max_op_temp_c,
            aluminium_active=bool(
                electrical.get("aluminium_active", request.aluminium_active)
            ),
            aluminium_earth=request.aluminium_earth,
            fixed_runs=electrical.get("fixed_runs") or request.fixed_runs,
            strategy=request.strategy,
            fault_duration_s=float(
                duty(electrical, "fault_duration_s")
            ),
            pinned_cross_section=float(stated) if stated else None,
            region=request.region,
        )

        result = SIZER.size(sizing_request, styles=[style_code])

        if stated:
            # We were verifying, not choosing.
            if result.status == "no-solution":
                warnings.append(
                    f"{stated} mm2 does not meet the stated duty — it fails on "
                    "capacity, volt drop or operating temperature."
                )
            return result, float(stated), "stated"

        if result.status == "sized" and result.candidate:
            return result, result.candidate.spec.cross_section, "sized"

        warnings.extend(result.warnings)
        if result.detail:
            warnings.append(result.detail)
        return result, None, "none"

    def _resolve_cores(
        self,
        request: FinderRequest,
        fields: dict[str, Any],
        sizing_result: SizingResult | None,
        electrical: dict[str, Any],
    ) -> int | None:
        """Core count for the product code — the earth counts as a core."""
        if fields.get("count"):
            return int(fields["count"])

        if sizing_result and sizing_result.candidate:
            spec = sizing_result.candidate.spec
            return spec.active_cores + max(spec.earth_count, 0)

        cable_type = electrical.get("cable_type")
        if cable_type is not None and is_sdi(int(cable_type)):
            return 1
        return None

    def _describe(
        self, product_code: str, style_obj: Any
    ) -> tuple[dict[str, Any] | None, str]:
        try:
            parsed = rules.parse_code(product_code)
        except Exception:  # noqa: BLE001 - description is a nicety
            return None, ""
        if not parsed.get("valid"):
            return None, ""

        style_meta = None
        try:
            style_meta = style_obj.public()
        except Exception:  # noqa: BLE001
            pass

        try:
            description = rules.describe_code(parsed, style_meta)
        except Exception:  # noqa: BLE001
            description = ""

        segments = {
            "style": parsed.get("style"),
            "sheath": parsed.get("sheath"),
            "coreColourConfig": parsed.get("config_core"),
            "braidArmourScreen": parsed.get("armour"),
            "conductor": parsed.get("conductor"),
            "conductorCount": parsed.get("count"),
            "coreType": parsed.get("core_type"),
            "crossSection": parsed.get("cross_section"),
            "jacketColour": parsed.get("colour"),
            "earthCores": parsed.get("ccnt_earth"),
            "earthCrossSection": parsed.get("csa_earth"),
            "fireRated": parsed.get("fire_rated"),
        }
        return segments, description

    def _alternatives(
        self, fields: dict[str, Any], style_obj: Any, chosen: str
    ) -> list[dict[str, Any]]:
        try:
            products = rules.find_products(fields, [(style_obj, 1.0)])
        except Exception:  # noqa: BLE001 - alternatives are optional
            return []
        out = []
        for product in products[:5]:
            if product.product_code == chosen:
                continue
            out.append(
                {
                    "productCode": product.product_code,
                    "style": product.style,
                    "cores": product.no_core,
                    "crossSection": product.cross_core,
                    "colour": product.colour,
                }
            )
        return out

    def _style_public(self, style_obj: Any) -> dict[str, Any] | None:
        try:
            return style_obj.public()
        except Exception:  # noqa: BLE001
            return {"code": getattr(style_obj, "code", str(style_obj))}

    def _confidence(
        self,
        style_score: float,
        inferred: list[str],
        stocked: bool,
        size_source: str,
    ) -> float:
        confidence = min(0.95, 0.55 + style_score * 0.4)
        if size_source == "stated":
            confidence += 0.05
        # Caps are applied last so a bonus can never lift a result past them.
        if inferred:
            confidence = min(confidence, MAX_CONFIDENCE_WITH_INFERENCE)
        if not stocked:
            confidence = min(confidence, 0.70)
        return min(confidence, 0.98)


FINDER = ProductFinder()


def vocabularies() -> dict[str, Any]:
    """Dropdown vocabularies for the preview UI."""
    from .constants import (
        CONDUCTOR_SIZES_MM2,
        INSTALL_METHODS,
        PHASES,
        VOLTAGES,
    )

    styles: list[dict[str, str]] = []
    try:
        for code, style in sorted(rules.catalog().styles.items()):
            styles.append(
                {
                    "code": code,
                    "title": getattr(style, "title", ""),
                    "voltage": getattr(style, "voltage", ""),
                    "temperature": getattr(style, "temperature", ""),
                }
            )
    except Exception:  # noqa: BLE001 - the UI copes with an empty list
        pass

    return {
        "cableTypes": [{"value": k, "label": v} for k, v in sorted(CABLE_TYPES.items())],
        "installMethods": [
            {"value": k, "label": v["label"]} for k, v in sorted(INSTALL_METHODS.items())
        ],
        "phases": [{"value": k, "label": v} for k, v in sorted(PHASES.items())],
        "voltages": list(VOLTAGES),
        "crossSections": list(CONDUCTOR_SIZES_MM2),
        "styles": styles,
    }


def health() -> dict[str, Any]:
    from .db import LEGACY_DB

    return {
        "rules": rules.status().public(),
        "legacyDb": LEGACY_DB.health(),
        "sizingAvailable": SPECS.available,
        "sizing": describe_source(),
        "aiEnabled": nl.CONFIG.llm.usable,
    }
