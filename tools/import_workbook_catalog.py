#!/usr/bin/env python3
"""
Imports the tests of the reference workbook that are not yet in the catalog.

Clinical-safety rules
  * Nothing is invented. Every limit comes from the workbook text, and the original text is kept as
    range_text so a printed report shows exactly what the workbook says.
  * Only unambiguous patterns become numeric limits (automatic H/L flags):
      a) one closed interval            "8.5-10.5 mg/dL (...)"      -> low + high
      b) one upper limit                "<10 ng/mL"                 -> high only
      c) a Male / Female pair           "Male: 4-15 | Female: 4-23" -> one range per sex
      d) several analytes "Name: lo-hi unit" on separate lines/segments -> one parameter each
    Everything else (qualitative, cycle phases, titres, "approx"-only, mixed units) is imported as a
    text result with the workbook text as display-only reference. No automatic flag is produced.
  * Every imported test and range is "VERIFY REQUIRED" (pathologist review pending).
  * Ranges are labelled "Adult" (>= 18 years): the same assumption as the existing catalog.

Usage
  python tools/import_workbook_catalog.py          regenerate the JSON seed + docs/CATALOG_IMPORT_REPORT.md
  python tools/import_workbook_catalog.py --check  exit 1 if the committed files differ (used in CI)
"""
import json
import re
import sys
from pathlib import Path

from openpyxl import load_workbook

ROOT = Path(__file__).resolve().parent.parent
XLSX = ROOT / "data/sources/Pakistan_Major_Labs_Test_Catalog_Reference_Ranges.xlsx"
CATALOG_DIR = ROOT / "backend/database/seeders/catalog"
OUT_JSON = CATALOG_DIR / "workbook_import.json"
OUT_REPORT = ROOT / "docs/CATALOG_IMPORT_REPORT.md"

SHEET = "Lab Tests Master List"
STATUS = "VERIFY REQUIRED"
SOURCE = ("Workbook Pakistan_Major_Labs_Test_Catalog_Reference_Ranges.xlsx "
          "(compiled from public lab information; approximate; not pathologist verified)")

NUM = r"\d+(?:\.\d+)?"
DASH = r"[\u2013-]"  # en dash or hyphen
SEGMENT_SPLIT = re.compile(r"\s*(?:\n|\|)\s*")


def norm(s):
    return re.sub(r"[^a-z0-9]+", "", str(s).lower())


def norm_unit(s):
    return re.sub(r"\s+", "", str(s)).lower().replace("\u03bc", "\u00b5")


def clean_unit(cell):
    """A single unit, or None (qualitative '-', empty, or several units)."""
    u = (cell or "").strip()
    if not u or u == "-" or "," in u or ";" in u:
        return None
    return u


def unit_matches(rest, unit):
    rest = rest.strip()
    return rest == "" or norm_unit(rest).startswith(norm_unit(unit))


def num(x):
    f = float(x)
    return int(f) if f.is_integer() else f


def rng(gender, low, high, text, unit):
    return {
        "gender": gender,
        "age_label": "Adult",
        "low": None if low is None else num(low),
        "high": None if high is None else num(high),
        "range_text": text,
        "unit": unit,
        "source": SOURCE,
        "verification_status": STATUS,
    }


def param(name, unit, ranges, order=1, data_type="numeric"):
    return {"name": name, "unit": unit, "data_type": data_type, "sort_order": order, "ranges": ranges}


def single_name(components, test_name):
    c = (components or "").strip()
    return c if c and "," not in c else test_name


def parse_structured(ref, unit_cell, components, test_name):
    """Returns (kind, [parameters]) when the text is unambiguous, otherwise None."""
    unit = clean_unit(unit_cell)
    segs = [s for s in SEGMENT_SPLIT.split(ref.strip()) if s]

    if len(segs) == 1 and unit:
        s = segs[0]
        m = re.fullmatch(rf"(?:Normal:\s*)?(?P<lo>{NUM})\s*{DASH}\s*(?P<hi>{NUM})(?P<rest>.*)", s, re.S)
        if m and unit_matches(m["rest"], unit):
            return "interval", [param(single_name(components, test_name), unit,
                                      [rng("both", m["lo"], m["hi"], s, unit)])]
        m = re.fullmatch(rf"<\s*(?P<hi>{NUM})(?P<rest>.*)", s, re.S)
        if m and unit_matches(m["rest"], unit):
            return "upper limit", [param(single_name(components, test_name), unit,
                                         [rng("both", None, m["hi"], s, unit)])]

    if len(segs) == 2 and unit:
        found = []
        for s in segs:
            m = re.fullmatch(
                rf"(?P<label>Male|Female)(?:\s*\(adult\))?:\s*(?P<body>(?P<lo>{NUM})\s*{DASH}\s*(?P<hi>{NUM})(?P<rest>.*))",
                s, re.S)
            if not m or not unit_matches(m["rest"], unit):
                found = []
                break
            found.append((m["label"].lower(), m["lo"], m["hi"], m["body"].strip()))
        if {f[0] for f in found} == {"male", "female"}:
            return "male/female", [param(single_name(components, test_name), unit,
                                         [rng(g, lo, hi, text, unit) for g, lo, hi, text in found])]

    if len(segs) >= 2:
        params = []
        for i, s in enumerate(segs, 1):
            m = re.fullmatch(
                rf"(?P<name>[A-Za-z][A-Za-z0-9 ()/%+.\-]*?):\s*(?P<body>(?P<lo>{NUM})\s*{DASH}\s*(?P<hi>{NUM})\s*(?P<unit>[^\s(]+)(?P<tail>.*))",
                s, re.S)
            if (not m or m["name"].strip().lower() in ("male", "female")
                    or ("/" not in m["unit"] and "%" not in m["unit"])):
                params = []
                break
            u = m["unit"]
            params.append(param(m["name"].strip(), u,
                                [rng("both", m["lo"], m["hi"], m["body"].strip(), u)], order=i))
        if params:
            return "analytes", params

    return None


def code_for(first_token):
    return re.sub(r"[^A-Z0-9]+", "_", first_token.upper()).strip("_")


def load_existing_keys():
    keys, codes = {}, set()
    for f in sorted(CATALOG_DIR.glob("*.json")):
        if f.name == OUT_JSON.name:
            continue
        for t in json.loads(f.read_text(encoding="utf-8")):
            codes.add(t["code"].upper())
            for k in {t["code"], t.get("short_name") or "", t["name"], *(t.get("aliases") or [])}:
                if k:
                    keys.setdefault(norm(k), t["code"])
    return keys, codes


def build():
    wb = load_workbook(XLSX)
    rows = [r for r in list(wb[SHEET].iter_rows(values_only=True))[1:] if r[1]]
    keys, taken = load_existing_keys()

    imported, skipped, report = [], [], []
    for cat, name, short, components, ref, unit_cell, notes, common in rows:
        tokens = [t.strip() for t in re.split(r"\s*(?:/|&)\s*", short or "") if t.strip()]
        hit = next((keys[norm(t)] for t in [name, *tokens] if norm(t) in keys), None)
        if hit:
            skipped.append((name, hit))
            continue

        code = code_for(tokens[0] if tokens else name)
        if code in taken:
            raise SystemExit(f"code collision for {name!r}: {code}")
        taken.add(code)

        ref = (ref or "").strip()
        parsed = parse_structured(ref, unit_cell, components, name)
        if parsed:
            kind, params = parsed
        else:
            kind = "text only"
            text = " | ".join(s for s in SEGMENT_SPLIT.split(ref) if s)
            params = [param("Result", clean_unit(unit_cell), [rng("both", None, None, text, clean_unit(unit_cell))],
                            data_type="text")]

        note_parts = []
        if kind == "text only" and components and components.strip() not in ("-", ""):
            note_parts.append(f"Components: {components.strip()}.")
        if notes and notes.strip():
            note_parts.append(notes.strip())

        test = {
            "code": code,
            "name": name,
            "short_name": tokens[0] if tokens else None,
            "department": cat,
            "aliases": [t for t in tokens[1:] if norm(t) != norm(tokens[0])],
            "verification_status": STATUS,
            "source": f"{SOURCE}; listed as common at: {common}" if common else SOURCE,
            "parameters": params,
        }
        if note_parts:
            test["notes"] = " ".join(note_parts)
        imported.append(test)
        report.append((kind, test, ref))

    return rows, imported, skipped, report


def render_report(total, imported, skipped, report):
    structured = [r for r in report if r[0] != "text only"]
    text_only = [r for r in report if r[0] == "text only"]
    cell = lambda s: str(s).replace("\n", " / ").replace("|", "\\|")
    out = [
        "# Catalog import report (workbook)",
        "",
        "Generated by `tools/import_workbook_catalog.py`. Do not edit by hand.",
        "",
        f"- Tests in the workbook: {total}",
        f"- Already in the catalog (not imported again): {len(skipped)}",
        f"- Imported: **{len(imported)}** - {len(structured)} with numeric limits (automatic H/L flags), "
        f"{len(text_only)} text-only (reference text shown, **no automatic flag**)",
        "- Every imported test and range is `VERIFY REQUIRED` until a pathologist verifies it.",
        "- All ranges are labelled Adult (18 years and above); children get no range.",
        "",
        "## Imported with numeric limits",
        "",
        "| Code | Test | Parsed as | Stored limits | Workbook text |",
        "|---|---|---|---|---|",
    ]
    for kind, t, ref in structured:
        limits = "; ".join(
            f"{p['name']} [{r['gender']}] {r['low'] if r['low'] is not None else ''}-{r['high'] if r['high'] is not None else ''} {r['unit'] or ''}".strip()
            for p in t["parameters"] for r in p["ranges"])
        out.append(f"| {t['code']} | {t['name']} | {kind} | {cell(limits)} | {cell(ref)} |")
    out += ["", "## Imported as text only (needs structuring by the pathologist)", "",
            "| Code | Test | Workbook text |", "|---|---|---|"]
    for kind, t, ref in text_only:
        out.append(f"| {t['code']} | {t['name']} | {cell(ref)} |")
    out += ["", "## Already in the catalog (skipped)", ""]
    out += [f"- {name} -> `{code}`" for name, code in skipped]
    return "\n".join(out) + "\n"


def main():
    rows, imported, skipped, report = build()
    json_text = json.dumps(imported, indent=2, ensure_ascii=False) + "\n"
    report_text = render_report(len(rows), imported, skipped, report)

    if "--check" in sys.argv:
        stale = [p.name for p, new in ((OUT_JSON, json_text), (OUT_REPORT, report_text))
                 if not p.exists() or p.read_text(encoding="utf-8") != new]
        if stale:
            print("OUT OF DATE (run tools/import_workbook_catalog.py): " + ", ".join(stale))
            sys.exit(1)
        print(f"OK: {len(imported)} imported tests are up to date with the workbook")
        return

    OUT_JSON.write_text(json_text, encoding="utf-8")
    OUT_REPORT.write_text(report_text, encoding="utf-8")
    structured = sum(1 for r in report if r[0] != "text only")
    print(f"workbook tests: {len(rows)}; already in catalog: {len(skipped)}; imported: {len(imported)} "
          f"({structured} numeric, {len(imported) - structured} text-only)")


if __name__ == "__main__":
    main()
