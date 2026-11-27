import importlib.util
import tempfile
from pathlib import Path

worker_path = Path(__file__).resolve().parents[1] / "tools" / "python_worker.py"
spec = importlib.util.spec_from_file_location("lac_python_worker", worker_path)
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)

with tempfile.TemporaryDirectory() as tmp:
    for chart in ("line", "bar", "scatter", "area", "pie"):
        result = worker.plot_tool({"chart_type": chart, "y": [2, 4, 3]}, Path(tmp))
        assert result["chart_type"] == chart
        assert (Path(tmp) / result["file"]).is_file()

    for chart in ("line", "bar"):
        result = worker.plot_tool({"chart_type": chart, "series": [
            {"name": "A", "values": [1, 2, 3]},
            {"name": "B", "values": [3, 2, 1]},
        ]}, Path(tmp))
        assert result["chart_type"] == chart
        assert result["series_count"] == 2
        assert (Path(tmp) / result["file"]).is_file()

print("Python plot tests passed: omitted x gets generated index axis.")
