import sqlite3
import sys
import os
from collections import defaultdict

def get_columns(conn, table):
    return [col[1] for col in conn.execute(f'PRAGMA table_info({table})')]

def get_schema(conn, table):
    return conn.execute(f'PRAGMA table_info({table})').fetchall()

def get_foreign_keys(conn, table):
    return conn.execute(f'PRAGMA foreign_key_list({table})').fetchall()

def get_tables(conn):
    return [r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]

def get_default_value(col):
    # (cid, name, type, notnull, dflt_value, pk)
    if col[4] is not None:
        return col[4].strip("'")
    if col[2].startswith('int') or col[2].startswith('tinyint'):
        return 0
    if col[2].startswith('varchar') or col[2].startswith('TEXT'):
        return ''
    if col[2].startswith('datetime'):
        return None
    return None

def quote_identifier(identifier):
    # SQLite: escape double quotes by doubling them
    return f'"{identifier.replace("\"", "\"\"")}"'

def migrate_table(src, dst, table):
    src_cols = get_columns(src, table)
    dst_schema = get_schema(dst, table)
    dst_cols = [col[1] for col in dst_schema]
    insert_cols = []
    for col in dst_cols:
        if col in src_cols:
            insert_cols.append(col)
        else:
            insert_cols.append(None)
    src_rows = src.execute(f'SELECT * FROM {quote_identifier(table)}').fetchall()
    dst.execute(f'DELETE FROM {quote_identifier(table)}')
    for row in src_rows:
        new_row = []
        for i, col in enumerate(dst_cols):
            if col in src_cols:
                new_row.append(row[src_cols.index(col)])
            else:
                # domyślna wartość
                default = get_default_value(dst_schema[i])
                new_row.append(default)
        placeholders = ','.join(['?'] * len(dst_cols))
        quoted_cols = ','.join([quote_identifier(c) for c in dst_cols])
        dst.execute(f'INSERT INTO {quote_identifier(table)} ({quoted_cols}) VALUES ({placeholders})', new_row)
    dst.commit()

def main():
    src = sqlite3.connect('database (7).sqlite')
    dst = sqlite3.connect('database.sqlite')
    src_tables = set(get_tables(src))
    dst_tables = set(get_tables(dst))
    wspolne = src_tables & dst_tables
    tylko_dst = dst_tables - src_tables
    raport = []
    for t in wspolne:
        try:
            migrate_table(src, dst, t)
            raport.append(f'OK: {t}')
        except Exception as e:
            raport.append(f'BŁĄD: {t}: {e}')
    for t in tylko_dst:
        raport.append(f'POMINIĘTO (tylko w docelowej): {t}')
    with open('raport_migracji.txt', 'w', encoding='utf-8') as f:
        for line in raport:
            f.write(line + '\n')
    print('Migracja zakończona. Szczegóły w raport_migracji.txt')

if __name__ == '__main__':
    main()
