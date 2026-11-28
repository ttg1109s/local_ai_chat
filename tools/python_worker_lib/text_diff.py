"""Text comparison powered by Python's standard-library difflib."""
from difflib import SequenceMatcher, unified_diff, HtmlDiff

MAX_DIFF_TEXT = 100_000

def text_diff_tool(args):
    old = args.get("old_text")
    new = args.get("new_text")
    if not isinstance(old, str) or not isinstance(new, str):
        raise ValueError("old_text and new_text must be strings.")
    if len(old) > MAX_DIFF_TEXT or len(new) > MAX_DIFF_TEXT:
        raise ValueError("Each text input must not exceed 100000 characters.")

    mode = args.get("mode", "line")
    if mode not in ("line", "character"):
        raise ValueError("mode must be line or character.")
    context = args.get("context_lines", 3)
    if isinstance(context, bool) or not isinstance(context, int) or not 0 <= context <= 10:
        raise ValueError("context_lines must be an integer from 0 to 10.")
    include_html = args.get("include_html", False)
    if not isinstance(include_html, bool):
        raise ValueError("include_html must be a boolean.")

    old_units = old.splitlines(keepends=True) if mode == "line" else list(old)
    new_units = new.splitlines(keepends=True) if mode == "line" else list(new)
    matcher = SequenceMatcher(a=old_units, b=new_units, autojunk=False)
    changes = []
    counts = {"equal": 0, "insert": 0, "delete": 0, "replace": 0}

    for tag, i1, i2, j1, j2 in matcher.get_opcodes():
        if tag == "equal":
            counts[tag] += i2 - i1
            continue
        if tag == "insert":
            counts[tag] += j2 - j1
        elif tag == "delete":
            counts[tag] += i2 - i1
        else:
            counts[tag] += max(i2 - i1, j2 - j1)
        changes.append({
            "operation": tag,
            "old_range": {"start": i1 + 1 if i1 < i2 else None, "end": i2 if i1 < i2 else None},
            "new_range": {"start": j1 + 1 if j1 < j2 else None, "end": j2 if j1 < j2 else None},
            "old_content": "".join(old_units[i1:i2]),
            "new_content": "".join(new_units[j1:j2]),
        })

    result = {
        "mode": mode,
        "identical": old == new,
        "similarity_ratio": round(matcher.ratio(), 6),
        "old_length": len(old_units),
        "new_length": len(new_units),
        "summary": {
            "changes": len(changes),
            "equal_units": counts["equal"],
            "inserted_units": counts["insert"],
            "deleted_units": counts["delete"],
            "replaced_units": counts["replace"],
        },
        "changes": changes,
        "unified_diff": "".join(unified_diff(old_units, new_units, fromfile="old", tofile="new", n=context)),
    }
    if include_html:
        result["html_diff"] = HtmlDiff(wrapcolumn=100).make_table(
            old.splitlines(), new.splitlines(), fromdesc="Old", todesc="New",
            context=True, numlines=context
        )
    return result
