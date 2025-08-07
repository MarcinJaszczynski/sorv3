import sqlite3
src = sqlite3.connect('database (7).sqlite')
dst = sqlite3.connect('database.sqlite')

tables = [r[0] for r in src.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]

for t in tables:
    src_cols = src.execute(f'PRAGMA table_info({t})').fetchall()
    dst_cols = dst.execute(f'PRAGMA table_info({t})').fetchall()
    if src_cols != dst_cols:
        print(f'RÓŻNICE W TABELI: {t}\nŹródło: {src_cols}\nDocelowa: {dst_cols}\n')
