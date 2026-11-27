"""Analytics domain for the Python tool worker."""
from .common import *

def numeric_list(value, label="values"):
    if not isinstance(value, list) or not value or len(value) > MAX_ITEMS:
        raise ValueError(f"{label} must be a non-empty array with at most {MAX_ITEMS} items.")
    out = []
    for x in value:
        if isinstance(x, bool) or not isinstance(x, (int, float)):
            raise ValueError(f"{label} must contain numbers only.")
        if not math.isfinite(float(x)): raise ValueError("Data contains a non-finite value.")
        out.append(float(x))
    return out

def _finite_scalar(value, name, default, low=None, high=None):
    if value is None:
        value = default
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise ValueError(f"{name} must be a finite number.")
    value = float(value)
    if low is not None and value <= low:
        raise ValueError(f"{name} must be greater than {low}.")
    if high is not None and value >= high:
        raise ValueError(f"{name} must be less than {high}.")
    return value

def _pvalue_interpretation(p, alpha):
    if p < alpha:
        return f"There is statistical evidence against the null hypothesis at significance level {alpha:g}."
    return f"There is insufficient statistical evidence to reject the null hypothesis at significance level {alpha:g}."

def _mean_ci(arr, confidence, stats, alternative="two-sided"):
    n = int(arr.size)
    mean = float(arr.mean())
    if n < 2:
        return {"lower": None, "upper": None, "method": "undefined (at least 2 observations are required)"}
    se = float(stats.sem(arr))
    if alternative == "two-sided":
        crit = float(stats.t.ppf((1 + confidence) / 2, n - 1))
        lower, upper = mean - crit * se, mean + crit * se
    elif alternative == "less":
        crit = float(stats.t.ppf(confidence, n - 1))
        lower, upper = None, mean + crit * se
    elif alternative == "greater":
        crit = float(stats.t.ppf(confidence, n - 1))
        lower, upper = mean - crit * se, None
    else:
        raise ValueError("Invalid alternative.")
    return {"lower": lower, "upper": upper, "method": "Student's t confidence interval", "alternative": alternative,
            "standard_error": se, "degrees_of_freedom": n - 1}

def _describe_stats(x, np, stats, args):
    arr = np.asarray(x, dtype=float)
    n = int(arr.size)
    counts = {}
    for value in x:
        counts[value] = counts.get(value, 0) + 1
    max_freq = max(counts.values())
    modes = sorted(float(k) for k, v in counts.items() if v == max_freq) if max_freq > 1 else []
    q1, median, q3 = (float(v) for v in np.quantile(arr, [0.25, 0.5, 0.75], method="linear"))
    iqr = q3 - q1
    lower_fence, upper_fence = q1 - 1.5 * iqr, q3 + 1.5 * iqr
    outliers = [float(v) for v in arr if v < lower_fence or v > upper_fence]
    mean = float(np.mean(arr))
    sd_sample = float(np.std(arr, ddof=1)) if n > 1 else None
    confidence = _finite_scalar(args.get("confidence_level"), "confidence_level", 0.95, 0, 1)
    result = {
        "method": "describe", "count": n, "sum": float(np.sum(arr)),
        "mean": mean, "median": float(np.median(arr)),
        "mode": modes[0] if modes else None, "modes": modes, "mode_frequency": int(max_freq) if modes else None,
        "variance_population": float(np.var(arr, ddof=0)),
        "variance_sample": float(np.var(arr, ddof=1)) if n > 1 else None,
        "std_population": float(np.std(arr, ddof=0)), "std_sample": sd_sample,
        "standard_error_mean": float(stats.sem(arr)) if n > 1 else None,
        "min": float(np.min(arr)), "max": float(np.max(arr)), "range": float(np.ptp(arr)),
        "q1": q1, "q2": median, "q3": q3, "iqr": iqr,
        "quantile_method": "linear",
        "mad_median_absolute_deviation": float(stats.median_abs_deviation(arr, scale=1.0)),
        "coefficient_of_variation": (sd_sample / abs(mean)) if n > 1 and mean != 0 else None,
        "skewness": float(stats.skew(arr, bias=False)) if n > 2 and np.std(arr) > 0 else None,
        "kurtosis_excess": float(stats.kurtosis(arr, fisher=True, bias=False)) if n > 3 and np.std(arr) > 0 else None,
        "outlier_rule": "Tukey 1.5 × IQR",
        "outlier_fences": {"lower": float(lower_fence), "upper": float(upper_fence)},
        "outliers": outliers,
        "mean_confidence_interval": _mean_ci(arr, confidence, stats),
        "confidence_level": confidence,
        "warnings": []
    }
    if n < 2:
        result["warnings"].append("Only one observation is available: sample variance, sample standard deviation, and the confidence interval for the mean cannot be estimated.")
    if mean == 0:
        result["warnings"].append("Mean is zero: the coefficient of variation is undefined.")
    if np.std(arr) == 0:
        result["warnings"].append("All observations are equal: skewness, kurtosis, and some distribution tests are not meaningful.")
    if n < 8:
        result["warnings"].append("Small sample size; inferential statistical conclusions should be interpreted with caution.")
    return result

def statistics_tool(args):
    import numpy as np
    import scipy.stats as stats

    method = str(args.get("method", "describe")).strip().lower()
    x = numeric_list(args.get("values", [0] if method in ("anova", "one_way_anova") else None), "values")
    y_raw = args.get("y")
    confidence = _finite_scalar(args.get("confidence_level"), "confidence_level", 0.95, 0, 1)
    alpha = 1.0 - confidence
    alternative = str(args.get("alternative", "two-sided")).lower()
    if alternative not in ("two-sided", "less", "greater"):
        raise ValueError("alternative must be two-sided, less, or greater.")

    if method in ("describe", "descriptive", "summary"):
        return _describe_stats(x, np, stats, args)

    if method in ("correlation", "pearson", "spearman", "kendall"):
        y = numeric_list(y_raw, "y")
        if len(x) != len(y) or len(x) < 3:
            raise ValueError("The two arrays must have equal length and at least 3 paired observations.")
        if np.std(x) == 0 or np.std(y) == 0:
            raise ValueError("Correlation cannot be calculated when one variable has no variation.")
        kind = "pearson" if method == "correlation" else method
        if kind == "pearson":
            res = stats.pearsonr(x, y, alternative=alternative)
            r, p = float(res.statistic), float(res.pvalue)
            if len(x) > 3 and abs(r) < 1:
                z_value = float(np.arctanh(r))
                z_crit = float(stats.norm.ppf((1 + confidence) / 2))
                z_se = 1 / math.sqrt(len(x) - 3)
                ci_result = {"lower": float(np.tanh(z_value - z_crit*z_se)),
                             "upper": float(np.tanh(z_value + z_crit*z_se)), "method": "Fisher z"}
            else:
                ci_result = {"lower": None, "upper": None, "method": "not applicable (requires n>3 and |r|<1)"}
        elif kind == "spearman":
            res = stats.spearmanr(x, y, alternative=alternative)
            r, p = float(res.statistic), float(res.pvalue)
            ci_result = {"lower": None, "upper": None, "method": "exact confidence interval is unavailable"}
        else:
            res = stats.kendalltau(x, y, alternative=alternative, variant="b")
            r, p = float(res.statistic), float(res.pvalue)
            ci_result = {"lower": None, "upper": None, "method": "exact confidence interval is unavailable"}
        return {"method": kind, "n": len(x), "coefficient": r, "p_value": p,
                "confidence_level": confidence, "confidence_interval": ci_result,
                "alternative": alternative, "alpha": alpha,
                "significant": bool(p < alpha), "interpretation": _pvalue_interpretation(p, alpha),
                "warnings": ["Correlation does not establish causation."]}

    if method in ("linear_regression", "regression"):
        y = numeric_list(y_raw, "y")
        if len(x) != len(y) or len(x) < 3:
            raise ValueError("Linear regression requires two arrays of equal length and at least 3 paired observations.")
        xa, ya = np.asarray(x), np.asarray(y)
        if np.std(xa) == 0:
            raise ValueError("Regression cannot be performed when independent variable x does not vary.")
        res = stats.linregress(xa, ya, alternative=alternative)
        slope, intercept, r, p, stderr = map(float, (res.slope, res.intercept, res.rvalue, res.pvalue, res.stderr))
        fitted = intercept + slope * xa
        residuals = ya - fitted
        n = len(x)
        df = n - 2
        if alternative == "two-sided":
            tcrit = float(stats.t.ppf(1 - alpha / 2, df))
            slope_ci = {"lower": slope - tcrit * stderr, "upper": slope + tcrit * stderr}
        elif alternative == "less":
            tcrit = float(stats.t.ppf(confidence, df))
            slope_ci = {"lower": None, "upper": slope + tcrit * stderr}
        else:
            tcrit = float(stats.t.ppf(confidence, df))
            slope_ci = {"lower": slope - tcrit * stderr, "upper": None}
        sse = float(np.sum(residuals ** 2))
        sst = float(np.sum((ya - np.mean(ya)) ** 2))
        r2 = float(1 - sse / sst) if sst > 0 else None
        dw = float(np.sum(np.diff(residuals) ** 2) / sse) if sse > 0 and n > 2 else None
        return {"method": "linear_regression", "n": n, "slope": slope, "intercept": intercept,
                "equation": f"y = {intercept:.10g} + ({slope:.10g})x",
                "correlation_r": r, "r_squared": r2,
                "adjusted_r_squared": float(1 - (1-r2)*(n-1)/(n-2)) if r2 is not None and n > 2 else None,
                "p_value_slope": p, "slope_standard_error": stderr, "slope_confidence_interval": slope_ci,
                "residual_standard_error": float(math.sqrt(sse / df)) if df > 0 else None,
                "rmse": float(math.sqrt(np.mean(residuals ** 2))), "mae": float(np.mean(np.abs(residuals))),
                "durbin_watson": dw, "alpha": alpha, "significant_slope": bool(p < alpha),
                "warnings": ["Linear regression describes an association; it does not establish causation.",
                             "Confidence intervals and p-values rely on model assumptions. Check linearity, independence, homoscedasticity, and residual distribution."]}

    if method in ("one_sample_t_test", "one_sample_ttest"):
        popmean = _finite_scalar(args.get("population_mean"), "population_mean", 0)
        if len(x) < 2: raise ValueError("One-sample t-test requires at least 2 observations.")
        if np.std(x, ddof=1) == 0 and float(np.mean(x)) == popmean:
            raise ValueError("One-sample t-test is undefined when the sample equals the hypothesized mean with zero variance.")
        res = stats.ttest_1samp(x, popmean, alternative=alternative)
        ci = _mean_ci(np.asarray(x) - popmean, confidence, stats, alternative)
        p = float(res.pvalue)
        return {"method": method, "n": len(x), "sample_mean": float(np.mean(x)),
                "hypothesized_mean": popmean, "mean_difference_confidence_interval": ci,
                "t_statistic": float(res.statistic),
                "degrees_of_freedom": len(x)-1, "p_value": float(res.pvalue),
                "confidence_level": confidence, "alternative": alternative,
                "significant": bool(p < alpha), "interpretation": _pvalue_interpretation(p, alpha),
                "warnings": ["The t-test assumes independent observations. For small samples, the population distribution should be approximately normal."]}

    if method in ("two_sample_t_test", "welch_t_test", "independent_t_test"):
        y = numeric_list(y_raw, "y")
        if len(x) < 2 or len(y) < 2: raise ValueError("Two-sample testing requires at least 2 observations in each group.")
        equal_var_arg = args.get("equal_var", False)
        if not isinstance(equal_var_arg, bool):
            raise ValueError("equal_var must be a boolean.")
        equal_var = equal_var_arg
        res = stats.ttest_ind(x, y, equal_var=equal_var, alternative=alternative)
        nx, ny = len(x), len(y)
        vx, vy = float(np.var(x, ddof=1)), float(np.var(y, ddof=1))
        diff = float(np.mean(x) - np.mean(y))
        if equal_var:
            df = nx + ny - 2
            pooled = ((nx-1)*vx + (ny-1)*vy) / df
            se = math.sqrt(pooled * (1/nx + 1/ny))
        else:
            a, b = vx/nx, vy/ny
            se = math.sqrt(a+b)
            df = (a+b)**2 / (a*a/(nx-1) + b*b/(ny-1)) if (a*a/(nx-1) + b*b/(ny-1)) else float("nan")
        if alternative == "two-sided":
            crit = float(stats.t.ppf(1-alpha/2, df))
            ci_lower, ci_upper = diff-crit*se, diff+crit*se
        elif alternative == "less":
            crit = float(stats.t.ppf(confidence, df))
            ci_lower, ci_upper = None, diff+crit*se
        else:
            crit = float(stats.t.ppf(confidence, df))
            ci_lower, ci_upper = diff-crit*se, None
        if se == 0:
            raise ValueError("Independent t-test is undefined when the estimated standard error is zero.")
        p = float(res.pvalue)
        return {"method": "independent_t_test", "test_variant": "Student" if equal_var else "Welch",
                "n_x": nx, "n_y": ny, "mean_x": float(np.mean(x)), "mean_y": float(np.mean(y)),
                "mean_difference": diff, "t_statistic": float(res.statistic), "degrees_of_freedom": float(df),
                "standard_error_difference": se, "confidence_interval_difference": {"lower": ci_lower, "upper": ci_upper, "alternative": alternative},
                "p_value": p, "alternative": alternative, "alpha": alpha, "significant": bool(p < alpha),
                "interpretation": _pvalue_interpretation(p, alpha),
                "warnings": ["Welch's t-test is used by default (equal_var=false); it does not assume equal group variances.",
                             "Ensure observations are independent. Check outliers and distribution shape before interpretation."]}

    if method in ("paired_t_test", "paired_ttest"):
        y = numeric_list(y_raw, "y")
        if len(x) != len(y) or len(x) < 2: raise ValueError("Paired t-test requires two arrays of equal length and at least 2 pairs.")
        d = np.asarray(x) - np.asarray(y)
        if np.std(d, ddof=1) == 0 and float(np.mean(d)) == 0:
            raise ValueError("Paired t-test is undefined when all paired differences are zero.")
        res = stats.ttest_rel(x, y, alternative=alternative)
        ci = _mean_ci(d, confidence, stats, alternative)
        return {"method": "paired_t_test", "n_pairs": len(x), "mean_difference": float(np.mean(d)),
                "t_statistic": float(res.statistic), "degrees_of_freedom": len(x)-1,
                "p_value": float(res.pvalue), "confidence_interval_difference": ci,
                "alternative": alternative, "alpha": alpha, "significant": bool(res.pvalue < alpha),
                "interpretation": _pvalue_interpretation(float(res.pvalue), alpha),
                "warnings": ["Pairs must be matched by subject/time. The approximate normality assumption applies to the differences."]}

    if method in ("mann_whitney", "mann_whitney_u"):
        y = numeric_list(y_raw, "y")
        if not x or not y: raise ValueError("Mann–Whitney requires two non-empty groups.")
        res = stats.mannwhitneyu(x, y, alternative=alternative, method="auto")
        return {"method": "mann_whitney_u", "n_x": len(x), "n_y": len(y),
                "u_statistic": float(res.statistic), "p_value": float(res.pvalue),
                "alternative": alternative, "alpha": alpha, "significant": bool(res.pvalue < alpha),
                "interpretation": _pvalue_interpretation(float(res.pvalue), alpha),
                "warnings": ["This compares distributions/ranks between two groups. Interpret it as a median test only when distribution shapes are similar."]}

    if method in ("wilcoxon", "wilcoxon_signed_rank"):
        y = numeric_list(y_raw, "y")
        if len(x) != len(y): raise ValueError("Wilcoxon signed-rank requires two arrays of equal length.")
        d = np.asarray(x) - np.asarray(y)
        if np.all(d == 0): raise ValueError("All differences are zero; the Wilcoxon statistic is undefined.")
        res = stats.wilcoxon(x, y, alternative=alternative, method="auto")
        return {"method": "wilcoxon_signed_rank", "n_pairs": len(x),
                "statistic": float(res.statistic), "p_value": float(res.pvalue),
                "alternative": alternative, "alpha": alpha, "significant": bool(res.pvalue < alpha),
                "interpretation": _pvalue_interpretation(float(res.pvalue), alpha),
                "warnings": ["Pairs must be independent of other pairs. The method assumes symmetric difference distributions for location interpretation."]}

    if method in ("anova", "one_way_anova"):
        groups = args.get("groups")
        if not isinstance(groups, list) or len(groups) < 2:
            raise ValueError("ANOVA requires groups to be a list containing at least 2 numeric groups.")
        arrays = [np.asarray(numeric_list(g, f"groups[{i}]"), dtype=float) for i, g in enumerate(groups)]
        if any(len(g) < 2 for g in arrays): raise ValueError("Each ANOVA group requires at least 2 observations.")
        allv = np.concatenate(arrays)
        if np.std(allv) == 0:
            raise ValueError("ANOVA is undefined when all observations are identical.")
        res = stats.f_oneway(*arrays)
        grand = float(np.mean(allv))
        ss_between = float(sum(len(g)*(float(np.mean(g))-grand)**2 for g in arrays))
        ss_total = float(np.sum((allv-grand)**2))
        eta2 = ss_between/ss_total if ss_total > 0 else None
        p = float(res.pvalue)
        return {"method": "one_way_anova", "group_count": len(arrays), "group_sizes": [len(g) for g in arrays],
                "group_means": [float(np.mean(g)) for g in arrays], "f_statistic": float(res.statistic),
                "df_between": len(arrays)-1, "df_within": len(allv)-len(arrays),
                "eta_squared": eta2, "p_value": p, "alpha": alpha, "significant": bool(p < alpha),
                "interpretation": _pvalue_interpretation(p, alpha),
                "warnings": ["A significant ANOVA indicates that at least one mean differs. Use a post-hoc test (for example, Tukey HSD) to identify the differing groups.",
                             "Check independence, residual distribution, and homogeneity of variance. Consider Welch ANOVA when variances differ."]}

    if method in ("normality", "normality_test"):
        arr = np.asarray(x, dtype=float)
        if len(x) < 3: raise ValueError("Normality testing requires at least 3 observations.")
        if np.std(arr) == 0: raise ValueError("Normality cannot be tested when all values are equal.")
        results = {}
        if len(x) <= 5000:
            sh = stats.shapiro(arr)
            results["shapiro_wilk"] = {"statistic": float(sh.statistic), "p_value": float(sh.pvalue)}
        if len(x) >= 8:
            da = stats.normaltest(arr)
            results["dagostino_pearson"] = {"statistic": float(da.statistic), "p_value": float(da.pvalue)}
        return {"method": "normality_test", "n": len(x), "tests": results, "alpha": alpha,
                "interpretation": {k: ("there is evidence that the data deviate from normality" if v["p_value"] < alpha else "there is insufficient evidence to reject the normality assumption") for k, v in results.items()},
                "warnings": ["Failure to reject the null hypothesis does not prove that the data are normally distributed.",
                             "With large samples, tests are sensitive to small deviations. Also inspect a histogram and Q-Q plot."]}

    if method in ("chi_square", "chi_square_goodness_of_fit"):
        observed = np.asarray(x, dtype=float)
        expected = args.get("expected")
        if len(observed) < 2:
            raise ValueError("Chi-square goodness-of-fit requires at least 2 groups.")
        if expected is None:
            raise ValueError("Chi-square goodness-of-fit requires expected to be an array of expected frequencies.")
        exp = np.asarray(numeric_list(expected, "expected"), dtype=float)
        if not np.all(observed == np.floor(observed)):
            raise ValueError("Observed chi-square frequencies must be integer counts.")
        if len(exp) != len(observed): raise ValueError("observed and expected must have the same length.")
        if np.any(observed < 0) or np.any(exp <= 0): raise ValueError("Observed frequencies must be non-negative and expected frequencies must be greater than zero.")
        if not np.isclose(np.sum(observed), np.sum(exp), rtol=1e-7, atol=1e-9):
            raise ValueError("Observed and expected frequency totals must be equal.")
        res = stats.chisquare(observed, f_exp=exp)
        p = float(res.pvalue)
        return {"method": "chi_square_goodness_of_fit", "categories": len(x),
                "chi_square_statistic": float(res.statistic), "degrees_of_freedom": len(x)-1,
                "p_value": p, "alpha": alpha, "significant": bool(p < alpha),
                "interpretation": _pvalue_interpretation(p, alpha),
                "warnings": ["Each expected cell count should be sufficiently large (a common rule is approximately 5 or more). Otherwise, consider an exact method or combine groups."]}

    if method in ("confidence_interval_mean", "mean_ci"):
        arr = np.asarray(x, dtype=float)
        ci = _mean_ci(arr, confidence, stats)
        return {"method": "confidence_interval_mean", "n": len(x), "mean": float(np.mean(arr)),
                "confidence_level": confidence, "confidence_interval": ci,
                "warnings": ["The t confidence interval assumes independent observations. The confidence level is not the probability that a fixed parameter lies in the calculated interval."]}

    raise ValueError("Unsupported method. Available methods: describe, correlation/pearson/spearman/kendall, linear_regression, one_sample_t_test, two_sample_t_test, paired_t_test, mann_whitney, wilcoxon, anova, normality, chi_square, confidence_interval_mean.")
