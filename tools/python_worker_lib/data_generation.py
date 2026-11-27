"""Data Generation domain for the Python tool worker."""
from .common import *

def generate_data_tool(args):
    distribution = args.get("distribution")
    count = int(args.get("count", 0))
    if distribution not in ("integer", "uniform", "normal"):
        raise ValueError("distribution must be integer, uniform, or normal.")
    if not 1 <= count <= 10000:
        raise ValueError("count must be between 1 and 10,000.")
    series_count = int(args.get("series_count", 1))
    if not 1 <= series_count <= 20:
        raise ValueError("series_count must be between 1 and 20.")
    low = float(args.get("min", 0))
    high = float(args.get("max", 100))
    if distribution in ("integer", "uniform") and low >= high:
        raise ValueError("min must be less than max.")
    mean = float(args.get("mean", 0))
    std = float(args.get("std", 1))
    if distribution == "normal" and std <= 0:
        raise ValueError("std must be greater than 0.")
    base_seed = args.get("seed")
    datasets = []
    for index in range(series_count):
        # Derive independent, reproducible streams without repeating a dataset.
        seed = None if base_seed is None else int(base_seed) + index
        rng = random.Random(seed)
        if distribution == "integer":
            values = [rng.randint(math.ceil(low), math.floor(high)) for _ in range(count)]
        elif distribution == "uniform":
            values = [rng.uniform(low, high) for _ in range(count)]
        else:
            values = [rng.gauss(mean, std) for _ in range(count)]
        datasets.append({"name": f"Series {index + 1}", "values": values, "seed": seed})
    result = {"distribution": distribution, "count": count, "series_count": series_count,
              "seed": base_seed, "datasets": datasets}
    # Backward compatibility: callers expecting a single `values` array still work.
    if series_count == 1:
        result["values"] = datasets[0]["values"]
    return result
