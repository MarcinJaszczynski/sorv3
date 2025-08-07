import sqlite3

baza = sqlite3.connect('database.sqlite')

# Lista relacji do naprawy (tabela, kolumna, tabela_docelowa, kolumna_docelowa)
RELACJE = [
    ('event_template_event_template_program_point', 'event_template_id', 'event_templates', 'id'),
    ('event_template_program_point_tag', 'event_template_program_point_id', 'event_template_program_points', 'id'),
    ('event_template_price_per_person', 'currency_id', 'currencies', 'id'),
    ('event_template_day_insurance', 'event_template_id', 'event_templates', 'id'),
    ('event_template_starting_place_availability', 'event_template_id', 'event_templates', 'id'),
    ('event_template_starting_place_availability', 'start_place_id', 'places', 'id'),
    ('event_template_starting_place_availability', 'end_place_id', 'places', 'id'),
]

raport = []
for (table, col, ref_table, ref_col) in RELACJE:
    try:
        # Usuń rekordy z nieistniejącymi powiązaniami
        sql = f'DELETE FROM "{table}" WHERE {col} IS NOT NULL AND {col} NOT IN (SELECT {ref_col} FROM "{ref_table}")'
        cur = baza.execute(sql)
        baza.commit()
        raport.append(f'{table}.{col} -> {ref_table}.{ref_col}: usunięto {cur.rowcount} niespójnych rekordów')
    except Exception as e:
        raport.append(f'BŁĄD: {table}.{col} -> {ref_table}.{ref_col}: {e}')
with open('raport_naprawa_integralnosci.txt', 'w', encoding='utf-8') as f:
    for line in raport:
        f.write(line + '\n')
print('Naprawa integralności zakończona. Szczegóły w raport_naprawa_integralnosci.txt')
