"""Reads the lists ANGed publishes (www.anged.nat.tn) into one JSON file for `php artisan registry:import-anged`.

    python scripts/anged/extract.py <folder of the downloaded PDFs> database/data/anged/operators.json

Two kinds of list:
  - "Sociétés autorisées par le ministère de l'environnement" (hazardous waste): one authorisation per row, with its
    number and validity dates;
  - "Cahiers des charges" (non-hazardous waste): the companies that filed the specifications for an activity.

Nothing is guessed: a cell is copied as printed (line breaks become spaces); a row that has no company name continues
the row above it (a table cut by a page). Needs pdfplumber (python -m pip install pdfplumber).
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
import unicodedata
from datetime import date
from pathlib import Path

import pdfplumber

# The pages listing the files, kept with each entry.
SOURCES = {
    True: "http://www.anged.nat.tn/Liste_societes_autorisees_dechets_dangereux.html",
    False: "http://www.anged.nat.tn/Liste_des_societes_ayant_des_cahiers_des_charges_dechets_non_dangereux14c.html",
}

# Header (normalised) → field. Headers vary slightly from one list to another.
HEADERS = [
    ("etablissement", "legal_name"),
    ("benefici", "contact_name"),
    ("tel", "phone"),
    ("fax", "fax"),
    ("siege", "head_office"),
    ("adresse", "head_office"),
    ("usine", "plant_address"),
    ("nature", "activity"),
    ("type", "waste"),
    ("n d'autorisation", "authorization_number"),
    ("n°", "authorization_number"),
    ("d'autorisation", "authorization_number"),
    ("debut", "valid_from"),
    ("fin", "valid_until"),
    ("qte", "annual_tonnage"),
    ("zone", "collection_area"),
    ("gouvernorat", "governorate"),
    ("retrait", "withdrawal"),
    ("retour", "withdrawal"),
]

GOVERNORATES = [
    "Ariana", "Béja", "Ben Arous", "Bizerte", "Gabès", "Gafsa", "Jendouba", "Kairouan", "Kasserine", "Kébili", "Le Kef",
    "Mahdia", "La Manouba", "Médenine", "Monastir", "Nabeul", "Sfax", "Sidi Bouzid", "Siliana", "Sousse", "Tataouine",
    "Tozeur", "Tunis", "Zaghouan",
]


def plain(text: str) -> str:
    text = unicodedata.normalize("NFKD", text or "")
    return "".join(c for c in text if not unicodedata.combining(c)).lower().strip()


def clean(cell) -> str:
    text = re.sub(r"\s+", " ", str(cell or "")).strip()
    return "" if text in {"-", "_", "/", "—", "–"} else text


def field_of(header: str) -> str | None:
    h = plain(header).replace("\n", " ")
    if not h:
        return None
    for prefix, field in HEADERS:
        if h.startswith(prefix) or (prefix in {"retrait", "retour", "d'autorisation"} and prefix in h):
            return field
    return None


def iso(text: str) -> str | None:
    m = re.search(r"(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})", text or "")
    if not m:
        return None
    try:
        return date(int(m.group(3)), int(m.group(2)), int(m.group(1))).isoformat()
    except ValueError:
        return None


ALIASES = {"kairaouane": "Kairouan", "kairaouan": "Kairouan", "manouba": "La Manouba", "el kef": "Le Kef", "kef": "Le Kef",
           "zaghouane": "Zaghouan", "seliana": "Siliana", "gabes": "Gabès", "beja": "Béja", "kebili": "Kébili",
           "medenine": "Médenine", "djerba": "Médenine", "jerba": "Médenine"}


def governorate(*texts: str) -> str | None:
    """The governorate named in the first text that names one (the plant before the head office), else None."""
    names = [(plain(n), n) for n in GOVERNORATES] + list(ALIASES.items())
    for text in texts:
        blob = plain(text or "")
        best = None
        for key, name in names:
            for m in re.finditer(r"\b" + re.escape(key) + r"\b", blob):
                if best is None or m.start() > best[0]:
                    best = (m.start(), name)
        if best:
            return best[1]
    return None


def roles(activity: str) -> dict[str, bool]:
    a = plain(activity)
    return {
        "is_collector": "collect" in a,
        "is_transporter": "transport" in a or "tansport" in a,
        "is_recycler": any(w in a for w in ("recycl", "valoris", "traitement", "regener", "elimin", "incinér", "inciner", "compost", "broy", "concass")),
        "is_sorter": bool(re.search(r"\btri\b|\btriage\b", a)),
        "is_storer": "stock" in a,
    }


def title_of(first_line: str) -> str:
    line = re.sub(r"\s+", " ", first_line).strip()
    m = re.search(r"gestion (?:du|des|de la|de l')\s*(.+)$", line, re.I)
    return (m.group(1) if m else line).strip(" :")


def emails(*texts: str) -> str | None:
    blob = re.sub(r"\s+", "", " ".join(t for t in texts if t))
    m = re.search(r"[\w.+-]+@[\w-]+(?:\.[\w-]+)+", blob)
    return m.group(0).lower() if m else None


def extract(path: Path) -> list[dict]:
    slug = re.sub(r"_WA.*$", "", path.stem).removeprefix("Dechets_").lower()
    entries: list[dict] = []
    with pdfplumber.open(path) as pdf:
        first = (pdf.pages[0].extract_text() or "").split("\n")[0]
        hazardous = not plain(first).startswith("cahiers")
        title = title_of(first)
        columns: list[str | None] = []
        for page in pdf.pages:
            for table in page.extract_tables():
                for row in table:
                    cells = [clean(c) for c in row]
                    # The header row (a company may be named "Etablissement …": the second cell tells them apart).
                    if len(cells) > 1 and plain(cells[0]) == "etablissement" and plain(cells[1]).startswith("benef"):
                        columns = [field_of(str(c or "")) for c in row]
                        continue
                    if not columns or not any(cells):
                        continue
                    record: dict[str, str] = {}
                    for field, value in zip(columns, cells):
                        if field and value:
                            record[field] = (record[field] + " " + value) if field in record and field == "withdrawal" else record.get(field) or value
                    if not record:
                        continue
                    if not record.get("legal_name"):
                        # A row cut by the page: its cells complete the row above.
                        if entries:
                            last = entries[-1]["_raw"]
                            for field, value in record.items():
                                last[field] = (last.get(field, "") + " " + value).strip()
                        continue
                    entries.append({"_raw": record})

    out = []
    for entry in entries:
        r = entry["_raw"]
        if not re.search(r"[^\W\d_]", r.get("legal_name", "")):
            continue  # masked in the list ("******")
        activity = r.get("activity", "")
        item = {
            "list": slug,
            "list_title": title,
            "hazardous": hazardous,
            "legal_name": r.get("legal_name", "")[:191],
            "contact_name": r.get("contact_name") or None,
            "phone": r.get("phone") or None,
            "fax": None if "@" in (r.get("fax") or "") else (r.get("fax") or None),
            "email": emails(r.get("fax", ""), r.get("phone", ""), r.get("head_office", "")),
            "head_office": r.get("head_office") or None,
            "plant_address": r.get("plant_address") or None,
            "governorate": None,
            "activity": activity or None,
            "waste": r.get("waste") or None,
            "authorization_number": r.get("authorization_number") or None,
            "valid_from": iso(r.get("valid_from", "")),
            "valid_until": iso(r.get("valid_until", "")),
            "annual_tonnage": r.get("annual_tonnage") or None,
            "collection_area": r.get("collection_area") or None,
            "withdrawal": r.get("withdrawal") or None,
            **roles(activity),
            "source_file": path.name,
            "source_url": SOURCES[hazardous],
        }
        item["governorate"] = governorate(r.get("governorate", ""), item["plant_address"] or "", item["head_office"] or "")
        key = "|".join([slug, plain(item["legal_name"]), plain(item["authorization_number"] or ""), plain(item["waste"] or ""),
                        plain(activity), plain(item["head_office"] or ""), plain(item["phone"] or "")])
        item["fingerprint"] = hashlib.sha1(key.encode("utf-8")).hexdigest()
        out.append(item)
    return out


def main() -> None:
    folder, target = Path(sys.argv[1]), Path(sys.argv[2])
    rows: list[dict] = []
    for pdf in sorted(folder.glob("*.pdf")):
        found = extract(pdf)
        print(f"{pdf.name}: {len(found)}")
        rows.extend(found)
    seen, unique = set(), []
    for row in rows:
        if row["fingerprint"] in seen:
            continue
        seen.add(row["fingerprint"])
        unique.append(row)
    target.parent.mkdir(parents=True, exist_ok=True)
    payload = {"registry": "anged", "extracted_on": date.today().isoformat(), "sources": sorted(set(SOURCES.values())), "operators": unique}
    target.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"{len(unique)} entries ({len(rows) - len(unique)} repeated rows dropped) -> {target}")


if __name__ == "__main__":
    main()
