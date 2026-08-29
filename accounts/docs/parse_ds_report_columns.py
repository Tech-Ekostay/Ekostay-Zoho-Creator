# -*- coding: utf-8 -*-
"""
Extract every report's column list, IN ORDER, from Accounts.ds — including the action
buttons and the conditions that enable them.

Husain: "I need all the columns on the index page and edit page to be like this."

The DS declares a report as

    default list All_Pending_Approvals
    {
        displayName = "All Pending Approvals"
        show all rows from Pending_Approvals
        (
            Payment_No as "Payment No"
            Status
            Approved_By.Approver as "Approved By"
            custom action "Approve"
            (
                workflow = Approve
                column header = "Approve"
                condition = (Status == "Sent for Approval" && Approvers.Email == zoho.loginuserid)
            )
            ...
        )
    }

so the parenthesised block IS the column list in display order. THE NESTING MATTERS: a
first version stopped at the `)` closing `custom action "Approve"` and reported 6 columns
where the live report shows 24. Depth is tracked properly here.

The `condition` on a custom action is the button's enablement rule, which is worth more
than the column name — it is the difference between a button that works and a button that
looks like it works.
"""
import io, re, json, sys

SRC = r"c:\Users\Amaan Shaikh\projects\ekostay-platform\accounts\deluge\Accounts.ds"
lines = io.open(SRC, encoding="utf-8", errors="replace").read().split("\n")

REPORT_RE = re.compile(r"^\t\t(?:default list|list)\s+(\S+)\s*$")
DISPLAY_RE = re.compile(r'^\s*displayName\s*=\s*"(.+?)"\s*$')
SOURCE_RE = re.compile(r"^\s*show all rows from\s+(\S+)")
ACTION_RE = re.compile(r'^\s*custom action\s+"(.+?)"\s*$')
COND_RE = re.compile(r"^\s*condition\s*=\s*(.+?)\s*$")
HEADER_RE = re.compile(r'^\s*column header\s*=\s*"(.+?)"\s*$')
COL_RE = re.compile(r'^\s*([A-Za-z_][A-Za-z_0-9.]*)(?:\s+as\s+"(.*?)")?\s*$')

reports = []
i = 0

while i < len(lines):
    m = REPORT_RE.match(lines[i])
    if not m:
        i += 1
        continue

    name = m.group(1)
    display = source = None
    cols = []
    depth = 0
    started = False
    pending_action = None

    j = i + 1
    while j < min(i + 800, len(lines)):
        line = lines[j]
        stripped = line.strip()

        if not started:
            d = DISPLAY_RE.match(line)
            if d and display is None:
                display = d.group(1)
            s = SOURCE_RE.match(line)
            if s and source is None:
                source = s.group(1)
            if stripped == "(":
                started = True
                depth = 1
            elif REPORT_RE.match(line) and j > i:
                break
            j += 1
            continue

        # inside the column block: track depth so nested action blocks do not end it
        if stripped == "(":
            depth += 1
            j += 1
            continue

        if stripped == ")":
            depth -= 1
            if depth == 0:
                break
            j += 1
            continue

        a = ACTION_RE.match(line)
        if a:
            pending_action = {"kind": "action", "label": a.group(1), "condition": None}
            cols.append(pending_action)
            j += 1
            continue

        if depth > 1 and pending_action is not None:
            c = COND_RE.match(line)
            if c and pending_action["condition"] is None:
                pending_action["condition"] = c.group(1)
            h = HEADER_RE.match(line)
            if h:
                pending_action["label"] = h.group(1)
            j += 1
            continue

        if depth == 1:
            c = COL_RE.match(line)
            if c:
                cols.append({
                    "kind": "field",
                    "label": c.group(2) if c.group(2) is not None else c.group(1),
                    "field": c.group(1),
                })
        j += 1

    reports.append({
        "name": name,
        "display": display or name,
        "source_form": source,
        "column_count": len(cols),
        "columns": cols,
    })
    i += 1

reports.sort(key=lambda r: r["display"])
want = sys.argv[1] if len(sys.argv) > 1 else None

if want:
    for r in reports:
        if want.lower() in r["display"].lower() or want.lower() in r["name"].lower():
            print("%s  (form %s)  %d columns" % (r["display"], r["source_form"], r["column_count"]))
            for k, c in enumerate(r["columns"], 1):
                if c["kind"] == "action":
                    print("  %2d  [ACTION] %-28s %s" % (k, c["label"], (c["condition"] or "always")[:80]))
                else:
                    print("  %2d  %-38s %s" % (k, c["label"], c["field"] if c["field"] != c["label"] else ""))
            print()
else:
    print("%d reports parsed\n" % len(reports))
    for r in reports:
        acts = sum(1 for c in r["columns"] if c["kind"] == "action")
        print("  %-44s %-22s %3d cols (%d actions)"
              % (r["display"][:44], (r["source_form"] or "-")[:22], r["column_count"], acts))
    io.open("docs/ds_report_columns.json", "w", encoding="utf-8").write(
        json.dumps(reports, indent=2, ensure_ascii=False))
    print("\nwritten to docs/ds_report_columns.json")
