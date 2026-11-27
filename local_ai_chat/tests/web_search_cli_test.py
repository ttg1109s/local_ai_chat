import ast
from pathlib import Path
source = Path(__file__).resolve().parents[1] / "tools" / "web_search.py"
tree = ast.parse(source.read_text(encoding="utf-8"))
found = False
for node in ast.walk(tree):
    if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute) and node.func.attr == "add_argument":
        if node.args and isinstance(node.args[0], ast.Constant) and node.args[0].value == "--mode":
            found = any(keyword.arg == "choices" for keyword in node.keywords)
            break
if not found:
    raise SystemExit("FAIL: --mode must use argparse's choices keyword")
print("PASS: web search CLI mode choices are configured correctly")
