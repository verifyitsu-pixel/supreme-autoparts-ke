import os,json,time,statistics,threading
import pymysql
cfg=dict(host='127.0.0.1',port=3306,user='root',password=os.environ['MYSQL_TEST_PASSWORD'],database='gmt_test',autocommit=True)
c=pymysql.connect(**cfg);cur=c.cursor();report={}
cur.execute('CREATE TABLE posts (ID BIGINT UNSIGNED PRIMARY KEY, post_status VARCHAR(20) NOT NULL, post_type VARCHAR(20) NOT NULL, post_date DATETIME NOT NULL, post_date_gmt DATETIME NOT NULL,post_modified_gmt DATETIME NOT NULL,post_content LONGTEXT, KEY type_status_date(post_type,post_status,post_date,ID)) ENGINE=InnoDB')
rows=[]
for i in range(65000):
 status=['publish']*8+['draft','private'];types=['product']*8+['post','page','attachment','revision','shop_order'];d='2026-11-%02d %02d:%02d:%02d'%(1+i%8,(i//8)%24,(i//192)%60,i%60);rows.append((i+1,status[i%10],types[i%13],d,d,d,'synthetic catalog description '*80))
for n in range(0,len(rows),1000):cur.executemany('INSERT INTO posts VALUES (%s,%s,%s,%s,%s,%s,%s)',rows[n:n+1000])
fields=['post_date_gmt','post_modified_gmt'];queries={f:"SELECT "+f+" FROM posts WHERE post_status='publish' AND post_type IN ('post','page','attachment','product') ORDER BY "+f+" DESC LIMIT 1"for f in fields}
def run(sql):
 t=time.perf_counter();cur.execute(sql);r=cur.fetchall();return time.perf_counter()-t,r
def bench():
 z={}
 for f,q in queries.items():
  plan=run('EXPLAIN '+q)[1];ts=[]
  for _ in range(15):t,r=run(q);ts.append(t)
  z[f]=dict(median_ms=statistics.median(ts)*1000,result=r,plan=plan)
 return z
cur.execute('ANALYZE TABLE posts');cur.execute("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='gmt_test' AND TABLE_NAME='posts'");report['baseline_index_bytes']=cur.fetchone()[0]
report['baseline']=bench();t=time.perf_counter();cur.execute('ALTER TABLE posts ADD INDEX sa_status_date_gmt(post_status,post_date_gmt,post_type), ADD INDEX sa_status_modified_gmt(post_status,post_modified_gmt,post_type), ALGORITHM=INPLACE, LOCK=NONE');report['ddl_seconds']=time.perf_counter()-t;cur.execute('ANALYZE TABLE posts');report['indexed']=bench();print(json.dumps(report,default=str),flush=True)
for f in fields:
 assert report['baseline'][f]['result']==report['indexed'][f]['result'];assert report['indexed'][f]['median_ms']<report['baseline'][f]['median_ms'];assert report['indexed'][f]['plan'][0][6].startswith('sa_status_')
# Scalar-result parity for ties, unpublished/newer excluded type, insert/update/delete.
for sql in ["UPDATE posts SET post_date_gmt='2026-12-31',post_modified_gmt='2026-12-31' WHERE ID=2", "UPDATE posts SET post_status='draft' WHERE ID=2", "UPDATE posts SET post_status='publish',post_type='revision' WHERE ID=2", "DELETE FROM posts WHERE ID=2"]:
 cur.execute(sql)
 for f,q in queries.items():
  cur.execute(q);a=cur.fetchall();cur.execute(q.replace('FROM posts','FROM posts IGNORE INDEX(sa_status_date_gmt,sa_status_modified_gmt)'));assert a==cur.fetchall()
report['parity']='PASS scalar lookup, ties, draft, unsupported type, updates and delete'
# Newest excluded types force a backwards index scan past those records.
cur.executemany('INSERT INTO posts VALUES (%s,%s,%s,%s,%s,%s,%s)',[(80000+i,'publish','revision','2027-01-01','2027-01-01','2027-01-01','synthetic excluded')for i in range(5000)])
report['excluded5000_newest']=bench()
for f,q in queries.items():
 cur.execute(q);a=cur.fetchall();cur.execute(q.replace('FROM posts','FROM posts IGNORE INDEX(sa_status_date_gmt,sa_status_modified_gmt)'));assert a==cur.fetchall()
cur.execute('DELETE FROM posts WHERE ID>=80000')
# Write costs in same synthetic engine, alternate order to reduce warmup bias.
cur.execute('CREATE TABLE basewrite LIKE posts');cur.execute('ALTER TABLE basewrite DROP INDEX sa_status_date_gmt,DROP INDEX sa_status_modified_gmt');cur.execute('CREATE TABLE indexedwrite LIKE posts')
write={x:[]for x in ['basewrite','indexedwrite']}
for repeat in range(5):
 for table in (['basewrite','indexedwrite']if repeat%2==0 else ['indexedwrite','basewrite']):
  t=time.perf_counter();cur.executemany('INSERT INTO '+table+' VALUES (%s,%s,%s,%s,%s,%s,%s)',[(100000+repeat*200+i,'publish','product','2026-11-09','2026-11-09','2026-11-09','x'*2000)for i in range(200)]);write[table].append(time.perf_counter()-t)
report['write_batch200_median_ms']={k:statistics.median(v)*1000 for k,v in write.items()}
cur.execute("ANALYZE TABLE posts");cur.execute("SELECT DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='gmt_test' AND TABLE_NAME='posts'");report['table_bytes_with_indexes']=cur.fetchone();report['added_index_bytes']=report['table_bytes_with_indexes'][1]-report['baseline_index_bytes']
# LOCK=NONE still needs metadata lock: prove short failure instead of unbounded wait.
hold=pymysql.connect(**dict(cfg,autocommit=False));hc=hold.cursor();hc.execute('SELECT ID FROM posts LIMIT 1');cur.execute('SET SESSION lock_wait_timeout=2');t=time.perf_counter()
try:cur.execute('ALTER TABLE posts ADD INDEX test_mdl(post_type),ALGORITHM=INPLACE,LOCK=NONE');raise AssertionError('metadata lock unexpectedly bypassed')
except pymysql.err.OperationalError as e:assert e.args[0]==1205;report['metadata_lock_timeout_seconds']=time.perf_counter()-t
finally:hold.rollback();hold.close()
# Rollback exact added indexes only. Original index remains.
t=time.perf_counter();cur.execute('ALTER TABLE posts DROP INDEX sa_status_date_gmt,DROP INDEX sa_status_modified_gmt,ALGORITHM=INPLACE,LOCK=NONE');report['rollback_seconds']=time.perf_counter()-t;cur.execute('SHOW INDEX FROM posts');assert {r[2]for r in cur.fetchall()}=={'PRIMARY','type_status_date'};report['rollback']='PASS exact indexes removed, original retained';print(json.dumps(report,indent=2,default=str));open('/tmp/gmt-index-report.json','w').write(json.dumps(report,indent=2,default=str))
