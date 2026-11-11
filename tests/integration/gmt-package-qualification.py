import importlib.util
import json
import os
import time
import pymysql

spec = importlib.util.spec_from_file_location('pkg', 'ops/gmt_index_package.py')
pkg = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pkg)
cfg = dict(host='127.0.0.1', port=3306, user='root',
           password=os.environ['MYSQL_TEST_PASSWORD'], database='gmt_test',
           autocommit=True, connect_timeout=5, read_timeout=45, write_timeout=5)
factory = lambda: pymysql.connect(**cfg)
c = factory()
report = {}
assert pkg.rows(c, 'SELECT VERSION()')[0][0].startswith('9.7.2')
original = pkg.inventory(c, 'gmt_test', 'posts')
assert not any(k in original for k in pkg.INDEXES)
report['pre'] = pkg.snapshot(c, 'gmt_test', 'posts')
report['add'] = pkg.change(factory, 'gmt_test', 'posts', 'add')
assert report['add']['verified_expected_inventory']
assert not report['add'].get('requires_manual_reconciliation')
report['post'] = pkg.snapshot(c, 'gmt_test', 'posts')
for f in pkg.FIELDS:
    assert report['pre']['lookups'][f]['scalar'] == report['post']['lookups'][f]['scalar']
    index = 'sa_status_date_gmt' if f == 'post_date_gmt' else 'sa_status_modified_gmt'
    assert index in str(report['post']['lookups'][f]['plan'])
try:
    pkg.change(factory, 'gmt_test', 'posts', 'add')
    raise AssertionError('duplicate add accepted')
except RuntimeError as e:
    assert 'already present' in str(e)
report['rollback'] = pkg.change(factory, 'gmt_test', 'posts', 'drop', expected_original=original)
assert report['rollback']['verified_expected_inventory']
assert pkg.inventory(c, 'gmt_test', 'posts') == original
# Existing long transaction forces metadata timeout and proves no fallback.
hold = factory()
hold.begin()
pkg.rows(hold, 'SELECT ID FROM posts LIMIT 1')
report['mdl_timeout'] = pkg.change(factory, 'gmt_test', 'posts', 'add', cap_seconds=10)
assert report['mdl_timeout']['error_code'] == 1205
assert report['mdl_timeout']['unchanged_inventory']
assert report['mdl_timeout']['elapsed_seconds'] < 4
# Same blocked DDL, cap shorter than lock timeout: real server session is killed.
sql = 'ALTER TABLE gmt_test.posts ADD INDEX sa_status_date_gmt(post_status,post_date_gmt,post_type), ADD INDEX sa_status_modified_gmt(post_status,post_modified_gmt,post_type), ALGORITHM=INPLACE, LOCK=NONE'
report['server_kill'] = pkg.bounded_sql(factory, sql, cap_seconds=.25, lock_seconds=20)
assert report['server_kill']['killed']
assert report['server_kill']['error_code'] == 1317
assert report['server_kill']['elapsed_seconds'] < 2
assert pkg.inventory(c, 'gmt_test', 'posts') == original
hold.rollback()
hold.close()
# Unsupported online request returns an error; no algorithm substitution.
report['unsupported'] = pkg.bounded_sql(factory, 'ALTER TABLE gmt_test.posts ADD FULLTEXT INDEX forbidden_fulltext(post_content), ALGORITHM=INPLACE, LOCK=NONE')
assert report['unsupported']['status'] == 'returned_error'
assert 'forbidden_fulltext' not in pkg.inventory(c, 'gmt_test', 'posts')
# Rollback rejects a changed definition before touching either candidate.
pkg.rows(c, 'ALTER TABLE posts ADD INDEX sa_status_date_gmt(post_type), ALGORITHM=INPLACE, LOCK=NONE')
try:
    pkg.change(factory, 'gmt_test', 'posts', 'drop', expected_original=original)
    raise AssertionError('mismatched drop accepted')
except RuntimeError as e:
    assert 'definition mismatch' in str(e)
pkg.rows(c, 'ALTER TABLE posts DROP INDEX sa_status_date_gmt, ALGORITHM=INPLACE, LOCK=NONE')
assert pkg.inventory(c, 'gmt_test', 'posts') == original
report['status'] = 'PASS fake-data package, exact rollback, parity, metadata timeout, independent server KILL QUERY, unsupported online request, idempotency guard, mismatch guard'
print(json.dumps(report, indent=2, default=str))
open('/tmp/gmt-package-report.json', 'w').write(json.dumps(report, indent=2, default=str))
c.close()
