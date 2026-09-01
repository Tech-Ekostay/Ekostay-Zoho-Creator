# -*- coding: utf-8 -*-
"""
Extract every FORM and its FIELD DECLARATIONS from a Creator DS export.

Companion to `parse_ds_handlers.py` (which indexes workflow bodies) and
`parse_ds_report_columns.py`. This one reads the *form* blocks, so it answers
"what control is this field, and where does it sit on the canvas" — the two
questions replication needs and no summary has ever answered correctly.

The DS nests as:

    form Payment
    {
        success message = "..."
        COA
        (
            type = picklist
            values = COA[Hide == true].ID
            displayformat = [Account_Name]
            row = 1
            column = 1
            width = medium
        )
        ...
    }

so a field is a bare identifier line followed by `(`, and its attributes are
`key = value` lines until the matching `)`.

WHAT THIS CANNOT TELL YOU. There is no `mandatory` attribute anywhere in
Accounts.ds, yet Creator renders COA with a red mandatory outline. Required-ness
is enforced in `on validate` workflow bodies, NOT in the field declaration, so
`required` is absent here by fact of the source rather than by omission. Use
`parse_ds_handlers.py --event validate` for that half.

Usage:
    python docs/parse_ds_forms.py deluge/Accounts.ds                  # summary
    python docs/parse_ds_forms.py deluge/Accounts.ds --json out.json  # full dump
    python docs/parse_ds_forms.py deluge/Accounts.ds --form Payment   # one form
"""
import argparse
import io
import json
import re
import sys

FORM_RE = re.compile(r'^\s*form\s+([A-Za-z0-9_]+)\s*$')
IDENT_RE = re.compile(r'^\s*([A-Za-z_][A-Za-z0-9_]*)\s*$')
ATTR_RE = re.compile(r'^\s*([A-Za-z][A-Za-z0-9 _]*?)\s*=\s*(.*?)\s*$')

# Attributes we normalise onto every field. Anything else is kept under `extra`
# rather than dropped -- a silently discarded attribute is how a control type
# goes missing.
CORE = {
    'type': 'type',
    'displayname': 'displayname',
    'values': 'values',
    'displayformat': 'displayformat',
    'sortorder': 'sortorder',
    'row': 'row',
    'column': 'column',
    'width': 'width',
    'height': 'height',
    'maxchar': 'maxchar',
    'format': 'format',
    'initial value': 'initial',
    'condition': 'condition',
    'alloweddays': 'alloweddays',
    'visibility': 'visibility',
}


def _unquote(v):
    v = v.strip()
    if len(v) >= 2 and v[0] == '"' and v[-1] == '"':
        return v[1:-1]
    return v


def parse(path):
    lines = io.open(path, encoding='utf-8', errors='replace').read().splitlines()
    forms = []
    i = 0
    n = len(lines)

    while i < n:
        m = FORM_RE.match(lines[i])
        if not m:
            i += 1
            continue

        name = m.group(1)
        start_line = i + 1

        # Walk to the form's opening brace, then track depth to find its end.
        j = i + 1
        while j < n and lines[j].strip() != '{':
            if lines[j].strip():          # a non-blank, non-brace line: not a form body
                break
            j += 1
        if j >= n or lines[j].strip() != '{':
            i += 1
            continue

        depth = 1
        j += 1
        fields = []
        pending = None                     # identifier awaiting its '('

        while j < n and depth > 0:
            raw = lines[j]
            t = raw.strip()

            if t == '{':
                depth += 1
                pending = None
            elif t == '}':
                depth -= 1
                pending = None
            elif t == '(' and pending:
                # Collect this field's attribute block.
                attrs, extra = {}, {}
                pdepth = 1
                j += 1
                while j < n and pdepth > 0:
                    ft = lines[j].strip()
                    if ft == ')':
                        pdepth -= 1
                        if pdepth == 0:
                            break
                        j += 1
                        continue
                    if ft == '(':
                        pdepth += 1
                        j += 1
                        continue
                    am = ATTR_RE.match(ft)
                    if am:
                        k, v = am.group(1).strip().lower(), _unquote(am.group(2))
                        (attrs if k in CORE else extra)[k] = v
                    j += 1

                f = {'name': pending, 'line': j + 1}
                for ds_key, out_key in CORE.items():
                    f[out_key] = attrs.get(ds_key)
                if extra:
                    f['extra'] = extra
                fields.append(f)
                pending = None
            else:
                im = IDENT_RE.match(raw)
                pending = im.group(1) if im else None
            j += 1

        forms.append({'name': name, 'line': start_line, 'fields': fields})
        i = j

    return forms


def dedupe(forms):
    """
    Every form is declared FOUR times -- once in the form block, three more in the
    i18n dictionary -- and the dictionary copies carry no field attributes. Counting
    naively is what turned F&B's 21 forms into "84" in two READMEs. Keep, per name,
    the declaration that actually has fields.
    """
    best = {}
    for f in forms:
        if not f['fields']:
            continue
        if f['name'] not in best or len(f['fields']) > len(best[f['name']]['fields']):
            best[f['name']] = f
    return sorted(best.values(), key=lambda x: x['line'])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('ds')
    ap.add_argument('--json')
    ap.add_argument('--form')
    ap.add_argument('--all', action='store_true',
                    help='keep the i18n duplicate declarations (default: drop them)')
    args = ap.parse_args()

    forms = parse(args.ds)
    if not args.all:
        forms = dedupe(forms)
    if args.form:
        forms = [f for f in forms if f['name'] == args.form]

    if args.json:
        io.open(args.json, 'w', encoding='utf-8').write(
            json.dumps(forms, indent=1, ensure_ascii=False))
        print('wrote %s: %d forms, %d fields'
              % (args.json, len(forms), sum(len(f['fields']) for f in forms)))
        return

    print('%-34s %6s %7s' % ('FORM', 'LINE', 'FIELDS'))
    for f in sorted(forms, key=lambda x: -len(x['fields'])):
        print('%-34s %6d %7d' % (f['name'], f['line'], len(f['fields'])))
    print('\n%d forms, %d field declarations'
          % (len(forms), sum(len(f['fields']) for f in forms)))


if __name__ == '__main__':
    main()
