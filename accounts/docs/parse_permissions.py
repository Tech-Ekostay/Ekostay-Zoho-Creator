"""
Extract the Creator permission matrix from the share_settings block of a .ds export.

The block is brace-nested, not line-oriented, so this walks braces rather than
pattern-matching. Shape:

    share_settings
    {
        "Read"
        {
            name = "Read"
            type = Users_Permissions
            permissions = {Chat:false, ...}
            description = "..."
            ModulePermissions
            {
                Billing_Cycles
                {
                    allFieldsVisible= true
                    ReportPermissions
                    {
                        All_Billing_Cycles={"View"}
                    }
                }
            }
        }
    }

Usage: python docs/parse_permissions.py deluge/Accounts.ds
"""
import json
import re
import sys
from collections import OrderedDict


def find_block(src, header):
    """Return the text inside the braces of the first `header` block."""
    i = src.index(header)
    i = src.index("{", i)
    depth = 0
    for j in range(i, len(src)):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[i + 1:j]
    raise ValueError(f"unbalanced braces after {header!r}")


def split_children(body):
    """Yield (label, inner_text) for each `label { ... }` child at this level."""
    i = 0
    n = len(body)
    while i < n:
        # find the next '{' and treat the preceding non-brace text as the label
        brace = body.find("{", i)
        if brace == -1:
            return
        label = body[i:brace].strip()
        # skip assignments like `permissions = {..}` / `X={"View"}` — those are
        # values, not child blocks; they have '=' immediately before the brace
        depth = 0
        end = None
        for j in range(brace, n):
            if body[j] == "{":
                depth += 1
            elif body[j] == "}":
                depth -= 1
                if depth == 0:
                    end = j
                    break
        if end is None:
            return
        inner = body[brace + 1:end]
        if label.endswith("=") or "=" in label.split("\n")[-1]:
            # an assignment, e.g. `All_Bills={"View","Add"}`
            name = label.rstrip("=").strip().split("\n")[-1].strip()
            yield ("=" + name, inner)
        else:
            yield (label.split("\n")[-1].strip(), inner)
        i = end + 1


def parse_perm_list(text):
    return [p.strip().strip('"') for p in text.split(",") if p.strip()]


def main(path):
    src = open(path, encoding="utf-8", errors="replace").read()
    body = find_block(src, "\n\tshare_settings")

    profiles = []
    for label, inner in split_children(body):
        if label.startswith("="):
            continue
        name = re.search(r'name = "([^"]*)"', inner)
        desc = re.search(r'description = "([^"]*)"', inner)
        perms = re.search(r"permissions = \{([^}]*)\}", inner)

        modules = OrderedDict()
        for sub, subinner in split_children(inner):
            if sub != "ModulePermissions":
                continue
            for mod, modinner in split_children(subinner):
                if mod.startswith("="):
                    continue
                afv = re.search(r"allFieldsVisible= *(\w+)", modinner)
                reports = OrderedDict()
                for rp, rpinner in split_children(modinner):
                    if rp == "ReportPermissions":
                        for rep, repinner in split_children(rpinner):
                            if rep.startswith("="):
                                reports[rep[1:]] = parse_perm_list(repinner)
                    elif rp == "FieldPermissions":
                        pass
                modules[mod] = {
                    "allFieldsVisible": (afv.group(1) == "true") if afv else None,
                    "reports": reports,
                }

        profiles.append({
            "profile": (name.group(1) if name else label.strip('"')),
            "label": label.strip('"'),
            "description": (desc.group(1) if desc else "").strip(),
            "flags": (perms.group(1).strip() if perms else ""),
            "modules": modules,
        })

    return profiles


if __name__ == "__main__":
    profiles = main(sys.argv[1] if len(sys.argv) > 1 else "deluge/Accounts.ds")
    out = "docs/permission_matrix.json"
    json.dump(profiles, open(out, "w", encoding="utf-8"), indent=1, ensure_ascii=False)

    print(f"profiles: {len(profiles)}\n")
    verbs = {}
    for p in profiles:
        nrep = sum(len(m["reports"]) for m in p["modules"].values())
        for m in p["modules"].values():
            for v in m["reports"].values():
                for x in v:
                    verbs[x] = verbs.get(x, 0) + 1
        print(f"  {p['profile']:30} modules={len(p['modules']):3} reports={nrep:4}")
    print(f"\npermission verbs: {verbs}")
    print(f"written -> {out}")
