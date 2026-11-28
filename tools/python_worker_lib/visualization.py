"""Visualization domain for the Python tool worker."""
from .common import *
from .analytics import numeric_list

def plot_tool(args, output_dir):
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    chart = str(args.get("chart_type", "line")).lower()
    title = str(args.get("title", "Chart"))[:200]
    x = args.get("x")
    raw_series = args.get("series")
    if raw_series is not None:
        if chart not in ("line", "bar"):
            raise ValueError("Multiple data series currently support only line or bar charts.")
        if not isinstance(raw_series, list) or not 1 <= len(raw_series) <= 20:
            raise ValueError("series must be a list containing 1 to 20 series.")
        series_items = []
        for index, item in enumerate(raw_series):
            if not isinstance(item, dict):
                raise ValueError(f"series[{index}] must be an object.")
            values = numeric_list(item.get("values"), f"series[{index}].values")
            if len(values) > 10000:
                raise ValueError("Each series can contain at most 10,000 items.")
            series_x = item.get("x", x)
            if series_x is None:
                series_x = list(range(1, len(values) + 1))
            if not isinstance(series_x, list) or len(series_x) != len(values) or len(series_x) > 10000:
                raise ValueError(f"series[{index}].x and values must have the same length, with at most 10,000 items.")
            if not all(isinstance(v, (str, int, float)) and not isinstance(v, bool) and len(str(v)) <= 100 and (not isinstance(v, float) or math.isfinite(v)) for v in series_x):
                raise ValueError(f"series[{index}].x contains invalid axis values.")
            label = str(item.get("name") or f"Series {index + 1}")[:100]
            series_items.append((label, series_x, values))
        if chart == "bar" and len({len(item[2]) for item in series_items}) != 1:
            raise ValueError("Multi-series bar charts require all series to have the same number of items.")
        if chart == "bar" and any(item[1] != series_items[0][1] for item in series_items[1:]):
            raise ValueError("Multi-series bar charts require identical x-axis labels across series.")
        plt.figure(figsize=(9, 5))
        for label, sx, values in series_items:
            if chart == "line":
                plt.plot(sx, values, marker="o", linewidth=1.8, label=label)
            else:
                continue
        if chart == "bar":
            # Grouped bars are redrawn with deterministic offsets.
            plt.cla()
            import numpy as np
            positions = np.arange(len(series_items[0][2]))
            width = 0.8 / len(series_items)
            for idx, (label, _sx, values) in enumerate(series_items):
                plt.bar(positions + (idx - (len(series_items)-1)/2) * width, values, width=width, label=label)
            plt.xticks(positions, [str(v) for v in series_items[0][1]])
        plt.legend()
        plt.xlabel("Observation")
        plt.ylabel("Value")
        plt.title(title)
        plt.grid(axis="y", alpha=0.2)
        plt.tight_layout()
        output_dir.mkdir(parents=True, exist_ok=True)
        filename = "chart_" + uuid.uuid4().hex + ".jpg"
        target = output_dir / filename
        fig = plt.gcf()
        fig.patch.set_facecolor("white")
        plt.savefig(target, format="jpeg", dpi=150, bbox_inches="tight",
                    facecolor="white", pil_kwargs={"quality": 92, "optimize": True, "progressive": True})
        plt.close()
        return {"chart_type": chart, "title": title, "file": filename, "format": "jpg",
                "series_count": len(series_items), "point_counts": [len(v[2]) for v in series_items],
                "series_names": [v[0] for v in series_items]}
    y = numeric_list(args.get("y"), "y")
    if chart not in ("line", "bar", "scatter", "histogram", "boxplot", "pie", "area"):
        raise ValueError("Chart type must be line, bar, scatter, histogram, boxplot, pie, or area.")
    if chart == "histogram":
        plt.hist(y, bins="auto", edgecolor="black")
        plt.xlabel("Value"); plt.ylabel("Frequency")
    elif chart == "boxplot":
        plt.boxplot(y, vert=True)
        plt.ylabel("Value")
    elif chart in ("pie", "area"):
        if x is None:
            x = list(range(1, len(y) + 1))
        if not isinstance(x, list) or len(x) != len(y) or len(x) > 10000:
            raise ValueError("x and y must be arrays of equal length, with at most 10,000 items.")
        if chart == "pie":
            if any(v < 0 for v in y) or sum(y) <= 0: raise ValueError("Pie charts require non-negative values with a sum greater than zero.")
            plt.pie(y, labels=[str(v) for v in x], autopct="%1.1f%%")
        else:
            if not all(isinstance(v, (int, float)) and not isinstance(v, bool) and math.isfinite(float(v)) for v in x):
                raise ValueError("The x-axis of an area chart must contain finite numbers.")
            plt.fill_between(x, y, alpha=0.25); plt.plot(x, y, marker="o")
    else:
        if x is None:
            x = list(range(1, len(y) + 1))
        if not isinstance(x, list) or len(x) != len(y) or len(x) > 10000:
            raise ValueError("x and y must be arrays of equal length, with at most 10,000 items.")
        if not all(isinstance(v, (str, int, float)) and not isinstance(v, bool) and len(str(v)) <= 100 and (not isinstance(v, float) or math.isfinite(v)) for v in x):
            raise ValueError("Invalid x-axis value.")
        if chart == "line": plt.plot(x, y, marker="o")
        elif chart == "bar": plt.bar([str(v) for v in x], y)
        else:
            if not all(isinstance(v, (int, float)) and not isinstance(v, bool) and math.isfinite(float(v)) for v in x): raise ValueError("The scatter chart x-axis must contain finite numbers.")
            plt.scatter(x, y)
    plt.title(title)
    if chart not in ("pie",): plt.grid(axis="y", alpha=0.2)
    plt.tight_layout()
    output_dir.mkdir(parents=True, exist_ok=True)
    filename = "chart_" + uuid.uuid4().hex + ".jpg"
    target = output_dir / filename
    fig = plt.gcf()
    fig.patch.set_facecolor("white")
    plt.savefig(target, format="jpeg", dpi=150, bbox_inches="tight",
                facecolor="white", pil_kwargs={"quality": 92, "optimize": True, "progressive": True})
    plt.close()
    return {"chart_type": chart, "title": title, "file": filename, "format": "jpg"}
