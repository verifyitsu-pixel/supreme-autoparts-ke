"""Offline fake-data qualification only. No application writes or production defaults."""
import os,json,time,statistics,random
import pymysql
cfg=dict(host='127.0.0.1',port=3306,user='root',password=os.environ['MYSQL_TEST_PASSWORD'],database='gmt_test',autocommit=True)
c=pymysql.connect(**cfg);cur=c.cursor();report={}
cur.execute('SELECT VERSION()');assert cur.fetchone()[0].startswith('9.7.2')
for sql in [
 'CREATE TABLE q_posts(ID BIGINT UNSIGNED PRIMARY KEY,post_type VARCHAR(20) NOT NULL,post_status VARCHAR(20) NOT NULL,post_title TEXT,post_date DATETIME NOT NULL,KEY type_status_date(post_type,post_status,post_date,ID)) ENGINE=InnoDB',
 'CREATE TABLE q_meta(meta_id BIGINT UNSIGNED PRIMARY KEY,post_id BIGINT UNSIGNED NOT NULL,meta_key VARCHAR(255),meta_value LONGTEXT,KEY post_id(post_id),KEY meta_key(meta_key(191))) ENGINE=InnoDB',
 'CREATE TABLE q_lookup(product_id BIGINT UNSIGNED PRIMARY KEY,sku VARCHAR(100),min_price DECIMAL(12,2),stock_status VARCHAR(20)) ENGINE=InnoDB',
 'CREATE TABLE q_terms(object_id BIGINT UNSIGNED NOT NULL,term_taxonomy_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(object_id,term_taxonomy_id),KEY term_taxonomy_id(term_taxonomy_id)) ENGINE=InnoDB',
 'CREATE TABLE q_facets(product_id BIGINT UNSIGNED NOT NULL,facet VARCHAR(24) NOT NULL,value VARCHAR(120) NOT NULL,label VARCHAR(160) NOT NULL,PRIMARY KEY(facet,value,product_id),KEY product_id(product_id)) ENGINE=InnoDB']:
 cur.execute(sql)
for start in range(0,65000,1000):
 ids=range(start+1,start+1001)
 cur.executemany('INSERT INTO q_posts VALUES (%s,%s,%s,%s,%s)',[(i,'product' if i%13 else 'revision','publish' if i%10 else 'draft','Synthetic part '+str(i),'2026-11-01')for i in ids])
 cur.executemany('INSERT INTO q_meta VALUES (%s,%s,%s,%s)',[(i*10+j,i,'_sku' if j==1 else 'fake_'+str(j),'SKU-'+str(i)if j==1 else 'x'*40)for i in ids for j in range(1,6)])
 cur.executemany('INSERT INTO q_lookup VALUES (%s,%s,%s,%s)',[(i,'SKU-'+str(i),i%700,'instock'if i%4 else 'outofstock')for i in ids if i%17])
 cur.executemany('INSERT INTO q_terms VALUES (%s,%s)',[(i,99)for i in ids if i%11==0])
 cur.executemany('INSERT INTO q_facets VALUES (%s,%s,%s,%s)',[(i,f,v,l)for i in ids for f,v,l in [('brand','b'+str(i%31),'Brand '+str(i%31)),('make','m'+str(i%9),'Make '+str(i%9)),('cat',str(i%40),'Category '+str(i%40))]])
for t in ['q_posts','q_meta','q_lookup','q_terms','q_facets']:cur.execute('ANALYZE TABLE '+t)
def bench(sql,repeat=5):
 cur.execute('EXPLAIN '+sql);plan=cur.fetchall();ts=[];answer=None
 for _ in range(repeat):
  at=time.perf_counter();cur.execute(sql);answer=cur.fetchall();ts.append((time.perf_counter()-at)*1000)
 return {'median_ms':statistics.median(ts),'plan':plan,'rows':answer}
# Same scalar subquery is deliberately retained, so duplicate/missing SKU semantics do not change.
base="SELECT p.ID,p.post_title,(SELECT meta_value FROM q_meta WHERE post_id=p.ID AND meta_key='_sku' LIMIT 1) AS sku FROM q_posts p WHERE p.post_type='product' AND p.post_status='publish'"
report['sku_pages']={}
for offset in [0,10000,40000,53000]:
 original=base+' ORDER BY p.ID LIMIT '+str(offset)+',500'
 cur.execute(base+' ORDER BY p.ID LIMIT '+str(max(0,offset-1))+',1');prior=cur.fetchone();cursor=prior[0]if offset and prior else 0
 candidate=base+' AND p.ID>'+str(cursor)+' ORDER BY p.ID LIMIT 500'
 a,b=bench(original),bench(candidate);assert a['rows']==b['rows']
 report['sku_pages'][offset]={'cursor':cursor,'offset':{k:v for k,v in a.items()if k!='rows'},'keyset':{k:v for k,v in b.items()if k!='rows'},'row_count':len(a['rows']),'parity':True}
# Missing and duplicate SKU rows: both strategies retain the exact scalar-subquery behavior.
cur.execute("DELETE FROM q_meta WHERE post_id=1 AND meta_key='_sku'")
cur.execute("INSERT INTO q_meta VALUES(9999999,2,'_sku','SECOND-SKU')")
cur.execute(base+' ORDER BY p.ID LIMIT 500');a=cur.fetchall();cur.execute(base+' AND p.ID>0 ORDER BY p.ID LIMIT 500');assert a==cur.fetchall()
report['sku_edge_parity']='PASS missing/duplicate SKU, draft/revision exclusion; same scalar subquery retained'
# Facet ordering ties are nondeterministic in original SQL. Compare value->(label,count), not tie order.
report['facets']={}
for name,extra,facet in [('unfiltered','','brand'),('stock'," AND l.stock_status IN ('instock','onbackorder')",'brand'),('price'," AND l.min_price>=199",'make'),('other_facet'," AND p.ID IN (SELECT product_id FROM q_facets WHERE facet='cat' AND value IN ('1','2','3'))",'brand'),('empty'," AND p.ID<0",'make')]:
 where="p.post_type='product' AND p.post_status='publish' AND p.ID NOT IN (SELECT object_id FROM q_terms WHERE term_taxonomy_id=99)"+extra
 original="SELECT i.value,MAX(i.label) label,COUNT(*) c FROM q_facets i INNER JOIN (SELECT p.ID FROM q_posts p LEFT JOIN q_lookup l ON l.product_id=p.ID WHERE "+where+") b ON b.ID=i.product_id WHERE i.facet='"+facet+"' GROUP BY i.value ORDER BY c DESC LIMIT 300"
 candidate="SELECT i.value,MAX(i.label) label,COUNT(*) c FROM q_facets i WHERE i.facet='"+facet+"' AND EXISTS (SELECT 1 FROM q_posts p LEFT JOIN q_lookup l ON l.product_id=p.ID WHERE p.ID=i.product_id AND "+where+") GROUP BY i.value ORDER BY c DESC LIMIT 300"
 a,b=bench(original),bench(candidate);assert sorted(a['rows'])==sorted(b['rows'])
 report['facets'][name]={'original':{k:v for k,v in a.items()if k!='rows'},'exists':{k:v for k,v in b.items()if k!='rows'},'parity':True,'groups':len(a['rows'])}
# Additional covering index is a research candidate, NOT within approved production two-index scope.
cur.execute('ALTER TABLE q_facets ADD INDEX fake_cover(facet,value,product_id,label),ALGORITHM=INPLACE,LOCK=NONE')
for name,entry in report['facets'].items():
 # The last unfiltered literal is reconstructed; other filter variants remain report-only above.
 if name=='unfiltered':
  sql="SELECT i.value,MAX(i.label) label,COUNT(*) c FROM q_facets i INNER JOIN (SELECT p.ID FROM q_posts p LEFT JOIN q_lookup l ON l.product_id=p.ID WHERE p.post_type='product' AND p.post_status='publish' AND p.ID NOT IN (SELECT object_id FROM q_terms WHERE term_taxonomy_id=99)) b ON b.ID=i.product_id WHERE i.facet='brand' GROUP BY i.value ORDER BY c DESC LIMIT 300"
  r=bench(sql);entry['covering']={k:v for k,v in r.items()if k!='rows'}
report['limits']=['Source-shape attribution only; current live file identity/call source not verified.','Fake uniform data and warm reads are not live cardinality, cache hit rate or load predictions.','Keyset changes traversal under concurrent data edits; static parity does not prove concurrent rebuild semantics.','LIMIT/count ties retain original nondeterminism; no claim of stable selected tie subset.','No cache/cron/search/scoring application edits; additional covering index is NOT approved for production.']
report['status']='PASS fake-data static semantic parity; inspect measured timings before choosing a candidate'
print(json.dumps(report,indent=2,default=str));open('/tmp/residual-query-report.json','w').write(json.dumps(report,indent=2,default=str))
c.close()
