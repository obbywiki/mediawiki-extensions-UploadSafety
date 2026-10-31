#!/usr/bin/env python3
"""Score a labelled image corpus against a running sidecar, to tune thresholds and compare models.

Corpus layout (labels come from the top-level folder, subfolders are free-form):

    corpus/allow/...   images that must pass (avatars, game screenshots, drawings, ...)
    corpus/reject/...  images that must be rejected

Commands:

    evaluate.py run CORPUS [--url URL] [--checks nsfw,...] [--out results.json]
    evaluate.py report RESULTS [--threshold 0.5]
    evaluate.py compare BASELINE CANDIDATE [--threshold 0.5]

`compare` matches files by SHA-1, so it is meant for checking that a new runtime or model build scores the same corpus like the old one did.
Standard library only.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path
from typing import Any

LABELS = ("allow", "reject")
THRESHOLDS = [round(0.05 * i, 2) for i in range(1, 20)]


# --- sidecar -----------------------------------------------------------------


def request(url: str, api_key: str, data: bytes | None = None, content_type: str | None = None) -> dict[str, Any]:
    headers = {"Accept": "application/json"}
    if api_key:
        headers["Authorization"] = f"Bearer {api_key}"
    if content_type:
        headers["Content-Type"] = content_type
    req = urllib.request.Request(url, data=data, headers=headers, method="POST" if data is not None else "GET")
    try:
        with urllib.request.urlopen(req, timeout=120) as response:
            return json.load(response)
    except urllib.error.HTTPError as exc:
        try:
            return json.load(exc)
        except ValueError:
            return {"error": {"code": f"http_{exc.code}", "message": exc.reason}}


def multipart(image: bytes, filename: str, body: dict[str, Any]) -> tuple[bytes, str]:
    boundary = uuid.uuid4().hex
    parts = [
        f'--{boundary}\r\nContent-Disposition: form-data; name="request"\r\nContent-Type: application/json\r\n\r\n'.encode(),
        json.dumps(body).encode(),
        f'\r\n--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\n'
        "Content-Type: application/octet-stream\r\n\r\n".encode(),
        image,
        f"\r\n--{boundary}--\r\n".encode(),
    ]
    return b"".join(parts), f"multipart/form-data; boundary={boundary}"


# --- run ---------------------------------------------------------------------


def corpus_files(corpus: Path) -> list[tuple[str, Path]]:
    files = []
    for label in LABELS:
        root = corpus / label
        if root.is_dir():
            files += [(label, path) for path in sorted(root.rglob("*")) if path.is_file() and not path.name.startswith(".")]
    return files


def run(args: argparse.Namespace) -> int:
    corpus = Path(args.corpus)
    files = corpus_files(corpus)
    if not files:
        print(f"no files under {corpus}/allow or {corpus}/reject", file=sys.stderr)
        return 2

    base = args.url.rstrip("/")
    checks = [name for name in args.checks.split(",") if name]
    info = request(f"{base}/v1/info", args.api_key)
    results: list[dict[str, Any]] = []
    seen: set[str] = set()

    for label, path in files:
        data = path.read_bytes()
        sha1 = hashlib.sha1(data).hexdigest()
        if sha1 in seen:
            continue
        seen.add(sha1)

        payload, content_type = multipart(data, path.name, {"checks": checks, "max_frames": args.max_frames})
        started = time.monotonic()
        response = request(f"{base}/v1/check", args.api_key, payload, content_type)
        elapsed = time.monotonic() - started

        entry = {
            "path": str(path.relative_to(corpus)),
            "label": label,
            "sha1": sha1,
            "seconds": round(elapsed, 3),
            "frames": response.get("image", {}).get("frames"),
            "error": response.get("error"),
            "checks": {name: summarize(result) for name, result in response.get("checks", {}).items()},
        }
        results.append(entry)
        print(f"{elapsed:6.2f}s  {label:6}  {describe(entry)}  {entry['path']}", file=sys.stderr)

    report_data = {"created": time.strftime("%Y-%m-%dT%H:%M:%S%z"), "url": base, "info": info, "files": results}
    out = Path(args.out) if args.out else Path(__file__).parent / "results" / f"{time.strftime('%Y%m%d-%H%M%S')}.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps(report_data, indent=1) + "\n")
    print(f"\nwrote {out}\n")

    print_report(report_data, args.threshold)
    return 0


def summarize(result: dict[str, Any]) -> dict[str, Any]:
    """One score per check. For region checks, that is the strongest `*_exposed` region (0 when there is none)."""
    summary = {key: result.get(key) for key in ("status", "score", "frame", "error")}
    if result.get("status") == "ok" and isinstance(result.get("regions"), list):
        exposed = [region for region in result["regions"] if str(region.get("label", "")).endswith("_exposed")]
        top = max(exposed, key=lambda region: region["score"], default=None)
        summary.update(score=top["score"] if top else 0.0, frame=top["frame"] if top else None, label=top["label"] if top else None)
        summary["regions"] = result["regions"]
    return summary


def describe(entry: dict[str, Any]) -> str:
    if entry["error"]:
        return f"error {entry['error'].get('code')}"
    return "  ".join(
        f"{name}={result['score']:.3f}" if result.get("score") is not None else f"{name}:{result.get('status')}"
        for name, result in entry["checks"].items()
    )


# --- report ------------------------------------------------------------------


def scores(report_data: dict[str, Any], check: str) -> list[tuple[str, str, float]]:
    return [
        (entry["label"], entry["path"], entry["checks"][check]["score"])
        for entry in report_data["files"]
        if entry["checks"].get(check, {}).get("score") is not None
    ]


def print_report(report_data: dict[str, Any], threshold: float) -> None:
    files = report_data["files"]
    errors = [entry for entry in files if entry["error"]]
    checks = sorted({name for entry in files for name in entry["checks"]})
    counts = {label: sum(1 for entry in files if entry["label"] == label) for label in LABELS}
    print(f"{len(files)} files ({counts['allow']} allow, {counts['reject']} reject), {len(errors)} errors")
    for entry in errors:
        print(f"  error  {entry['error'].get('code')}  {entry['path']}")

    for check in checks:
        rows = scores(report_data, check)
        allow = sorted((row for row in rows if row[0] == "allow"), key=lambda row: -row[2])
        reject = sorted((row for row in rows if row[0] == "reject"), key=lambda row: row[2])

        print(f"\n[{check}]  rejected at threshold t when score >= t")
        print("     t   false rejects   misses")
        for t in THRESHOLDS:
            false_rejects = sum(1 for row in allow if row[2] >= t)
            misses = sum(1 for row in reject if row[2] < t)
            marker = "  <" if abs(t - threshold) < 1e-9 else ""
            print(f"  {t:4.2f}   {false_rejects:4} / {len(allow):<4}   {misses:4} / {len(reject):<4}{marker}")

        if allow:
            print(f"  highest allow scores (rejected at {threshold} if >=):")
            for _, path, score in allow[:10]:
                print(f"    {score:.3f}{'  REJECTED' if score >= threshold else ''}  {path}")
        if reject:
            print(f"  lowest reject scores (missed at {threshold} if <):")
            for _, path, score in reject[:10]:
                print(f"    {score:.3f}{'  MISSED' if score < threshold else ''}  {path}")


def report(args: argparse.Namespace) -> int:
    print_report(json.loads(Path(args.results).read_text()), args.threshold)
    return 0


# --- compare -----------------------------------------------------------------


def compare(args: argparse.Namespace) -> int:
    old = {entry["sha1"]: entry for entry in json.loads(Path(args.baseline).read_text())["files"]}
    new = {entry["sha1"]: entry for entry in json.loads(Path(args.candidate).read_text())["files"]}
    shared = [sha1 for sha1 in old if sha1 in new]
    print(f"{len(shared)} shared files, {len(old) - len(shared)} only in baseline, {len(new) - len(shared)} only in candidate")

    flips = 0
    for check in sorted({name for entry in old.values() for name in entry["checks"]}):
        deltas = []
        for sha1 in shared:
            before = old[sha1]["checks"].get(check, {}).get("score")
            after = new[sha1]["checks"].get(check, {}).get("score")
            if before is None or after is None:
                continue
            deltas.append((abs(after - before), before, after, new[sha1]["path"]))

        if not deltas:
            continue
        deltas.sort(reverse=True)
        mean = sum(row[0] for row in deltas) / len(deltas)
        flipped = [row for row in deltas if (row[1] >= args.threshold) != (row[2] >= args.threshold)]
        flips += len(flipped)
        print(f"\n[{check}]  max |delta| {deltas[0][0]:.4f}, mean {mean:.4f}, {len(flipped)} verdicts flip at {args.threshold}")
        for delta, before, after, path in deltas[:10] + [row for row in flipped if row not in deltas[:10]]:
            flip = (before >= args.threshold) != (after >= args.threshold)
            print(f"  {before:.3f} -> {after:.3f}  (|{delta:.4f}|){'  FLIPS' if flip else ''}  {path}")

    old_seconds = sum(old[sha1]["seconds"] for sha1 in shared)
    new_seconds = sum(new[sha1]["seconds"] for sha1 in shared)
    print(f"\ntime: {old_seconds:.1f}s -> {new_seconds:.1f}s")
    return 1 if flips else 0


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    commands = parser.add_subparsers(dest="command", required=True)

    run_parser = commands.add_parser("run", help="score a corpus")
    run_parser.add_argument("corpus")
    run_parser.add_argument("--url", default=os.environ.get("SIDECAR_URL", "http://127.0.0.1:8090"))
    run_parser.add_argument("--api-key", default=os.environ.get("SIDECAR_API_KEY", ""))
    run_parser.add_argument("--checks", default="nsfw")
    run_parser.add_argument("--max-frames", type=int, default=64)
    run_parser.add_argument("--out")
    run_parser.add_argument("--threshold", type=float, default=0.5)
    run_parser.set_defaults(func=run)

    report_parser = commands.add_parser("report", help="summarise a results file")
    report_parser.add_argument("results")
    report_parser.add_argument("--threshold", type=float, default=0.5)
    report_parser.set_defaults(func=report)

    compare_parser = commands.add_parser("compare", help="diff two results files")
    compare_parser.add_argument("baseline")
    compare_parser.add_argument("candidate")
    compare_parser.add_argument("--threshold", type=float, default=0.5)
    compare_parser.set_defaults(func=compare)

    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
