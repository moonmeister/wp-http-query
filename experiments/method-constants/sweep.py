#!/usr/bin/env python3
"""Run the #73 veloria.dev searches and save each result as JSON.

Usage: /usr/bin/python3 -I sweep.py OUT_DIR

Creates (or reuses, via OUT_DIR/ids.json) one search per entry in SEARCHES,
polls until each completes, and writes OUT_DIR/<key>.json. Results are not
committed; the regexes and search IDs are.
"""
import json, os, sys, time, urllib.request, urllib.error

API = "https://veloria.dev/api/v1/search"
S = r"\\?WP_REST_Server::"          # optional leading backslash
SINGLE = r"(READABLE|CREATABLE|DELETABLE)"
MULTI = r"(EDITABLE|ALLMETHODS)"
EQ = r"(===|==|!==|!=)"

SEARCHES = {
    # Single-method constants used as exactly one method.
    "eq_after":   rf"{S}{SINGLE}\s*{EQ}",
    "eq_before":  rf"{EQ}\s*{S}{SINGLE}\b",
    "in_array":   rf"in_array\s*\([^;]{{0,200}}{S}{SINGLE}\b",
    "case":       rf"case\s+{S}{SINGLE}\b",
    "one_method": rf"(WP_REST_Request\s*\(|set_method\s*\(|['\"]method['\"]\s*=>)\s*{S}{SINGLE}\b",
    "key":        rf"({S}{SINGLE}\s*=>|\[\s*{S}{SINGLE}\s*\])",
    # Multi-method constants compared as if single: already broken today.
    "multi_eq":   rf"({S}{MULTI}\s*{EQ}|{EQ}\s*{S}{MULTI}\b)",
    # Control: already treats a constant as a list.
    "as_list":    rf"(explode|strpos|str_contains|stripos)\s*\([^;]{{0,80}}{S}",
    # Denominators.
    "d_readable":  r"WP_REST_Server::READABLE",
    "d_creatable": r"WP_REST_Server::CREATABLE",
    "d_deletable": r"WP_REST_Server::DELETABLE",
}

def req(method, url, body=None):
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(url, data=data, method=method,
                               headers={"Content-Type": "application/json",
                                        "User-Agent": "wp-http-query-sweep/1 (github.com/moonmeister/wp-http-query)"})
    for attempt in range(8):
        try:
            with urllib.request.urlopen(r, timeout=120) as resp:
                return json.load(resp)
        except urllib.error.HTTPError as e:
            if e.code == 429:
                time.sleep(15 * (attempt + 1))
                continue
            raise
    raise RuntimeError(f"rate limited: {url}")

def main(out):
    os.makedirs(out, exist_ok=True)
    ids_path = os.path.join(out, "ids.json")
    ids = json.load(open(ids_path)) if os.path.exists(ids_path) else {}
    for key, term in SEARCHES.items():
        if ids.get(key, {}).get("term") != term:
            res = req("POST", API, {"term": term, "repo": "plugins"})
            ids[key] = {"id": res["id"], "term": term}
            stale = os.path.join(out, f"{key}.json")
            if os.path.exists(stale):
                os.remove(stale)  # results of the old regex
            json.dump(ids, open(ids_path, "w"), indent=2)
            time.sleep(10)
    for key, meta in ids.items():
        path = os.path.join(out, f"{key}.json")
        if os.path.exists(path):
            continue
        while True:
            d = req("GET", f"{API}/{meta['id']}")
            if d["status"] == "completed":
                break
            if d["status"] == "failed":
                raise RuntimeError(f"{key} failed: {d}")
            time.sleep(10)
        json.dump(d, open(path, "w"))
        print(f"{key}: {d['total_matches']} lines, {d['total_extensions']} plugins", flush=True)

if __name__ == "__main__":
    main(sys.argv[1])
