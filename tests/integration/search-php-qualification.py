import subprocess,json
report={'cases':[],'scope':'Actual deployed-source PHP functions extracted and executed. WordPress, WooCommerce literal query, taxonomy, product visibility, intent and network boundaries are deterministic stubs. Not a full live WordPress integration test.'}
for n in [0,1,499,500,501,65000,121000]:
 outputs=[]
 for mode in ['original','candidate']:
  r=subprocess.run(['php','-d','memory_limit=768M','tests/integration/search-php-harness.php',mode,str(n)],capture_output=True,text=True,check=True)
  outputs.append(json.loads(r.stdout))
 a,b=outputs
 for k in ['packed_sha256','serialized_sha256','indexed_count','query_count','ttl','results']:
  assert a[k]==b[k],(n,k,a[k],b[k])
 assert a['ttl']==(7200 if a['indexed_count']else None)
 report['cases'].append({'fixture_count':n,'indexed_count':a['indexed_count'],'query_count':a['query_count'],'packed_sha256':a['packed_sha256'],'packed_bytes_identical':True,'decoded_serialized_bytes_identical':True,'ttl':a['ttl'],'suggestion_and_fuzzy_cases_identical':len(a['results'])})
assert report['cases'][-1]['query_count']==200
report['status']='PASS exact packed PHP index and suggestion/fuzzy outputs on static fixtures; 200-page ceiling preserved'
report['limits']=['Concurrency differences remain explicit, not exact equivalence across changes.','SKU selection is supplied by the DB stub; missing/duplicate SKU SQL behavior was separately qualified on MySQL.','Intent-plan branch is fixed null in this harness; unchanged intent code is not independently integrated here.','Fake literal queries/products/taxonomy and no images are stubs, not live UI or full WordPress coverage.','No production application, schedule, cache, scoring or schema changes.']
print(json.dumps(report,indent=2));open('/tmp/search-php-report.json','w').write(json.dumps(report,indent=2))
