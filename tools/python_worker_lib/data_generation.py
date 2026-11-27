"""Data Generation domain for the Python tool worker."""
from .common import *

def generate_data_tool(args):
    distribution = args.get("distribution")
    raw_count = args.get("count", 0)
    if isinstance(raw_count, bool) or not isinstance(raw_count, int):
        raise ValueError("count must be an integer.")
    count = raw_count
    if distribution not in ("integer", "uniform", "normal"):
        raise ValueError("distribution must be integer, uniform, or normal.")
    if not 1 <= count <= 10000:
        raise ValueError("count must be between 1 and 10,000.")
    raw_series_count = args.get("series_count", 1)
    if isinstance(raw_series_count, bool) or not isinstance(raw_series_count, int):
        raise ValueError("series_count must be an integer.")
    series_count = raw_series_count
    if not 1 <= series_count <= 20:
        raise ValueError("series_count must be between 1 and 20.")
    low = float(args.get("min", 0))
    high = float(args.get("max", 100))
    if not math.isfinite(low) or not math.isfinite(high):
        raise ValueError("min and max must be finite numbers.")
    if distribution in ("integer", "uniform") and low >= high:
        raise ValueError("min must be less than max.")
    if distribution == "uniform" and not math.isfinite(high - low):
        raise ValueError("Uniform range is too large to represent safely.")
    if distribution == "integer" and math.ceil(low) > math.floor(high):
        raise ValueError("min and max must include at least one integer value.")
    mean = float(args.get("mean", 0))
    std = float(args.get("std", 1))
    if distribution == "normal" and (not math.isfinite(mean) or not math.isfinite(std) or std <= 0):
        raise ValueError("mean must be finite and std must be a finite number greater than 0.")
    base_seed = args.get("seed")
    if base_seed is not None and (isinstance(base_seed, bool) or not isinstance(base_seed, int)):
        raise ValueError("seed must be an integer or null.")
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
            if not all(math.isfinite(value) for value in values):
                raise ValueError("Normal distribution generated a non-finite value; reduce mean or std.")
        datasets.append({"name": f"Series {index + 1}", "values": values, "seed": seed})
    result = {"distribution": distribution, "count": count, "series_count": series_count,
              "seed": base_seed, "datasets": datasets}
    # Backward compatibility: callers expecting a single `values` array still work.
    if series_count == 1:
        result["values"] = datasets[0]["values"]
    return result
