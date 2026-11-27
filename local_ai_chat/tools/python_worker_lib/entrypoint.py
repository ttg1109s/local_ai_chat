"""Entrypoint domain for the Python tool worker."""
from .common import *
from .analytics import statistics_tool
from .data_generation import generate_data_tool
from .dataframe import dataframe_tool
from .verification import verify_tool
from .math_ops import calculate
from .protocol import read_input, _json_safe
from .visualization import plot_tool

def main():
    try:
        payload = read_input()
        action = payload.get("action")
        args = payload.get("arguments", {})
        if not isinstance(args, dict): raise ValueError("arguments must be a JSON object.")
        output_dir = Path(payload.get("output_dir") or ".").resolve()
        if action == "calculate": result = calculate(args)
        elif action == "generate_data": result = generate_data_tool(args)
        elif action == "statistics": result = statistics_tool(args)
        elif action == "plot": result = plot_tool(args, output_dir)
        elif action == "dataframe": result = dataframe_tool(args)
        elif action == "verify": result = verify_tool(args)
        elif action == "web_crawl":
            web_dir = str(Path(__file__).resolve().parents[1] / "web")
            if web_dir not in sys.path: sys.path.insert(0, web_dir)
            from crawl_worker import crawl_site
            result = crawl_site(
                args.get("url", ""),
                args.get("max_pages", 8),
                args.get("max_depth", 1),
                args.get("max_chars_per_page", 20000),
            )
        else: raise ValueError("Invalid Python action.")
        response = {"success": True, "result": result}
    except Exception as exc:
        response = {"success": False, "error": str(exc)[:2000]}
    sys.stdout.write(json.dumps(_json_safe(response), ensure_ascii=False, allow_nan=False))
    sys.stdout.flush()
