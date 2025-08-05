import sqlite3
import pandas as pd
import os


server_db = 'c:/Users/user/Documents/Herd/sorbaza/database/database_server.sqlite'
local_db = 'c:/Users/user/Documents/Herd/sorbaza/database/database_local.sqlite'
merged_db = 'c:/Users/user/Documents/Herd/sorbaza/database/database_merged.sqlite'

import shutil
shutil.copy(local_db, merged_db)  # Tworzymy nowy plik na bazie lokalnej

conn_server = sqlite3.connect(server_db)
conn_merged = sqlite3.connect(merged_db)

def get_tables(conn):
    return set(pd.read_sql("SELECT name FROM sqlite_master WHERE type='table';", conn)['name'])

tables_server = get_tables(conn_server)
tables_local = get_tables(conn_merged)
common_tables = tables_server & tables_local
common_tables.discard('sqlite_sequence')

for table in common_tables:
    print(f"Scalam: {table}")
    df_server = pd.read_sql(f"SELECT * FROM {table}", conn_server)
    df_local = pd.read_sql(f"SELECT * FROM {table}", conn_merged)
    print(f"Przed scaleniem: local={len(df_local)}, server={len(df_server)}")
    # Scal i usuń duplikaty po kluczu 'id' jeśli istnieje, inaczej po wszystkich kolumnach
    if 'id' in df_server.columns and 'id' in df_local.columns:
        df_merged = pd.concat([df_local, df_server]).drop_duplicates(subset='id', keep='first')
    else:
        df_merged = pd.concat([df_local, df_server]).drop_duplicates()
    print(f"Po scaleniu: {len(df_merged)}")
    # Nadpisz tabelę w nowej bazie
    df_merged.to_sql(table, conn_merged, if_exists='replace', index=False)

conn_server.close()
conn_merged.close()
print("Scalanie zakończone! Wynik zapisany w database_merged.sqlite")
print("Bieżący katalog:", os.getcwd())
print("Czy można pisać w folderze?", os.access('.', os.W_OK))
print("Plik merged_db istnieje?", os.path.exists(merged_db))