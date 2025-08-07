import sqlite3

baza = sqlite3.connect('database.sqlite')

# Lista przykładowych relacji do sprawdzenia (tabela, kolumna, tabela_docelowa, kolumna_docelowa)
RELACJE = [
    ('event_templates', 'markup_id', 'markups', 'id'),
    ('event_templates', 'bus_id', 'buses', 'id'),
    ('event_template_event_template_program_point', 'event_template_id', 'event_templates', 'id'),
    ('event_template_event_template_program_point', 'event_template_program_point_id', 'event_template_program_points', 'id'),
    ('event_template_program_points', 'currency_id', 'currencies', 'id'),
    ('event_template_program_point_tag', 'event_template_program_point_id', 'event_template_program_points', 'id'),
    ('event_template_program_point_tag', 'tag_id', 'tags', 'id'),
    ('event_template_tag', 'event_template_id', 'event_templates', 'id'),
    ('event_template_tag', 'tag_id', 'tags', 'id'),
    ('event_template_hotel_days', 'event_template_id', 'event_templates', 'id'),
    ('event_template_price_per_person', 'event_template_qty_id', 'event_template_qties', 'id'),
    ('event_template_price_per_person', 'currency_id', 'currencies', 'id'),
    ('event_template_day_insurance', 'event_template_id', 'event_templates', 'id'),
    ('event_template_day_insurance', 'insurance_id', 'insurances', 'id'),
    ('event_template_starting_place_availability', 'event_template_id', 'event_templates', 'id'),
    ('event_template_starting_place_availability', 'start_place_id', 'places', 'id'),
    ('event_template_starting_place_availability', 'end_place_id', 'places', 'id'),
]

raport = []
for (table, col, ref_table, ref_col) in RELACJE:
    try:
        sql = f'SELECT COUNT(*) FROM "{table}" WHERE {col} IS NOT NULL AND {col} NOT IN (SELECT {ref_col} FROM "{ref_table}")'
        count = baza.execute(sql).fetchone()[0]
        if count == 0:
            raport.append(f'OK: {table}.{col} -> {ref_table}.{ref_col}')
        else:
            raport.append(f'BŁĄD: {table}.{col} -> {ref_table}.{ref_col}: {count} niespójnych rekordów')
    except Exception as e:
        raport.append(f'BŁĄD ZAPYTANIA: {table}.{col} -> {ref_table}.{ref_col}: {e}')
with open('raport_integralnosci.txt', 'w', encoding='utf-8') as f:
    for line in raport:
        f.write(line + '\n')
print('Raport integralności zapisany do raport_integralnosci.txt')
