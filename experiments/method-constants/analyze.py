#!/usr/bin/env python3
"""Summarize the #73 sweep results.

Usage: /usr/bin/python3 -I analyze.py OUT_DIR [--lines KEY]

PHP files only, de-vendored (/vendor/, /node_modules/, /build/ dropped),
same rule as the method-branching sweep.
"""
import json, os, re, sys

VENDORED = ("/vendor/", "/node_modules/", "/build/")
REQUEST_METHOD = re.compile(r"get_method\s*\(|REQUEST_METHOD")
REGISTRATION = re.compile(r"['\"]methods['\"]\s*=>")
# Copies of core's rest_get_endpoint_args_for_schema(): $method is the constant itself.
SCHEMA_ARGS = re.compile(r"\$params\[\s*'(default|required)'\s*\]")


def load(out, key):
    d = json.load(open(os.path.join(out, f"{key}.json")))
    rows = []
    for ext in d["results"]["results"]:
        for f in ext["matches"]:
            fn = f["filename"]
            if not fn.endswith(".php") or any(v in fn for v in VENDORED):
                continue
            for m in f["matches"]:
                rows.append((ext["slug"], ext["active_installs"], fn, m["line_number"], m["line"].strip()))
    return d, rows


def unique(rows):
    """One row per source location: the searches overlap, so a line can match two."""
    return list({(r[0], r[2], r[3]): r for r in rows}.values())


def summary(rows):
    plugins = {r[0]: r[1] for r in rows}
    return len(rows), len(plugins), sum(plugins.values())


def row(label, rows):
    n, p, i = summary(rows)
    return f"| {label} | {n:,} | {p:,} | {i:,} |"


def main(out, lines_key=None):
    ids = json.load(open(os.path.join(out, "ids.json")))
    if lines_key:
        for slug, inst, fn, ln, line in sorted(load(out, lines_key)[1], key=lambda r: -r[1]):
            print(f"{inst:>9} {fn}:{ln}  {line[:160]}")
        return

    print("## Raw searches\n\n| Key | Search ID | Lines | Plugins | Installs |\n|---|---|---|---|---|")
    data = {}
    for key, meta in ids.items():
        data[key] = load(out, key)[1]
        n, p, i = summary(data[key])
        print(f"| `{key}` | `{meta['id']}` | {n:,} | {p:,} | {i:,} |")

    comparisons = unique([r for k in ("eq_after", "eq_before") for r in data[k]
                          if not REGISTRATION.search(r[4])])
    # A READABLE check that also accepts a literal 'GET' keeps working; 'GET' is no
    # fallback for CREATABLE or DELETABLE.
    get_fallback = lambda line: (re.search(r"['\"]GET['\"]", line) and "READABLE" in line
                                 and not re.search(r"CREATABLE|DELETABLE", line))
    vs_request = [r for r in comparisons if REQUEST_METHOD.search(r[4]) and not get_fallback(r[4])]
    token = [r for r in comparisons if not REQUEST_METHOD.search(r[4])]
    schema_copy = [r for r in token if SCHEMA_ARGS.search(r[4])]
    one_method = data["one_method"]
    breaks = unique(vs_request + one_method)
    # in_array hits on the request method all also list a literal 'GET', so they keep working.
    in_array_req = [r for r in data["in_array"] if REQUEST_METHOD.search(r[4])]

    print("\n## Classified\n\n| Set | Lines | Plugins | Installs |\n|---|---|---|---|")
    print(row("**Breaks if the value changes** (union of the next two)", breaks))
    print(row("compared with the request's method (`get_method()`)", vs_request))
    print(row("passed where one method is required (`'method' =>`, `new WP_REST_Request()`)", one_method))
    print(row("compared with a variable (mostly the constant passed in: survives)", token))
    print(row("of which copies of core's schema-args pattern", schema_copy))
    print(row("`in_array` on the request method (all also list `'GET'`: survives)", in_array_req))
    print(row("`switch`/`case` on a constant (largest: Elementor, on the registered constant: survives)", data["case"]))
    print(row("already treats a constant as a list (control)", data["as_list"]))
    print(row("`EDITABLE`/`ALLMETHODS` compared as one method (already broken today: control)", data["multi_eq"]))

    den = {}
    for k in ("d_readable", "d_creatable", "d_deletable"):
        den.update({r[0]: r[1] for r in data[k]})
    print(f"\nDenominator: any of READABLE/CREATABLE/DELETABLE: {len(den):,} plugins, {sum(den.values()):,} installs")


if __name__ == "__main__":
    a = sys.argv[1:]
    main(a[0], a[2] if len(a) > 2 and a[1] == "--lines" else None)
