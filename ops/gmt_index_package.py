"""Two-index package. No production entrypoint; callers supply a DB-API connection factory.
DDL is guarded by a separate control connection that KILL QUERYs the server session.
A lost control connection is UNKNOWN, never permission to retry or claim rollback.
"""
import json
import re
import threading
import time

INDEXES = {
    'sa_status_date_gmt': ('post_status', 'post_date_gmt', 'post_type'),
    'sa_status_modified_gmt': ('post_status', 'post_modified_gmt', 'post_type'),
}
FIELDS = ('post_date_gmt', 'post_modified_gmt')


def ident(value):
    if not re.fullmatch(r'[a-zA-Z0-9_]+', value):
        raise ValueError('unsafe identifier')
    return '`' + value + '`'


def rows(conn, sql, args=None):
    with conn.cursor() as cur:
        cur.execute(sql, args)
        return cur.fetchall()


def inventory(conn, schema, table):
    data = rows(conn, 'SHOW INDEX FROM ' + ident(schema) + '.' + ident(table))
    result = {}
    for row in data:
        result.setdefault(row[2], []).append((row[3], row[4], row[7]))
    return {name: tuple((col, prefix) for _, col, prefix in sorted(parts))
            for name, parts in result.items()}


def bounded_sql(factory, sql, cap_seconds=30, lock_seconds=2):
    """One attempt. Independent controller kills the exact server connection ID.
    Returns after reconciliation of the worker. Unknown termination is a hard blocker.
    Never retries a failed DDL. Used with LOCK=NONE only by change().
    """
    if not 0 < cap_seconds <= 60:
        raise ValueError('runtime cap must be between 0 and 60 seconds')
    worker, control = factory(), factory()
    connection_id = rows(worker, 'SELECT CONNECTION_ID()')[0][0]
    rows(worker, 'SET SESSION lock_wait_timeout=%s', (lock_seconds,))
    done = threading.Event()
    outcome = {'connection_id': connection_id, 'cap_seconds': cap_seconds,
               'sql': sql, 'killed': False}

    def execute():
        started = time.monotonic()
        try:
            rows(worker, sql)
            outcome['status'] = 'returned_success'
        except Exception as exc:
            outcome['status'] = 'returned_error'
            outcome['error'] = str(exc)
            outcome['error_code'] = getattr(exc, 'args', [None])[0]
        finally:
            outcome['elapsed_seconds'] = time.monotonic() - started
            done.set()

    thread = threading.Thread(target=execute, daemon=True)
    thread.start()
    try:
        if not done.wait(cap_seconds):
            try:
                # Independent connection sends KILL to the server, not a socket timeout.
                rows(control, 'KILL QUERY ' + str(int(connection_id)))
                outcome['killed'] = True
            except Exception as exc:
                outcome['termination_error'] = str(exc)
            if not done.wait(10):
                outcome['status'] = 'UNKNOWN_SERVER_SESSION'
                raise RuntimeError(json.dumps(outcome))
        return outcome
    finally:
        control.close()
        if done.is_set():
            worker.close()


def snapshot(conn, schema, table):
    target = ident(schema) + '.' + ident(table)
    existing = inventory(conn, schema, table)
    result = {'indexes': existing, 'lookups': {}}
    active = [k for k in INDEXES if k in existing]
    # Repeatable-read snapshot makes parity immune to concurrent timestamp changes.
    rows(conn, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
    rows(conn, 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY')
    try:
        for field in FIELDS:
            suffix = (" WHERE post_status='publish' AND post_type IN "
                      "('post','page','attachment','product') ORDER BY " + ident(field) + ' DESC LIMIT 1')
            query = 'SELECT ' + ident(field) + ' FROM ' + target + suffix
            start = time.monotonic()
            scalar = rows(conn, query)
            elapsed = time.monotonic() - start
            plan = rows(conn, 'EXPLAIN ' + query)
            ignored = (' IGNORE INDEX (' + ','.join(ident(k) for k in active) + ')') if active else ''
            baseline = rows(conn, 'SELECT ' + ident(field) + ' FROM ' + target + ignored + suffix)
            if scalar != baseline:
                raise RuntimeError('scalar parity failed: ' + field)
            result['lookups'][field] = {'scalar': scalar, 'seconds': elapsed,
                                        'plan': plan, 'same_snapshot_parity': True}
    finally:
        conn.rollback()
    return result


def change(factory, schema, table, action, cap_seconds=30, expected_original=None):
    """Caller must retain preflight quiet/headroom approval outside this module.
    Absent indexes required for add; exact definitions and original inventory for drop.
    No fallback, implicit retry, ANALYZE, data writes, or unrelated schema writes.
    """
    if action not in ('add', 'drop'):
        raise ValueError('action must be add or drop')
    conn = factory()
    try:
        before = inventory(conn, schema, table)
        if action == 'add':
            if any(k in before for k in INDEXES):
                raise RuntimeError('candidate already present; reconcile instead of retry')
            clauses = ['ADD INDEX ' + ident(k) + '(' + ','.join(ident(c) for c in v) + ')'
                       for k, v in INDEXES.items()]
        else:
            if expected_original is None:
                raise RuntimeError('rollback needs saved original inventory')
            expected_original = {k: tuple(tuple(part) for part in v)
                                 for k, v in expected_original.items()}
            for k, v in INDEXES.items():
                if before.get(k) != tuple((c, None) for c in v):
                    raise RuntimeError('rollback candidate absent or definition mismatch')
            if {k: v for k, v in before.items() if k not in INDEXES} != expected_original:
                raise RuntimeError('original inventory changed; do not drop')
            clauses = ['DROP INDEX ' + ident(k) for k in INDEXES]
    finally:
        conn.close()
    sql = 'ALTER TABLE ' + ident(schema) + '.' + ident(table) + ' ' + ', '.join(clauses) + ', ALGORITHM=INPLACE, LOCK=NONE'
    execution = bounded_sql(factory, sql, cap_seconds)
    # Even a timeout/error can race a completed DDL. Always read actual server state.
    conn = factory()
    try:
        after = inventory(conn, schema, table)
        original = before if action == 'add' else expected_original
        wanted = dict(original)
        if action == 'add':
            wanted.update({k: tuple((c, None) for c in v) for k, v in INDEXES.items()})
        execution['verified_expected_inventory'] = after == wanted
        execution['unchanged_inventory'] = after == before
        execution['before'] = before
        execution['after'] = after
        if after != wanted and after != before:
            raise RuntimeError('UNEXPECTED inventory; no automatic retry: ' + json.dumps(execution, default=str))
        if (execution['status'] != 'returned_success' or after != wanted
                or execution.get('killed') or execution.get('termination_error')):
            execution['requires_manual_reconciliation'] = True
        return execution
    finally:
        conn.close()
