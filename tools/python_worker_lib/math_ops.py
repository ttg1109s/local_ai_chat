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

def _convert_data(args):
    """Safe data conversions: bases, encodings, units, timestamps, and structured text."""
    import base64, csv, io, json, urllib.parse, datetime
    category=str(args.get("conversion_type","")).lower()
    if category=="astronomy": return _astronomy_convert(args)
    value=args.get("value")
    if category in ("number_base","base","numeric_base"):
        if isinstance(value,bool): raise ValueError("value must be a number or integer string.")
        number=int(str(value),int(args.get("from_base",10))) if isinstance(value,str) else int(value)
        to_base=int(args.get("to_base",10))
        if not 2<=to_base<=36: raise ValueError("to_base must be between 2 and 36.")
        digits="0123456789abcdefghijklmnopqrstuvwxyz"
        if number==0: converted="0"
        else:
            n=abs(number); out=""
            while n: n,r=divmod(n,to_base); out=digits[r]+out
            converted=("-" if number<0 else "")+out
        return {"value":number,"from_base":args.get("from_base",10),"to_base":to_base,"converted":converted}
    if category in ("encoding","encode","decode"):
        operation=str(args.get("operation","encode")).lower()
        encoding=str(args.get("encoding","base64")).lower()
        raw=str(value if value is not None else "")
        if encoding=="base64":
            if operation=="decode": converted=base64.b64decode(raw,validate=True).decode("utf-8")
            else: converted=base64.b64encode(raw.encode("utf-8")).decode("ascii")
        elif encoding=="hex":
            if operation=="decode": converted=bytes.fromhex(raw).decode("utf-8")
            else: converted=raw.encode("utf-8").hex()
        elif encoding in ("url","url_percent"):
            converted=urllib.parse.unquote(raw) if operation=="decode" else urllib.parse.quote(raw,safe="")
        elif encoding in ("unicode_escape","unicode"):
            converted=raw.encode("utf-8").decode("unicode_escape") if operation=="decode" else raw.encode("unicode_escape").decode("ascii")
        else: raise ValueError("encoding must be base64|hex|url|unicode_escape.")
        return {"operation":operation,"encoding":encoding,"converted":converted}
    if category in ("unit","units"):
        unit=str(args.get("from_unit","")).lower(); target=str(args.get("to_unit","")).lower()
        try: numeric=float(value)
        except (TypeError,ValueError): raise ValueError("value must be numeric.") from None
        if not math.isfinite(numeric): raise ValueError("value must be finite.")
        groups={
          "length":{"m":1,"meter":1,"meters":1,"km":1000,"kilometer":1000,"cm":.01,"mm":.001,"mi":1609.344,"mile":1609.344,"yd":.9144,"yard":.9144,"ft":.3048,"feet":.3048,"in":.0254,"inch":.0254},
          "mass":{"kg":1,"kilogram":1,"g":.001,"gram":.001,"mg":1e-6,"lb":.45359237,"lbs":.45359237,"oz":.028349523125},
          "time":{"s":1,"sec":1,"second":1,"seconds":1,"ms":.001,"min":60,"minute":60,"h":3600,"hr":3600,"hour":3600,"day":86400,"d":86400},
          "volume":{"l":1,"liter":1,"litre":1,"ml":.001,"m3":1000,"gal_us":3.785411784,"gallon":3.785411784,"cup_us":.2365882365},
          "speed":{"m/s":1,"km/h":1/3.6,"mph":.44704,"ft/s":.3048,"knot":.5144444444},
          "area":{"m2":1,"km2":1e6,"cm2":1e-4,"ft2":.09290304,"acre":4046.8564224,"ha":10000},
          "pressure":{"pa":1,"kpa":1000,"bar":100000,"atm":101325,"psi":6894.757293},
          "energy":{"j":1,"kj":1000,"cal":4.184,"kcal":4184,"wh":3600,"kwh":3600000,"ev":1.602176634e-19},
          "temperature":{"c":["c"],"f":["f"],"k":["k"]}
        }
        if unit in groups["temperature"] and target in groups["temperature"]:
            c=numeric if unit=="c" else (numeric-32)*5/9 if unit=="f" else numeric-273.15
            converted=c if target=="c" else c*9/5+32 if target=="f" else c+273.15
            return {"value":numeric,"from_unit":unit,"to_unit":target,"converted":converted}
        group=next((g for g,table in groups.items() if unit in table and target in table),None)
        if not group: raise ValueError("Units are unsupported or belong to different dimensions.")
        converted=numeric*groups[group][unit]/groups[group][target]
        return {"value":numeric,"from_unit":unit,"to_unit":target,"converted":converted,"dimension":group}
    if category in ("timestamp","datetime","date_time"):
        operation=str(args.get("operation","to_timestamp")).lower()
        unit=str(args.get("timestamp_unit","seconds")).lower()
        scales={"seconds":1,"s":1,"milliseconds":1000,"ms":1000,"microseconds":1e6,"us":1e6,"nanoseconds":1e9,"ns":1e9}
        if operation in ("to_timestamp","timestamp"):
            dt=datetime.datetime.fromisoformat(str(value).replace("Z","+00:00"))
            if dt.tzinfo is None: dt=dt.replace(tzinfo=datetime.timezone.utc)
            converted=dt.timestamp()*scales.get(unit,1)
        elif operation in ("from_timestamp","datetime"):
            if unit not in scales: raise ValueError("timestamp_unit must be seconds|milliseconds|microseconds|nanoseconds.")
            converted=datetime.datetime.fromtimestamp(float(value)/scales[unit],datetime.timezone.utc).isoformat()
        else: raise ValueError("datetime operation must be to_timestamp|from_timestamp.")
        return {"operation":operation,"timestamp_unit":unit,"converted":converted}
    if category in ("json_csv","json_to_csv","csv_json","csv_to_json","structured"):
        operation=str(args.get("operation","json_to_csv")).lower()
        if operation in ("json_to_csv","json_csv"):
            data=json.loads(value) if isinstance(value,str) else value
            if not isinstance(data,list) or not data or not all(isinstance(row,dict) for row in data): raise ValueError("JSON-to-CSV requires a non-empty list of objects.")
            output=io.StringIO(); writer=csv.DictWriter(output,fieldnames=list(dict.fromkeys(k for row in data for k in row)))
            writer.writeheader(); writer.writerows(data)
            converted=output.getvalue()
        elif operation in ("csv_to_json","csv_json"):
            converted=json.dumps(list(csv.DictReader(io.StringIO(str(value)))),ensure_ascii=False)
        else: raise ValueError("structured operation must be json_to_csv|csv_to_json.")
        return {"operation":operation,"converted":converted}
    raise ValueError("conversion_type: number_base|encoding|unit|timestamp|json_csv.")

def _astronomy_convert(args):
    """Astronomy conversions without network-dependent ephemerides."""
    op=str(args.get("astronomy_operation","")).lower()
    value=args.get("value")
    def finite_num(x,label="value"):
        if isinstance(x,bool) or not isinstance(x,(int,float)) or not math.isfinite(float(x)):
            raise ValueError(label+" must be a finite number.")
        return float(x)
    def result(v,unit=None,details=None):
        out={"converted":v}
        if unit: out["unit"]=unit
        if details: out.update(details)
        return out
    # Fixed constants and conventional conversion factors.
    AU_KM=149597870.7
    PC_AU=206264.80624709636
    LY_KM=9460730472580.8
    units_distance={"au":AU_KM,"km":1.0,"m":0.001,"ly":LY_KM,"light_year":LY_KM,"pc":PC_AU*AU_KM,"parsec":PC_AU*AU_KM,"kpc":1000*PC_AU*AU_KM,"mpc":1e6*PC_AU*AU_KM}
    if op=="distance":
        x=finite_num(value); a=str(args.get("from_unit","au")).lower(); b=str(args.get("to_unit","km")).lower()
        if a not in units_distance or b not in units_distance: raise ValueError("distance units: au|km|m|ly|pc|kpc|mpc.")
        return result(x*units_distance[a]/units_distance[b],b,{"from_value":x,"from_unit":a})
    if op=="angle":
        x=finite_num(value); a=str(args.get("from_unit","deg")).lower(); b=str(args.get("to_unit","arcsec")).lower()
        to_deg={"deg":1,"degree":1,"rad":180/math.pi,"radian":180/math.pi,"arcmin":1/60,"arcminute":1/60,"arcsec":1/3600,"arcsecond":1/3600,"mas":1/3600000,"uas":1/3600000000,"hourangle":15,"hour":15}
        if a not in to_deg or b not in to_deg: raise ValueError("angle units: deg|rad|arcmin|arcsec|mas|uas|hourangle.")
        return result(x*to_deg[a]/to_deg[b],b,{"from_value":x,"from_unit":a})
    if op=="parallax":
        x=finite_num(value); a=str(args.get("from_unit","arcsec")).lower(); b=str(args.get("to_unit","pc")).lower()
        angle_factor={"arcsec":1,"arcminute":60,"arcmin":60,"deg":3600,"mas":.001}
        dist_factor={"pc":1,"kpc":1e-3,"mpc":1e-6}
        if a in angle_factor and b in dist_factor:
            arcsec=x*angle_factor[a]
            if arcsec<=0: raise ValueError("Parallax must be positive.")
            return result((1/arcsec)*dist_factor[b],b,{"input_parallax_arcsec":arcsec,"method":"inverse-parallax"})
        if a in dist_factor and b in angle_factor:
            pc=x/dist_factor[a]
            if pc<=0: raise ValueError("Distance must be positive.")
            arcsec=1/pc
            return result(arcsec/angle_factor[b],b,{"distance_pc":pc,"method":"inverse-parallax"})
        raise ValueError("parallax supports arcsec|arcmin|deg|mas ↔ pc|kpc|mpc.")
    if op=="time":
        x=finite_num(value); a=str(args.get("from_unit","jd")).lower(); b=str(args.get("to_unit","mjd")).lower()
        if a in ("jd","julian_date") and b in ("mjd","modified_julian_date"): return result(x-2400000.5,b)
        if a in ("mjd","modified_julian_date") and b in ("jd","julian_date"): return result(x+2400000.5,b)
        if a in ("unix","unix_seconds") and b in ("jd","julian_date"): return result(x/86400+2440587.5,b)
        if a in ("jd","julian_date") and b in ("unix","unix_seconds"): return result((x-2440587.5)*86400,b)
        if a in ("unix","unix_seconds") and b in ("mjd","modified_julian_date"): return result(x/86400+40587,b)
        if a in ("mjd","modified_julian_date") and b in ("unix","unix_seconds"): return result((x-40587)*86400,b)
        if a==b: return result(x,b)
        raise ValueError("time conversions currently support JD, MJD and Unix seconds; astronomical time scales are not interchangeable without leap-second/EOP data.")
    if op=="coordinate":
        frame_a=str(args.get("from_frame","icrs")).lower(); frame_b=str(args.get("to_frame","galactic")).lower()
        lon=finite_num(args.get("longitude"),"longitude"); lat=finite_num(args.get("latitude"),"latitude")
        unit=str(args.get("coordinate_unit","deg")).lower()
        factor={"deg":1,"degree":1,"rad":180/math.pi}
        if unit not in factor: raise ValueError("coordinate_unit must be deg or rad.")
        lon*=factor[unit]; lat*=factor[unit]
        if not -90<=lat<=90: raise ValueError("Latitude/declination must be between -90 and 90 degrees.")
        if frame_a==frame_b: return {"longitude":lon,"latitude":lat,"frame":frame_b,"unit":"deg"}
        if not ((frame_a=="icrs" and frame_b in ("galactic","ecliptic")) or (frame_b=="icrs" and frame_a in ("galactic","ecliptic"))): raise ValueError("Supported coordinate transforms: ICRS↔Galactic and ICRS↔Ecliptic (J2000 approximate).")
        r=math.radians(lon); d=math.radians(lat)
        if frame_a=="icrs" and frame_b=="galactic":
            # IAU J2000 rotation matrix ICRS -> Galactic.
            M=[[-0.0548755604162154,-0.8734370902348850,-0.4838350155487132],[0.4941094278755837,-0.4448296299600112,0.7469822444972189],[-0.8676661490190047,-0.1980763734312015,0.4559837761750669]]
        elif frame_a=="galactic" and frame_b=="icrs":
            M=[[-0.0548755604162154,0.4941094278755837,-0.8676661490190047],[-0.8734370902348850,-0.4448296299600112,-0.1980763734312015],[-0.4838350155487132,0.7469822444972189,0.4559837761750669]]
        elif frame_a=="icrs" and frame_b=="ecliptic":
            eps=math.radians(23.439291111)
            M=[[1,0,0],[0,math.cos(eps),math.sin(eps)],[0,-math.sin(eps),math.cos(eps)]]
        else:
            eps=math.radians(23.439291111)
            M=[[1,0,0],[0,math.cos(eps),-math.sin(eps)],[0,math.sin(eps),math.cos(eps)]]
        v=[math.cos(d)*math.cos(r),math.cos(d)*math.sin(r),math.sin(d)]
        w=[sum(M[i][j]*v[j] for j in range(3)) for i in range(3)]
        out_lon=math.degrees(math.atan2(w[1],w[0]))%360
        out_lat=math.degrees(math.asin(max(-1,min(1,w[2]))))
        return {"longitude":out_lon,"latitude":out_lat,"frame":frame_b,"unit":"deg","epoch":"J2000 approximate"}
    if op=="physical_constant":
        x=finite_num(value); a=str(args.get("from_unit","")).lower(); b=str(args.get("to_unit","")).lower()
        tables={
          "mass":{"kg":1,"solar_mass":1.98847e30,"msun":1.98847e30,"earth_mass":5.9722e24,"me":5.9722e24,"jupiter_mass":1.89813e27,"mj":1.89813e27},
          "radius":{"m":1,"km":1000,"solar_radius":6.957e8,"rsun":6.957e8,"earth_radius":6.3781e6,"re":6.3781e6,"jupiter_radius":7.1492e7,"rj":7.1492e7},
          "luminosity":{"w":1,"solar_luminosity":3.828e26,"lsun":3.828e26},
          "energy":{"j":1,"erg":1e-7,"foe":1e44},
        }
        grp=next((g for g,t in tables.items() if a in t and b in t),None)
        if not grp: raise ValueError("physical_constant categories: mass, radius, luminosity, energy; use explicit supported units.")
        return result(x*tables[grp][a]/tables[grp][b],b,{"dimension":grp,"from_value":x,"from_unit":a})
    if op=="frequency_wavelength":
        x=finite_num(value); a=str(args.get("from_unit","hz")).lower(); b=str(args.get("to_unit","m")).lower()
        freq={"hz":1,"khz":1e3,"mhz":1e6,"ghz":1e9,"thz":1e12}
        length={"m":1,"km":1e3,"cm":1e-2,"mm":1e-3,"um":1e-6,"nm":1e-9,"angstrom":1e-10}
        c=299792458.0
        if a in freq and b in length:
            hz=x*freq[a]
            if hz<=0: raise ValueError("Frequency must be positive.")
            return result(c/hz/length[b],b,{"frequency_hz":hz,"method":"lambda=c/f"})
        if a in length and b in freq:
            lam=x*length[a]
            if lam<=0: raise ValueError("Wavelength must be positive.")
            hz=c/lam
            return result(hz/freq[b],b,{"wavelength_m":lam,"method":"f=c/lambda"})
        raise ValueError("frequency_wavelength converts frequency units ↔ wavelength units.")
    raise ValueError("astronomy_operation: distance|angle|parallax|time|coordinate|physical_constant|frequency_wavelength.")

def calculate(args):
    import sympy as sp
    expr = args.get("expression", "")
    mode = str(args.get("mode", "evaluate")).lower()
    if mode in ("convert", "conversion", "data_conversion"):
        return {"mode":mode,"conversion_type":args.get("conversion_type"),"result":_convert_data(args)}
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
    elif mode in ("numeric_integrate","integrate_numeric"):
        import scipy.integrate as spi
        lower=args.get("lower",0); upper=args.get("upper",1)
        if isinstance(lower,bool) or not isinstance(lower,(int,float)) or isinstance(upper,bool) or not isinstance(upper,(int,float)) or not math.isfinite(float(lower)) or not math.isfinite(float(upper)) or lower>=upper:
            raise ValueError("numeric integration requires finite lower < upper.")
        if getattr(parsed,"free_symbols",set())- {var}: raise ValueError("Numeric integration expression must use only the selected variable.")
        fn=sp.lambdify(var,parsed,modules=["math"])
        val,error=spi.quad(fn,float(lower),float(upper),limit=200)
        if not math.isfinite(val): raise ValueError("Numeric integration result is non-finite.")
        result={"value":float(val),"estimated_error":float(error),"interval":[float(lower),float(upper)]}
    elif mode in ("optimize","minimize","maximize"):
        from scipy.optimize import minimize_scalar
        lower=args.get("lower",-10); upper=args.get("upper",10)
        if isinstance(lower,bool) or not isinstance(lower,(int,float)) or isinstance(upper,bool) or not isinstance(upper,(int,float)) or not math.isfinite(float(lower)) or not math.isfinite(float(upper)) or lower>=upper:
            raise ValueError("Optimization requires finite lower < upper.")
        if getattr(parsed,"free_symbols",set())- {var}: raise ValueError("Optimization expression must use only the selected variable.")
        fn=sp.lambdify(var,parsed,modules=["math"])
        objective=(lambda x: fn(x)) if mode in ("optimize","minimize") else (lambda x: -fn(x))
        opt=minimize_scalar(objective,bounds=(float(lower),float(upper)),method="bounded")
        if not opt.success or not math.isfinite(float(opt.fun)): raise ValueError("Optimization failed.")
        result={"x":float(opt.x),"value":float(fn(opt.x)),"kind":"minimum" if mode in ("optimize","minimize") else "maximum","interval":[float(lower),float(upper)]}
    elif mode in ("root","find_root"):
        from scipy.optimize import root_scalar
        lower=args.get("lower",-10); upper=args.get("upper",10)
        if isinstance(lower,bool) or not isinstance(lower,(int,float)) or isinstance(upper,bool) or not isinstance(upper,(int,float)) or not math.isfinite(float(lower)) or not math.isfinite(float(upper)) or lower>=upper:
            raise ValueError("Root finding requires finite lower < upper.")
        if getattr(parsed,"free_symbols",set())- {var}: raise ValueError("Root expression must use only the selected variable.")
        fn=sp.lambdify(var,parsed,modules=["math"])
        sol=root_scalar(fn,bracket=(float(lower),float(upper)),method="brentq")
        if not sol.converged: raise ValueError("Root finding failed to converge.")
        result={"root":float(sol.root),"iterations":int(sol.iterations),"bracket":[float(lower),float(upper)]}
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
        elif op=="trace": result=_sympy_result(M.trace())
        elif op=="multiply":\n            raw_b=args.get("matrix_b")\n            if not isinstance(raw_b,list) or not raw_b or len(raw_b)>30 or any(not isinstance(row,list) or len(row)>30 for row in raw_b): raise ValueError("matrix_b must be a matrix with 1 to 30 rows and columns.")\n            if len({len(row) for row in raw_b})!=1 or any(isinstance(v,bool) or not isinstance(v,(int,float)) or not math.isfinite(float(v)) for row in raw_b for v in row): raise ValueError("matrix_b must be rectangular and contain finite numbers.")\n            B=sp.Matrix(raw_b)\n            if M.cols!=B.rows: raise ValueError("Matrix multiplication requires left columns to equal right rows.")\n            result=str(M*B)
        elif op=="power":\n            power=args.get("matrix_power",2)\n            if M.rows!=M.cols: raise ValueError("Matrix power requires a square matrix.")\n            if isinstance(power,bool) or not isinstance(power,int) or not -10<=power<=10: raise ValueError("matrix_power must be an integer from -10 to 10.")\n            result=str(M**power)
        else: raise ValueError("matrix_operation: determinant|inverse|rank|eigenvalues|transpose|rref|trace|multiply|power.")
    else:
        raise ValueError("mode: evaluate|simplify|factor|expand|derivative|integrate|limit|solve|series|matrix.")
    return {"mode":mode,"expression":expr,"normalized_expression":normalize_math_expression(expr),"variable":variable_name,"result":result}
