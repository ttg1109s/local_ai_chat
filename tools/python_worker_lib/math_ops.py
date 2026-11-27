"""Math Ops domain for the Python tool worker."""
from .common import *

def normalize_math_expression(expr):
    """Normalize common human math notation without evaluating or executing input."""
    if not isinstance(expr, str) or not expr.strip() or len(expr) > 4000:
        raise ValueError("Expression must be a non-empty string with at most 4,000 characters.")
    text = expr.strip()
    # Remove only enclosing Markdown/code/math delimiters, never surrounding prose.
    text = re.sub(r"^\s*```(?:python|py|math|latex)?\s*", "", text, flags=re.I)
    text = re.sub(r"\s*```\s*$", "", text)
    for left, right in (("$", "$"), (r"\(", r"\)"), (r"\[", r"\]")):
        if text.startswith(left) and text.endswith(right) and len(text) > len(left)+len(right):
            text = text[len(left):-len(right)].strip()
            break
    replacements = {
        "×": "*", "·": "*", "⋅": "*", "÷": "/", "−": "-", "–": "-", "—": "-",
        "π": "pi", "∞": "oo", "√": "sqrt",
        "≤": "<=", "≥": ">=", "≠": "!=",
        "⁰": "**0", "¹": "**1", "²": "**2", "³": "**3", "⁴": "**4",
        "⁵": "**5", "⁶": "**6", "⁷": "**7", "⁸": "**8", "⁹": "**9",
    }
    for old, new in replacements.items():
        text = text.replace(old, new)
    text = text.replace("^", "**")
    text = re.sub(r"(?<![<>=!])=(?!=)", "==", text)
    text = re.sub(r"\\(?:cdot|times)", "*", text)
    text = re.sub(r"\\(?:div)", "/", text)
    text = re.sub(r"\\(?:pi)", "pi", text)
    text = re.sub(r"\\(?:sqrt)\s*\{([^{}]+)\}", r"sqrt(\1)", text)
    text = re.sub(r"sqrt\s*\{([^{}]+)\}", r"sqrt(\1)", text)
    # Numeric juxtaposition: 2(3+4), 2pi, 3sqrt(4). Do not split identifiers.
    text = re.sub(r"\b(sin|cos|tan|asin|acos|atan|sinh|cosh|tanh|log|exp)\s*\*\*\s*(\d+)\s*\(([^()]*)\)", r"(\1(\3))**\2", text)
    text = re.sub(r"(?<=[0-9])\s*(?=[(a-zA-Z])", "*", text)
    text = re.sub(r"\bsqrt(\d+(?:\.\d+)?)", r"sqrt(\1)", text)
    text = re.sub(r"(\d+(?:\.\d+)?|[A-Za-z][A-Za-z0-9_]*|\([^()]*\))!", r"factorial(\1)", text)
    text = re.sub(r"(?<=\d)%(?=\s*$)", "/100", text)
    text = re.sub(r"(?<=[)])\s*(?=[(a-zA-Z0-9])", "*", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text

def _safe_sympy_expression(expr):
    """Parse an expression through a strict AST whitelist; never call eval/parse_expr."""
    import sympy as sp
    normalized = normalize_math_expression(expr)
    try:
        tree = ast.parse(normalized, mode="eval")
    except SyntaxError as exc:
        detail = f" at line {exc.lineno}, column {exc.offset}" if exc.offset else ""
        raise ValueError(f"Invalid mathematical expression syntax{detail}. Normalized expression: {normalized!r}. Send only the expression. Do not include prose or an equals sign followed by a result.") from None
    if sum(1 for _ in ast.walk(tree)) > 250:
        raise ValueError("Expression is too complex (maximum 250 syntax nodes).")
    constants = {"pi": sp.pi, "e": sp.E, "i": sp.I, "tau": 2*sp.pi, "oo": sp.oo}
    functions = {
        "sqrt": sp.sqrt, "abs": sp.Abs, "sin": sp.sin, "cos": sp.cos, "tan": sp.tan,
        "asin": sp.asin, "acos": sp.acos, "atan": sp.atan, "atan2": sp.atan2,
        "sinh": sp.sinh, "cosh": sp.cosh, "tanh": sp.tanh, "sech": sp.sech, "csch": sp.csch, "coth": sp.coth, "sec": sp.sec, "csc": sp.csc, "cot": sp.cot,
        "asinh": sp.asinh, "acosh": sp.acosh, "atanh": sp.atanh,
        "log": sp.log, "ln": sp.log, "log2": lambda x: sp.log(x, 2), "log10": lambda x: sp.log(x, 10),
        "exp": sp.exp, "floor": sp.floor, "ceil": sp.ceiling,
        "factorial": sp.factorial, "gamma": sp.gamma, "erf": sp.erf, "zeta": sp.zeta,
        "binomial": sp.binomial, "comb": sp.binomial, "perm": lambda n,k: sp.factorial(n)/sp.factorial(n-k), "gcd": sp.gcd, "lcm": sp.lcm, "sign": sp.sign,
        "conjugate": sp.conjugate, "re": sp.re, "im": sp.im, "arg": sp.arg,
        "degrees": lambda x: x*180/sp.pi, "radians": lambda x: x*sp.pi/180, "min": sp.Min, "max": sp.Max,
    }
    bin_ops = {
        ast.Add: lambda a,b: a+b, ast.Sub: lambda a,b: a-b,
        ast.Mult: lambda a,b: a*b, ast.Div: lambda a,b: a/b,
        ast.Pow: lambda a,b: a**b, ast.Mod: lambda a,b: sp.Mod(a,b),
        ast.FloorDiv: lambda a,b: sp.floor(a/b),
    }
    unary_ops = {ast.UAdd: lambda x:x, ast.USub: lambda x:-x}
    def visit(node):
        if isinstance(node, ast.Expression): return visit(node.body)
        if isinstance(node, ast.Constant) and isinstance(node.value, (int,float)) and not isinstance(node.value,bool):
            if abs(float(node.value)) > 1e100: raise ValueError("Constant exceeds the 1e100 limit.")
            return sp.Integer(node.value) if isinstance(node.value,int) else sp.Float(node.value)
        if isinstance(node, ast.Name):
            if node.id in constants: return constants[node.id]
            if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,31}", node.id):
                raise ValueError("Invalid variable name.")
            return sp.Symbol(node.id)
        if isinstance(node, ast.BinOp) and type(node.op) in bin_ops:
            left, right = visit(node.left), visit(node.right)
            if isinstance(node.op, ast.Pow):
                if right.is_number and abs(float(right)) > 100: raise ValueError("Exponent exceeds the limit of 100.")
                if right.is_number and left.is_number and abs(float(left)) > 1e30 and float(right) > 1:
                    raise ValueError("Exponentiation exceeds resource limits.")
            return bin_ops[type(node.op)](left,right)
        if isinstance(node, ast.UnaryOp) and type(node.op) in unary_ops:
            return unary_ops[type(node.op)](visit(node.operand))
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Name) and node.func.id in functions and not node.keywords:
            if len(node.args) > 3: raise ValueError("A function accepts at most 3 arguments.")
            fn = functions[node.func.id]
            return fn(*[visit(arg) for arg in node.args])
        if isinstance(node, ast.Tuple): return tuple(visit(item) for item in node.elts)
        if isinstance(node, ast.Compare) and len(node.ops)==1 and len(node.comparators)==1:
            left,right=visit(node.left),visit(node.comparators[0])
            op=node.ops[0]
            if isinstance(op,ast.Eq): return sp.Eq(left,right)
            if isinstance(op,ast.NotEq): return sp.Ne(left,right)
            if isinstance(op,ast.Lt): return sp.Lt(left,right)
            if isinstance(op,ast.LtE): return sp.Le(left,right)
            if isinstance(op,ast.Gt): return sp.Gt(left,right)
            if isinstance(op,ast.GtE): return sp.Ge(left,right)
        raise ValueError(f"Disallowed syntax: {type(node).__name__}.")
    try:
        return visit(tree)
    except (ZeroDivisionError, OverflowError) as exc:
        raise ValueError(f"Could not evaluate expression: {exc}") from None

def _sympy_result(value, precision=15):
    import sympy as sp
    if isinstance(value, (sp.logic.boolalg.Boolean, bool)):
        return str(value)
    if getattr(value, "free_symbols", set()):
        return str(value)
    if value in (sp.oo, -sp.oo, sp.zoo, sp.nan):
        return str(value)
    try:
        numeric = sp.N(value, precision)
        if numeric.is_real:
            f = float(numeric)
            return f if math.isfinite(f) else str(numeric)
        return str(numeric)
    except (TypeError, ValueError):
        return str(value)

def safe_calculate(expr):
    import sympy as sp
    value = _safe_sympy_expression(expr)
    if isinstance(value, sp.core.function.AppliedUndef):
        raise ValueError("Function is not supported.")
    if getattr(value, "free_symbols", set()):
        raise ValueError("The expression contains variables without values. Use mode=simplify/derivative/integrate/solve or provide substitutions.")
    if isinstance(value, sp.logic.boolalg.Boolean):
        return str(value)
    result = sp.N(value, 15)
    if result.is_real:
        out = float(result)
        if not math.isfinite(out): raise ValueError("Result is not finite.")
        if abs(out) > 1e100: raise ValueError("Result magnitude exceeds the 1e100 limit.")
        return int(out) if out.is_integer() and abs(out) < 2**53 else out
    return str(result)

def _parse_substitutions(raw):
    import sympy as sp
    if raw is None: return {}
    if not isinstance(raw, dict) or len(raw) > 50:
        raise ValueError("substitutions must be an object with at most 50 variables.")
    out={}
    for key,val in raw.items():
        if not isinstance(key,str) or not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,31}",key):
            raise ValueError("Invalid variable name in substitutions.")
        if isinstance(val,bool) or not isinstance(val,(int,float)) or not math.isfinite(float(val)):
            raise ValueError("Substitution values must be finite numbers.")
        out[sp.Symbol(key)] = val
    return out

def calculate(args):
    import sympy as sp
    expr = args.get("expression", "")
    mode = str(args.get("mode", "evaluate")).lower()
    parsed = _safe_sympy_expression(expr)
    variable_name = args.get("variable", "x")
    if not isinstance(variable_name,str) or not re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{0,31}",variable_name):
        raise ValueError("variable must be a valid variable name.")
    var = sp.Symbol(variable_name)
    substitutions = _parse_substitutions(args.get("substitutions"))
    if mode in ("evaluate","calculate","numeric"):
        value = parsed.subs(substitutions) if hasattr(parsed,"subs") else parsed
        if getattr(value,"free_symbols",set()):
            raise ValueError("Some variables have no assigned values. Provide substitutions or forose mode=simplify/derivative/integrate/solve.")
        if hasattr(value, "has") and value.has(sp.zoo, sp.nan):
            raise ValueError("Result is undefined (for example, division by zero).")
        result = _sympy_result(value)
    elif mode == "simplify":
        result = _sympy_result(sp.simplify(parsed))
    elif mode in ("factor","factorize"):
        result = _sympy_result(sp.factor(parsed))
    elif mode in ("expand","expand_polynomial"):
        result = _sympy_result(sp.expand(parsed))
    elif mode in ("derivative","differentiate","diff"):
        order = int(args.get("order",1))
        if not 1 <= order <= 30: raise ValueError("order must be between 1 and 30.")
        result = _sympy_result(sp.diff(parsed,var,order))
    elif mode in ("integral","integrate"):
        result = _sympy_result(sp.integrate(parsed,var))
        if not result.endswith(" + C") and not isinstance(sp.integrate(parsed,var),sp.Integral):
            result = str(sp.integrate(parsed,var)) + " + C"
    elif mode == "limit":
        point_raw=args.get("point",0)
        if isinstance(point_raw,str):
            point_expr=_safe_sympy_expression(point_raw)
            if getattr(point_expr,"free_symbols",set()): raise ValueError("point must be a concrete value.")
            point=point_expr
        else: point=point_raw
        direction=str(args.get("direction","+"))
        if direction not in ("+","-","+-"): raise ValueError("direction must be +, -, or +-.")
        result=_sympy_result(sp.limit(parsed,var,point,dir=direction))
    elif mode == "solve":
        result_raw=sp.solve(parsed,var)
        result=[_sympy_result(v) for v in result_raw]
    elif mode in ("nsolve", "numeric_solve"):
        guess = args.get("initial_guess", 1.0)
        if isinstance(guess, bool) or not isinstance(guess, (int, float)) or not math.isfinite(float(guess)):
            raise ValueError("initial_guess must be a finite number.")
        result = _sympy_result(sp.nsolve(parsed, var, float(guess)))
    elif mode == "series":
        point=args.get("point",0); order=int(args.get("order",6))
        if not 1 <= order <= 30: raise ValueError("order must be between 1 and 30.")
        result=_sympy_result(sp.series(parsed,var,point,order))
    elif mode == "matrix":
        matrix=args.get("matrix")
        if not isinstance(matrix,list) or not matrix or len(matrix)>30 or any(not isinstance(row,list) or len(row)>30 for row in matrix):
            raise ValueError("matrix must be a matrix with 1 to 30 rows and columns.")
        if len({len(row) for row in matrix}) != 1 or any(isinstance(v,bool) or not isinstance(v,(int,float)) or not math.isfinite(float(v)) for row in matrix for v in row):
            raise ValueError("Matrix must be rectangular and contain only finite numbers.")
        M=sp.Matrix(matrix); op=str(args.get("matrix_operation","determinant"))
        if op=="determinant":
            if M.rows != M.cols: raise ValueError("Determinant requires a square matrix.")
            result=_sympy_result(M.det())
        elif op=="inverse":
            if M.rows != M.cols: raise ValueError("Matrix inverse requires a square matrix.")
            result=str(M.inv())
        elif op=="rank": result=int(M.rank())
        elif op=="eigenvalues":
            if M.rows != M.cols: raise ValueError("Eigenvalues require a square matrix.")
            result={str(k):int(v) for k,v in M.eigenvals().items()}
        elif op=="transpose": result=str(M.T)
        elif op=="rref": result=str(M.rref()[0])
        else: raise ValueError("matrix_operation: determinant|inverse|rank|eigenvalues|transpose|rref.")
    else:
        raise ValueError("mode: evaluate|simplify|factor|expand|derivative|integrate|limit|solve|series|matrix.")
    return {"mode":mode,"expression":expr,"normalized_expression":normalize_math_expression(expr),"variable":variable_name,"result":result}
