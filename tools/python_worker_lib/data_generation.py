"""Numeric generation, sequences, sampling, and transforms."""
from .common import *

def _finite_number(value, name):
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise ValueError(f"{name} must be a finite number.")
    return float(value)

def _safe_seed(seed):
    if seed is not None and (isinstance(seed, bool) or not isinstance(seed, int)):
        raise ValueError("seed must be an integer or null.")
    return seed

def _prime_sequence(count):
    out=[]; candidate=2
    while len(out)<count:
        prime=True
        for p in out:
            if p*p>candidate: break
            if candidate%p==0: prime=False; break
        if prime: out.append(candidate)
        candidate+=1
    return out

def _sequence(args):
    kind=args.get("sequence_type","arithmetic")
    count=args.get("count",10)
    if isinstance(count,bool) or not isinstance(count,int) or not 1<=count<=10000:
        raise ValueError("count must be an integer from 1 to 10,000.")
    start=args.get("start",0)
    if kind=="fibonacci":
        a=args.get("first",0); b=args.get("second",1); out=[]
        if not all(isinstance(v,int) and not isinstance(v,bool) for v in (a,b)): raise ValueError("Fibonacci first and second values must be integers.")
        for _ in range(count): out.append(a); a,b=b,a+b
        return out
    if kind=="prime": return _prime_sequence(count)
    if kind=="arithmetic":
        start=_finite_number(start,"start"); step=_finite_number(args.get("step",1),"step")
        out=[start+i*step for i in range(count)]
    elif kind=="geometric":
        start=_finite_number(start,"start"); ratio=_finite_number(args.get("ratio",2),"ratio")
        out=[start*(ratio**i) for i in range(count)]
    elif kind=="range":
        start=_finite_number(args.get("start",0),"start"); stop=_finite_number(args.get("stop",10),"stop")
        step=_finite_number(args.get("step",1),"step")
        if step==0 or (stop-start)*step<0: raise ValueError("range step must be non-zero and point toward stop.")
        out=[]; value=start
        for _ in range(count):
            if (step>0 and value>=stop) or (step<0 and value<=stop): break
            out.append(value); value=start+(len(out))*step
        if not out: raise ValueError("range produced no values.")
    elif kind=="logarithmic":
        start=_finite_number(args.get("start",1),"start"); base=_finite_number(args.get("base",10),"base")
        if start<=0 or base<=0 or base==1: raise ValueError("logarithmic start/base must be positive and base must not equal 1.")
        out=[start*(base**i) for i in range(count)]
    elif kind=="polynomial":
        coefficients=args.get("coefficients")
        if not isinstance(coefficients,list) or not 1<=len(coefficients)<=20: raise ValueError("polynomial requires 1..20 coefficients (lowest degree first).")
        coeff=[_finite_number(v,"coefficient") for v in coefficients]
        x_start=_finite_number(args.get("start",0),"start"); step=_finite_number(args.get("step",1),"step")
        out=[]
        for i in range(count):
            x=x_start+i*step; out.append(sum(c*(x**power) for power,c in enumerate(coeff)))
    else: raise ValueError("sequence_type must be arithmetic, geometric, fibonacci, range, logarithmic, polynomial, or prime.")
    if any(not math.isfinite(float(v)) for v in out): raise ValueError("Sequence overflowed to a non-finite value.")
    number_type=args.get("number_type","float")
    if number_type not in ("integer","float"): raise ValueError("number_type must be integer or float.")
    return [int(round(v)) for v in out] if number_type=="integer" else [float(v) for v in out]

def _sampling(args):
    population=args.get("population")
    if not isinstance(population,list) or not population: raise ValueError("sampling requires a non-empty numeric population array.")
    if len(population)>10000: raise ValueError("population is limited to 10,000 values.")
    pop=[_finite_number(v,"population value") for v in population]
    count=args.get("count",len(pop))
    if isinstance(count,bool) or not isinstance(count,int) or not 1<=count<=10000: raise ValueError("count must be an integer from 1 to 10,000.")
    replace=args.get("replace",True)
    if not isinstance(replace,bool): raise ValueError("replace must be boolean.")
    weights=args.get("weights")
    if weights is not None:
        if not isinstance(weights,list) or len(weights)!=len(pop): raise ValueError("weights must have the same length as population.")
        weights=[_finite_number(w,"weight") for w in weights]
        if any(w<0 for w in weights) or sum(weights)<=0: raise ValueError("weights must be non-negative with positive total.")
    seed=_safe_seed(args.get("seed")); rng=random.Random(seed)
    method=args.get("sampling_method","sample")
    if method=="shuffle":
        out=pop[:]; rng.shuffle(out); return out
    if method=="permutation":
        return rng.sample(pop,len(pop))
    if method!="sample": raise ValueError("sampling_method must be sample, shuffle, or permutation.")
    if not replace and count>len(pop): raise ValueError("count cannot exceed population size when replace=false.")
    if weights is None:
        out=[rng.choice(pop) for _ in range(count)] if replace else rng.sample(pop,count)
    elif replace:
        out=rng.choices(pop,weights=weights,k=count)
    else:
        # Weighted sampling without replacement via sequential weighted draws.
        remaining=list(zip(pop,weights)); out=[]
        for _ in range(count):
            total=sum(w for _,w in remaining)
            if total<=0: raise ValueError("not enough positive-weight items for weighted sampling without replacement.")
            pick=rng.random()*total; acc=0; chosen=0
            for i,(_,w) in enumerate(remaining):
                acc+=w
                if pick<acc: chosen=i; break
            item,w=remaining.pop(chosen); out.append(item)
    return out

def _transform(args):
    values=args.get("values")
    if not isinstance(values,list) or not values: raise ValueError("transform requires a non-empty values array.")
    if len(values)>10000: raise ValueError("transform is limited to 10,000 values.")
    vals=[_finite_number(v,"value") for v in values]
    op=args.get("transform_type")
    if op=="scale": out=[v*_finite_number(args.get("factor",1),"factor") for v in vals]
    elif op=="offset": out=[v+_finite_number(args.get("offset",0),"offset") for v in vals]
    elif op=="round": 
        digits=args.get("digits",0)
        if isinstance(digits,bool) or not isinstance(digits,int) or not -15<=digits<=15: raise ValueError("digits must be an integer from -15 to 15.")
        out=[round(v,digits) for v in vals]
    elif op=="clip":
        lo=_finite_number(args.get("min"),"min"); hi=_finite_number(args.get("max"),"max")
        if lo>hi: raise ValueError("clip min must be <= max.")
        out=[min(hi,max(lo,v)) for v in vals]
    elif op=="normalize":
        lo=_finite_number(args.get("target_min",0),"target_min"); hi=_finite_number(args.get("target_max",1),"target_max")
        if lo>=hi: raise ValueError("target_min must be less than target_max.")
        vmin=min(vals); vmax=max(vals)
        if vmin==vmax: raise ValueError("Cannot normalize a constant-valued array.")
        out=[lo+(v-vmin)*(hi-lo)/(vmax-vmin) for v in vals]
    elif op=="standardize":
        mean=statistics.mean(vals); sd=statistics.stdev(vals) if len(vals)>1 else 0
        if sd==0: raise ValueError("Cannot standardize constant values or a single value.")
        out=[(v-mean)/sd for v in vals]
    elif op=="absolute": out=[abs(v) for v in vals]
    elif op=="floor": out=[math.floor(v) for v in vals]
    elif op=="ceil": out=[math.ceil(v) for v in vals]
    else: raise ValueError("transform_type must be scale, offset, round, clip, normalize, standardize, absolute, floor, or ceil.")
    if any(not math.isfinite(float(v)) for v in out): raise ValueError("Transform produced non-finite values.")
    return out

def generate_number_tool(args):
    operation=args.get("operation","random")
    if operation=="sequence": return {"operation":operation,"values":_sequence(args),"count":args.get("count",10)}
    if operation=="sampling": 
        values=_sampling(args); return {"operation":operation,"values":values,"count":len(values)}
    if operation=="transform":
        values=_transform(args); return {"operation":operation,"values":values,"count":len(values)}
    if operation!="random": raise ValueError("operation must be random, sequence, sampling, or transform.")

    distribution=args.get("distribution")
    supported=("binary","integer","uniform","normal","binomial","poisson","exponential","beta","gamma","lognormal","triangular")
    if distribution not in supported: raise ValueError("Unsupported numeric distribution.")
    shape=args.get("shape","vector")
    if shape not in ("vector","matrix"): raise ValueError("shape must be vector or matrix.")
    number_type=args.get("number_type","integer" if distribution in ("binary","integer","binomial","poisson") else "float")
    if number_type not in ("integer","float"): raise ValueError("number_type must be integer or float.")
    if distribution in ("binary","binomial","poisson") and number_type!="integer": raise ValueError(f"{distribution} distribution requires integer output.")
    series_count=args.get("series_count",1)
    if isinstance(series_count,bool) or not isinstance(series_count,int) or not 1<=series_count<=20: raise ValueError("series_count must be an integer from 1 to 20.")
    if shape=="vector":
        count=args.get("count",10)
        if isinstance(count,bool) or not isinstance(count,int) or not 1<=count<=10000: raise ValueError("count must be an integer from 1 to 10,000.")
        rows=columns=None; total=count
    else:
        rows=args.get("rows"); columns=args.get("columns")
        if isinstance(rows,bool) or not isinstance(rows,int) or isinstance(columns,bool) or not isinstance(columns,int) or not 1<=rows<=10000 or not 1<=columns<=10000 or rows*columns>10000: raise ValueError("matrix rows and columns must be positive integers and total at most 10,000.")
        total=rows*columns; count=total
    low=_finite_number(args.get("min",0),"min"); high=_finite_number(args.get("max",100),"max")
    if distribution in ("integer","uniform","triangular") and low>=high: raise ValueError("min must be less than max.")
    mean=_finite_number(args.get("mean",0),"mean"); std=_finite_number(args.get("std",1),"std")
    if distribution=="normal" and std<=0: raise ValueError("std must be greater than 0.")
    probability=_finite_number(args.get("probability",0.5),"probability")
    if distribution in ("binary","binomial") and not 0<=probability<=1: raise ValueError("probability must be between 0 and 1.")
    n=args.get("trials",10)
    if isinstance(n,bool) or not isinstance(n,int) or not 1<=n<=10000: raise ValueError("trials must be an integer from 1 to 10,000.")
    if distribution=="binomial" and total*n>1000000: raise ValueError("binomial total trials across all generated values must not exceed 1,000,000.")
    lam=_finite_number(args.get("lambda",4),"lambda")
    if distribution=="poisson" and (lam<=0 or lam>100): raise ValueError("lambda must be greater than 0 and at most 100 for bounded generation.")
    scale=_finite_number(args.get("scale",1),"scale")
    if distribution=="exponential" and scale<=0: raise ValueError("scale must be greater than 0.")
    alpha=_finite_number(args.get("alpha",2),"alpha"); beta=_finite_number(args.get("beta",5),"beta")
    if distribution=="beta" and (alpha<=0 or beta<=0): raise ValueError("alpha and beta must be greater than 0.")
    if distribution=="gamma" and (alpha<=0 or scale<=0): raise ValueError("gamma shape alpha and scale must be greater than 0.")
    if distribution=="lognormal" and std<=0: raise ValueError("lognormal sigma (std) must be greater than 0.")
    mode=_finite_number(args.get("mode",(low+high)/2),"mode")
    if distribution=="triangular" and not low<=mode<=high: raise ValueError("triangular mode must be between min and max.")
    seed=_safe_seed(args.get("seed"))
    def sample(rng):
        if distribution=="binary": v=1 if rng.random()<probability else 0
        elif distribution=="integer": v=rng.randint(math.ceil(low),math.floor(high))
        elif distribution=="uniform": v=rng.randint(math.ceil(low),math.floor(high)) if number_type=="integer" else rng.uniform(low,high)
        elif distribution=="normal": v=rng.gauss(mean,std); v=round(v) if number_type=="integer" else v
        elif distribution=="binomial": v=sum(1 for _ in range(n) if rng.random()<probability)
        elif distribution=="poisson":
            threshold=math.exp(-lam); product=1; k=0
            while product>threshold: product*=rng.random(); k+=1
            v=k-1
        elif distribution=="exponential": v=rng.expovariate(1/scale)
        elif distribution=="beta": v=rng.betavariate(alpha,beta)
        elif distribution=="gamma": v=rng.gammavariate(alpha,scale)
        elif distribution=="lognormal": v=rng.lognormvariate(mean,std)
        else: v=rng.triangular(low,high,mode)
        if isinstance(v,float) and not math.isfinite(v): raise ValueError("Generated non-finite numeric value.")
        return int(round(v)) if number_type=="integer" and distribution not in ("binary","integer","binomial","poisson","uniform") else (int(v) if number_type=="integer" else float(v))
    datasets=[]
    for i in range(series_count):
        s=None if seed is None else seed+i; rng=random.Random(s)
        flat=[sample(rng) for _ in range(total)]
        values=flat if shape=="vector" else [flat[r*columns:(r+1)*columns] for r in range(rows)]
        datasets.append({"name":f"Series {i+1}","values":values,"seed":s})
    result={"operation":"random","distribution":distribution,"shape":shape,"number_type":number_type,"count":count,"rows":rows,"columns":columns,"series_count":series_count,"seed":seed,"datasets":datasets}
    if series_count==1: result["values"]=datasets[0]["values"]
    return result
