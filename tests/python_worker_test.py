import json, subprocess, sys
from pathlib import Path
WORKER = Path(__file__).resolve().parents[1] / 'tools' / 'python_worker.py'
def run(payload):
    p = subprocess.run([sys.executable, str(WORKER)], input=json.dumps(payload), text=True, capture_output=True, timeout=20)
    assert p.returncode == 0, p.stderr
    return json.loads(p.stdout)
r = run({'action':'generate_number','arguments':{'distribution':'integer','count':12,'min':1,'max':100,'seed':7}})
assert r['success'] and len(r['result']['values']) == 12 and all(isinstance(v, int) for v in r['result']['values'])
r2 = run({'action':'generate_number','arguments':{'distribution':'integer','count':12,'min':1,'max':100,'seed':7}})
assert r2['result']['values'] == r['result']['values'], 'seed must make generated data reproducible'
r = run({'action':'calculate','arguments':{'expression':'sqrt(81)+2'}})
assert r['success'] and r['result']['result'] == 11
r = run({'action':'statistics','arguments':{'method':'one_sample_t_test','values':[1,2,3,4,5],'population_mean':0,'alternative':'greater'}})
ci = r['result']['mean_difference_confidence_interval']
assert r['success'] and ci['lower'] is not None and ci['upper'] is None

# Multi-series generation returns independent, reproducible arrays in one call.
multi = run({'action':'generate_number','arguments':{'distribution':'uniform','count':10,'min':0,'max':1,'seed':23,'series_count':3}})
assert multi['success'] and multi['result']['series_count'] == 3
assert len(multi['result']['datasets']) == 3
assert all(len(item['values']) == 10 for item in multi['result']['datasets'])
assert len({tuple(item['values']) for item in multi['result']['datasets']}) == 3
multi2 = run({'action':'generate_number','arguments':{'distribution':'uniform','count':10,'min':0,'max':1,'seed':23,'series_count':3}})
assert [x['values'] for x in multi['result']['datasets']] == [x['values'] for x in multi2['result']['datasets']]
# Multi-series plotting creates one PNG with all three labelled series.
import tempfile
with tempfile.TemporaryDirectory() as outdir:
    chart = run({'action':'plot','output_dir':outdir,'arguments':{
        'chart_type':'line','title':'Three series',
        'series':[{'name':f'S{i+1}','values':d['values']} for i,d in enumerate(multi['result']['datasets'])]
    }})
    assert chart['success'] and chart['result']['series_count'] == 3
    assert len(chart['result']['point_counts']) == 3
    assert (Path(outdir) / chart['result']['file']).is_file()

# The non-web worker path must not fail because Path is shadowed by a local import.
web = run({'action':'generate_number','arguments':{'distribution':'normal','count':8,'mean':50,'std':10,'seed':101}})
assert web['success'] and len(web['result']['values']) == 8

# Reject generation inputs that previously produced empty randint ranges or non-finite data.
for invalid_args in (
    {'distribution':'integer','count':5,'min':1.2,'max':1.8},
    {'distribution':'uniform','count':5,'min':float('inf'),'max':2},
    {'distribution':'normal','count':5,'mean':float('nan'),'std':1},
    {'distribution':'normal','count':5,'mean':0,'std':float('inf')},
):
    invalid = run({'action':'generate_number','arguments':invalid_args})
    assert not invalid['success'], f'Invalid parameters should fail: {invalid_args}'


# DataFrame contains filters must not stringify null values as matching text.
df_filter = run({'action':'dataframe','arguments':{'operation':'filter','data':[{'name':'Alice'},{'name':None},{'name':'Bob'}],'column':'name','operator':'contains','value':'None'}})
assert df_filter['success'] and df_filter['result']['rows'] == 0, 'Nulls must not match contains filters after string coercion'
# Non-count groupby operations require a valid value column.
df_group = run({'action':'dataframe','arguments':{'operation':'groupby','data':[{'category':'A','amount':1}],'column':'category','aggregation':'sum','value_column':'missing'}})
assert not df_group['success'] and 'Value column not found' in df_group['error']


# Chi-square observed values are counts, not arbitrary fractional measurements.
chi_fractional = run({'action':'statistics','arguments':{'method':'chi_square','values':[2.5,3.5],'expected':[3,3]}})
assert not chi_fractional['success'] and 'integer counts' in chi_fractional['error']
# Degenerate ANOVA with no variation anywhere must fail clearly rather than return NaN.
anova_constant = run({'action':'statistics','arguments':{'method':'anova','groups':[[5,5],[5,5]]}})
assert not anova_constant['success'] and 'all observations are identical' in anova_constant['error']


# Degenerate t-tests should return explicit errors instead of NaN statistics/p-values.
one_sample_degenerate = run({'action':'statistics','arguments':{'method':'one_sample_t_test','values':[4,4,4],'population_mean':4}})
assert not one_sample_degenerate['success'] and 'undefined' in one_sample_degenerate['error']
paired_degenerate = run({'action':'statistics','arguments':{'method':'paired_t_test','values':[1,2,3],'y':[1,2,3]}})
assert not paired_degenerate['success'] and 'zero variance' in paired_degenerate['error']
two_sample_degenerate = run({'action':'statistics','arguments':{'method':'two_sample_t_test','values':[2,2,2],'y':[2,2,2]}})
assert not two_sample_degenerate['success'] and 'standard error is zero' in two_sample_degenerate['error']


# Verification must reject non-finite or negative tolerances and non-integer counts.
verify_bad_tol = run({'action':'verify','arguments':{'kind':'arithmetic','expression':'1+1','actual':2,'tolerance':-1}})
assert not verify_bad_tol['success'] and 'non-negative' in verify_bad_tol['error']
verify_inf_tol = run({'action':'verify','arguments':{'kind':'arithmetic','expression':'1+1','actual':2,'tolerance':float('inf')}})
assert not verify_inf_tol['success'] and 'finite' in verify_inf_tol['error']
verify_fraction_count = run({'action':'verify','arguments':{'kind':'record_count','records':[1,2],'reported_count':2.5}})
assert not verify_fraction_count['success'] and 'integer' in verify_fraction_count['error']
verify_ok = run({'action':'verify','arguments':{'kind':'record_count','records':[1,2,3],'reported_count':3}})
assert verify_ok['success'] and verify_ok['result']['verified']


# Generation dimensions and seeds must not silently truncate or coerce types.
for bad_gen in (
    {'distribution':'integer','count':2.5,'min':1,'max':5},
    {'distribution':'uniform','count':3,'series_count':1.5},
    {'distribution':'normal','count':3,'seed':True},
):
    bad_result = run({'action':'generate_number','arguments':bad_gen})
    assert not bad_result['success'], f'Invalid generation dimensions/seed should fail: {bad_gen}'


# Finite distribution parameters can still overflow during generation.
overflow_uniform = run({'action':'generate_number','arguments':{'distribution':'uniform','count':2,'min':-1e308,'max':1e308}})
assert not overflow_uniform['success'] and 'too large to represent safely' in overflow_uniform['error']
overflow_normal = run({'action':'generate_number','arguments':{'distribution':'normal','count':3,'mean':1.7e308,'std':1e308,'seed':1}})
assert not overflow_normal['success'] and 'non-finite value' in overflow_normal['error']


# Null values must not satisfy not-equal filters (SQL-like missing-value semantics).
df_ne = run({'action':'dataframe','arguments':{'operation':'filter','data':[{'name':'Alice'},{'name':None},{'name':'Bob'}],'column':'name','operator':'ne','value':'Alice'}})
assert df_ne['success'] and df_ne['result']['rows'] == 1 and df_ne['result']['records'][0]['name'] == 'Bob'

# Binary output is always 0/1 and uses the configured success probability.
binary = run({'action':'generate_number','arguments':{'distribution':'binary','count':100,'probability':1.0,'seed':8}})
assert binary['success'] and set(binary['result']['values']) == {1}
binary_zero = run({'action':'generate_number','arguments':{'distribution':'binary','count':20,'probability':0.0,'seed':8}})
assert binary_zero['success'] and set(binary_zero['result']['values']) == {0}

# Matrix shape is row/column aware and reproducible.
matrix = run({'action':'generate_number','arguments':{'distribution':'uniform','shape':'matrix','rows':4,'columns':3,'min':0,'max':1,'number_type':'float','seed':9}})
assert matrix['success'] and matrix['result']['count'] == 12 and len(matrix['result']['values']) == 4 and all(len(row)==3 for row in matrix['result']['values'])
assert all(isinstance(v,float) for row in matrix['result']['values'] for v in row)
matrix2 = run({'action':'generate_number','arguments':{'distribution':'uniform','shape':'matrix','rows':4,'columns':3,'min':0,'max':1,'number_type':'float','seed':9}})
assert matrix2['result']['values'] == matrix['result']['values']

# Integer output from continuous distributions is explicitly typed.
normal_int = run({'action':'generate_number','arguments':{'distribution':'normal','count':20,'mean':5,'std':2,'number_type':'integer','seed':10}})
assert normal_int['success'] and all(isinstance(v,int) for v in normal_int['result']['values'])

# Invalid binary probability and missing matrix dimensions are rejected.
bad_probability = run({'action':'generate_number','arguments':{'distribution':'binary','count':5,'probability':1.5}})
assert not bad_probability['success']
missing_matrix_dims = run({'action':'generate_number','arguments':{'distribution':'integer','shape':'matrix','seed':2}})
assert not missing_matrix_dims['success']

# Reject matrix dimensions beyond the bounded total.
matrix_bad = run({'action':'generate_number','arguments':{'distribution':'integer','shape':'matrix','rows':101,'columns':100,'seed':2}})
assert not matrix_bad['success']

print('Python worker tests passed.')

# Expanded random distributions, deterministic numeric sequences, sampling and transforms.
for dist, args, check in [
    ('binomial', {'trials':8,'probability':0.5}, lambda v: all(isinstance(x,int) and 0<=x<=8 for x in v)),
    ('poisson', {'lambda':4}, lambda v: all(isinstance(x,int) and x>=0 for x in v)),
    ('exponential', {'scale':2}, lambda v: all(isinstance(x,float) and x>=0 for x in v)),
    ('beta', {'alpha':2,'beta':5}, lambda v: all(0<=x<=1 for x in v)),
    ('gamma', {'alpha':2,'scale':3}, lambda v: all(x>=0 for x in v)),
    ('lognormal', {'mean':0,'std':0.5}, lambda v: all(x>0 for x in v)),
    ('triangular', {'min':0,'max':10,'mode':3}, lambda v: all(0<=x<=10 for x in v)),
]:
    result=run({'action':'generate_number','arguments':{'distribution':dist,'count':50,'seed':11,**args}})
    assert result['success'] and check(result['result']['values']), f'{dist} generation failed'

seq=run({'action':'generate_number','arguments':{'operation':'sequence','sequence_type':'arithmetic','start':2,'step':3,'count':5}})
assert seq['success'] and seq['result']['values']==[2,5,8,11,14]
fib=run({'action':'generate_number','arguments':{'operation':'sequence','sequence_type':'fibonacci','first':0,'second':1,'count':8}})
assert fib['success'] and fib['result']['values']==[0,1,1,2,3,5,8,13]
primes=run({'action':'generate_number','arguments':{'operation':'sequence','sequence_type':'prime','count':6}})
assert primes['success'] and primes['result']['values']==[2,3,5,7,11,13]
poly=run({'action':'generate_number','arguments':{'operation':'sequence','sequence_type':'polynomial','coefficients':[1,2,1],'start':0,'step':1,'count':4}})
assert poly['success'] and poly['result']['values']==[1,4,9,16]

sample=run({'action':'generate_number','arguments':{'operation':'sampling','population':[1,2,3,4,5],'count':3,'replace':False,'seed':3}})
assert sample['success'] and len(sample['result']['values'])==3 and len(set(sample['result']['values']))==3
weighted=run({'action':'generate_number','arguments':{'operation':'sampling','population':[10,20,30],'weights':[0,0,1],'count':5,'seed':4}})
assert weighted['success'] and set(weighted['result']['values'])=={30}
shuffle=run({'action':'generate_number','arguments':{'operation':'sampling','population':[1,2,3,4],'sampling_method':'shuffle','seed':4}})
assert shuffle['success'] and sorted(shuffle['result']['values'])==[1,2,3,4]

normalized=run({'action':'generate_number','arguments':{'operation':'transform','transform_type':'normalize','values':[2,4,6]}})
assert normalized['success'] and normalized['result']['values']==[0.0,0.5,1.0]
standardized=run({'action':'generate_number','arguments':{'operation':'transform','transform_type':'standardize','values':[2,4,6]}})
assert standardized['success'] and abs(sum(standardized['result']['values']))<1e-12
clipped=run({'action':'generate_number','arguments':{'operation':'transform','transform_type':'clip','values':[-2,1,9],'min':0,'max':5}})
assert clipped['success'] and clipped['result']['values']==[0.0,1.0,5.0]

rounded=run({'action':'generate_number','arguments':{'distribution':'beta','count':30,'alpha':2,'beta':5,'number_type':'integer','seed':12}})
assert rounded['success'] and all(isinstance(v,int) for v in rounded['result']['values'])

# Expanded calculator: numerical integration, bounded optimization, root finding and matrix ops.
numeric_integral=run({'action':'calculate','arguments':{'expression':'x^2','mode':'numeric_integrate','variable':'x','lower':0,'upper':1}})
assert numeric_integral['success'] and abs(numeric_integral['result']['result']['value']-1/3)<1e-7
minimum=run({'action':'calculate','arguments':{'expression':'(x-3)^2+2','mode':'minimize','lower':-5,'upper':8}})
assert minimum['success'] and abs(minimum['result']['result']['x']-3)<1e-4 and abs(minimum['result']['result']['value']-2)<1e-5
root=run({'action':'calculate','arguments':{'expression':'x^2-2','mode':'root','lower':1,'upper':2}})
assert root['success'] and abs(root['result']['result']['root']-2**0.5)<1e-8
matmul=run({'action':'calculate','arguments':{'expression':'0','mode':'matrix','matrix':[[1,2],[3,4]],'matrix_b':[[5,6],[7,8]],'matrix_operation':'multiply'}})
assert matmul['success'] and '19' in matmul['result']['result'] and '22' in matmul['result']['result']
matpower=run({'action':'calculate','arguments':{'expression':'0','mode':'matrix','matrix':[[1,2],[3,4]],'matrix_operation':'power','matrix_power':2}})
assert matpower['success'] and '7' in matpower['result']['result'] and '10' in matpower['result']['result']
