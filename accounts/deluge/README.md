# Deluge exports — Creator source, 08-Aug-2026

`Accounts.ds` 59,063 lines · `Admin.ds` 4,162 lines · `F_B.ds` 21,994 lines.
Line counts match `ACCOUNTS_REBUILD_CONTEXT.md` §18 provenance exactly.

Structure and script only, **no records** — every claim about data volumes comes
from the report exports in `master-data/`, not from these files.

**Currently git-ignored** (`*.ds` in `.gitignore`). `Accounts.ds` contains a live
hardcoded DoubleTick API key at line 22851 — rotate it before considering these
for commit, even to a private repo.
