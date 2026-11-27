import json, subprocess, sys
from pathlib import Path
WORKER = Path(__file__).resolve().parents[1] / 'tools' / 'python_worker.py'
def run(payload):
    p = subprocess.run([sys.executable, str(WORKER)], input=json.dumps(payload), text=True, capture_output=True, timeout=20)
    assert p.returncode == 0, p.stderr
    return json.loads(p.stdout)
r = run({'action':'generate_data','arguments':{'distribution':'integer','count':12,'min':1,'max':100,'seed':7}})
assert r['success'] and len(r['result']['values']) == 12 and all(isinstance(v, int) for v in r['result']['values'])
r2 = run({'action':'generate_data','arguments':{'distribution':'integer','count':12,'min':1,'max':100,'seed':7}})
assert r2['result']['values'] == r['result']['values'], 'seed must make generated data reproducible'
r = run({'action':'calculate','arguments':{'expression':'sqrt(81)+2'}})
assert r['success'] and r['result']['result'] == 11
r = run({'action':'statistics','arguments':{'method':'one_sample_t_test','values':[1,2,3,4,5],'population_mean':0,'alternative':'greater'}})
ci = r['result']['mean_difference_confidence_interval']
assert r['success'] and ci['lower'] is not None and ci['upper'] is None

# Multi-series generation returns independent, reproducible arrays in one call.
multi = run({'action':'generate_data','arguments':{'distribution':'uniform','count':10,'min':0,'max':1,'seed':23,'series_count':3}})
assert multi['success'] and multi['result']['series_count'] == 3
assert len(multi['result']['datasets']) == 3
assert all(len(item['values']) == 10 for item in multi['result']['datasets'])
assert len({tuple(item['values']) for item in multi['result']['datasets']}) == 3
multi2 = run({'action':'generate_data','arguments':{'distribution':'uniform','count':10,'min':0,'max':1,'seed':23,'series_count':3}})
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
web = run({'action':'generate_data','arguments':{'distribution':'normal','count':8,'mean':50,'std':10,'seed':101}})
assert web['success'] and len(web['result']['values']) == 8

# Reject generation inputs that previously produced empty randint ranges or non-finite data.
for invalid_args in (
    {'distribution':'integer','count':5,'min':1.2,'max':1.8},
    {'distribution':'uniform','count':5,'min':float('inf'),'max':2},
    {'distribution':'normal','count':5,'mean':float('nan'),'std':1},
    {'distribution':'normal','count':5,'mean':0,'std':float('inf')},
):
    invalid = run({'action':'generate_data','arguments':invalid_args})
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

print('Python worker tests passed.')
