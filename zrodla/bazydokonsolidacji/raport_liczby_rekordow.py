import sqlite3

src = sqlite3.connect('database (7).sqlite')
dst = sqlite3.connect('database.sqlite')

src_tables = [r[0] for r in src.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]
dst_tables = [r[0] for r in dst.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]

wspolne = set(src_tables) & set(dst_tables)
raport = []
for t in sorted(wspolne):
    try:
        src_count = src.execute(f'SELECT COUNT(*) FROM "{t}"').fetchone()[0]
        dst_count = dst.execute(f'SELECT COUNT(*) FROM "{t}"').fetchone()[0]
        raport.append(f'{t}: źródło={src_count}, docelowa={dst_count}')
    except Exception as e:
        raport.append(f'{t}: BŁĄD: {e}')
with open('raport_liczby_rekordow.txt', 'w', encoding='utf-8') as f:
    for line in raport:
        f.write(line + '\n')
print('Raport liczby rekordów zapisany do raport_liczby_rekordow.txt')
