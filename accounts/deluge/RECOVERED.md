# Deluge exports — recovered 27-Aug-2026

All three canonical exports are present, extracted from this session's own
transcript, where they had been attached as documents and never written to disk.

| File | Lines | Generated | Application |
|---|---|---|---|
| `Accounts.ds` | 62,264 | 12-Aug-2026 19:39:44 | `Accounts` |
| `Admin.ds` | 4,190 | 12-Aug-2026 19:55:01 | `Admin` |
| `../../fnb/deluge/F_B.ds` | 21,994 | 13-Aug-2026 12:34:53 | `F&B` |

Each is a complete export: Creator's `/* Author / Generated on / Version */`
header, an `application "<name>" { … }` block, and the trailing i18n dictionary.
Not truncated pastes.

## They are genuine, checked against independent counts

`F_B.ds` matches `fnb/README.md` — written by someone who read a different copy —
on all three structural counts:

- **84 forms** ✓
- **exactly 47 cross-app calls** into `accounts.` ✓
- **`DeleteAllRecords()`** present, wiping exactly **14 tables**, unguarded ✓
  (at line **4637** here; the README says 4645 — an 8-line offset, so cite 4637
  for this copy)

## Line counts differ slightly from the docs, and the docs are the stale ones

`README.md` and `ACCOUNTS_REBUILD_CONTEXT.md` §18 record 59,063 / 4,162 / 21,994.
`F_B.ds` matches exactly. The other two are **longer** here (62,264 and 4,190),
which is what a later re-export looks like — not a corrupted one. The provenance
comments in both files are Creator's own.

## ⚠️ `Accounts.ds` carries a live credential

**Line 23912 assigns a hardcoded `refreshToken`.** The repo docs flag a DoubleTick
API key at line 22851 of their copy; this is at a different offset and is a
`refreshToken` assignment. Either way the rule holds:

**Rotate it, and never commit this file.** `*.ds` is git-ignored in both
`.gitignore` and `accounts/.gitignore` — verified with `git check-ignore`. Git
history is permanent; a secret committed once is leaked even if removed later.

`Admin.ds` and `F_B.ds` carry no credential of their own.

## What was discarded

Two partial transcriptions from the prior session — a 2,435-line `Admin.ds` (41%
short) and a 3,765-line business-logic extract. Superseded by the complete files
and deleted, so nothing can cite the wrong copy.
