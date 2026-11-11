"""Fake-only traversal/concurrency qualification; no application patch."""
import os,json,hashlib
import pymysql
cfg=dict(host='127.0.0.1',port=3306,user='root',password=os.environ['MYSQL_TEST_PASSWORD'],database='gmt_test',autocommit=True)
c=pymysql.connect(**cfg);other=pymysql.connect(**cfg);report={}
def run(conn,sql):
 with conn.cursor()as q:q.execute(sql);return q.fetchall()
run(c,'CREATE TABLE traversal(ID BIGINT PRIMARY KEY, published BOOL NOT NULL, title VARCHAR(80)) ENGINE=InnoDB')
def seed():
 run(c,'DELETE FROM traversal')
 with c.cursor()as q:q.executemany('INSERT INTO traversal VALUES(%s,1,%s)',[(i,'part '+str(i))for i in range(1,1201)])
def page(conn,offset=None,cursor=0,highwater=None):
 return run(conn,'SELECT ID,title FROM traversal WHERE published=1'+(' AND ID>'+str(cursor)if offset is None else '')+(' AND ID<='+str(highwater)if highwater else '')+' ORDER BY ID LIMIT '+(str(offset)+','if offset is not None else '')+'500')
for name,mutation in [('delete_seen','DELETE FROM traversal WHERE ID=1'),('unpublish_seen','UPDATE traversal SET published=0 WHERE ID=1'),('publish_behind_cursor','UPDATE traversal SET published=1 WHERE ID=1'),('delete_unseen','DELETE FROM traversal WHERE ID=600'),('edit_unseen','UPDATE traversal SET title=\'edited\' WHERE ID=600'),('insert_new_high','INSERT INTO traversal VALUES(1300,1,\'new\')')]:
 seed()
 if name=='publish_behind_cursor':run(c,'UPDATE traversal SET published=0 WHERE ID=1')
 first=page(c,cursor=0);cursor=first[-1][0];run(other,mutation)
 a=page(c,offset=500);b=page(c,cursor=cursor)
 if name in ('delete_seen','unpublish_seen'):assert a[0][0]==502 and b[0][0]==501
 if name=='publish_behind_cursor':assert a[0][0]==501 and b[0][0]==502
 if name in ('delete_unseen','edit_unseen','insert_new_high'):assert a==b
 report[name]={'offset_first_id':a[0][0],'keyset_first_id':b[0][0],'same_page':a==b,'cursor':cursor}
# A consistent snapshot restores old/new row parity during writes, but pins a transaction.
seed();run(c,'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');run(c,'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')
first=page(c,cursor=0);run(other,'DELETE FROM traversal WHERE ID=1');run(other,"UPDATE traversal SET title='edited' WHERE ID=600");run(other,"INSERT INTO traversal VALUES(1300,1,'new')")
a=page(c,offset=500);b=page(c,cursor=first[-1][0]);assert a==b and b[0][0]==501
c.rollback();report['snapshot_parity']=True
# High-water limits growth, but deliberately excludes later high IDs. Not an implicit change.
seed();ceiling=run(c,'SELECT MAX(ID) FROM traversal WHERE published=1')[0][0];run(other,"INSERT INTO traversal VALUES(1300,1,'new')")
assert 1300 in [r[0]for r in page(c,cursor=1000)]
assert 1300 not in [r[0]for r in page(c,cursor=1000,highwater=ceiling)]
report['highwater_semantics']='later high-ID insert excluded, requires explicit design choice'
# Static coverage including current 200-page ceiling and rows skipped by token processing.
for count in [0,1,499,500,501,65000,100000,100001]:
 ids=list(range(1,count+1));old=[];new=[];cursor=0
 for n in range(200):
  batch=ids[n*500:(n+1)*500];old.extend(batch)
  if len(batch)<500:break
 for n in range(200):
  batch=[i for i in ids if i>cursor][:500];new.extend(batch)
  if batch:cursor=batch[-1]
  if len(batch)<500:break
 assert old==new==ids[:100000]
 # Cursor is last fetched ID, not last token-bearing ID, so empty-token rows cannot stall.
 packed_old={i:'same-transform'for i in old if i%7};packed_new={i:'same-transform'for i in new if i%7};assert packed_old==packed_new
 report.setdefault('static_coverage',{})[count]={'fetched':len(new),'indexed_after_synthetic_token_skip':len(packed_new),'identical_id_order':True}
report['status']='PASS characterized traversal differences, consistent-snapshot parity, explicit highwater difference, 200-page ceiling and cursor progression'
report['decision']='No silent production patch. Keyset without a long snapshot is weakly consistent, not exact concurrency equivalence. Long snapshot restores parity but adds undo/transaction cost; highwater introduces an intentional cutoff. Preserve hourly schedule, 500-row page, 200-page cap, scalar SKU selection, token/scoring logic and cache publication unless separately approved.'
print(json.dumps(report,indent=2));open('/tmp/search-traversal-report.json','w').write(json.dumps(report,indent=2));c.close();other.close()
