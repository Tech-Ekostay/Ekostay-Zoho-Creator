# -*- coding: utf-8 -*-
"""
Extract every workflow handler from Accounts.ds, tagged with its form and event.

Built for the flow audit Husain asked for: 630-odd handlers across 46 forms is too many
to spelunk, so this produces an index first. `--event` filters, `--form` filters,
`--show` prints the Deluge body.

The DS nests as:

    workflows
      <name>
      {
        type = form
        form = Payment
        record event = on add or edit
        on validate
        {
          actions { custom deluge script ( ...body... ) }
        }
      }

so a handler's form is the last `form = X` seen at a shallower indent, and its event is
the nearest `record event =` or the `on <event>` line itself.
"""
import io, re, sys, json, argparse

SRC = r"c:\Users\Amaan Shaikh\projects\ekostay-platform\accounts\deluge\Accounts.ds"

ap = argparse.ArgumentParser()
ap.add_argument("--event", default=None, help="substring of the event, e.g. validate")
ap.add_argument("--form", default=None, help="exact form name, e.g. Payment")
ap.add_argument("--show", action="store_true", help="print the Deluge body")
ap.add_argument("--json", default=None, help="write the index to this path")
ap.add_argument("--max", type=int, default=40)
args = ap.parse_args()

lines = io.open(SRC, encoding="utf-8", errors="replace").read().split("\n")

EVENT_RE = re.compile(r"^\s*on ((?:user input of|add row of|delete row of)\s*\S*|"
                      r"validate|load|add or edit|add|edit|success|delete)\s*$")
FORM_RE = re.compile(r"^\s*form\s*=\s*(\S+)\s*$")
RECEVENT_RE = re.compile(r"^\s*record event\s*=\s*(.+?)\s*$")
WFNAME_RE = re.compile(r"^\t\t\t([A-Za-z_][A-Za-z_0-9]*)\s*$")

handlers = []
form = None
rec_event = None
wf_name = None

for i, raw in enumerate(lines):
    m = FORM_RE.match(raw)
    if m:
        form = m.group(1)
        continue

    m = RECEVENT_RE.match(raw)
    if m:
        rec_event = m.group(1)
        continue

    m = WFNAME_RE.match(raw)
    if m:
        wf_name = m.group(1)
        continue

    m = EVENT_RE.match(raw)
    if not m:
        continue

    event = m.group(1).strip()

    # Body: from here to the matching close of the handler block. Deluge bodies sit
    # inside `custom deluge script ( ... )`, so track parens from the opening one.
    body = []
    depth = 0
    started = False

    for j in range(i + 1, min(i + 1200, len(lines))):
        line = lines[j]

        if not started:
            if "custom deluge script" in line:
                started = True
            elif re.match(r"^\s*on ", line) or FORM_RE.match(line):
                break
            continue

        if "(" in line and depth == 0 and line.strip() == "(":
            depth = 1
            continue

        if depth:
            if line.strip() == ")":
                break
            body.append(line)

    handlers.append({
        "line": i + 1,
        "form": form,
        "event": event,
        "record_event": rec_event,
        "workflow": wf_name,
        "body_lines": len(body),
        "body": "\n".join(body) if args.show else None,
    })

# filter
sel = handlers
if args.event:
    sel = [h for h in sel if args.event.lower() in (h["event"] or "").lower()]
if args.form:
    sel = [h for h in sel if h["form"] == args.form]

print("%d handlers total, %d selected" % (len(handlers), len(sel)))
print()

for h in sel[:args.max]:
    print("--- line %d | form %s | %s | %d body lines ---"
          % (h["line"], h["form"], h["event"], h["body_lines"]))
    if args.show and h["body"]:
        print(h["body"])
        print()

if args.json:
    io.open(args.json, "w", encoding="utf-8").write(
        json.dumps([{k: v for k, v in h.items() if k != "body"} for h in handlers],
                   indent=2, ensure_ascii=False))
    print("index written to %s" % args.json)
