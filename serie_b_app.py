import sqlite3
import pandas as pd
import streamlit as st
import requests
from bs4 import BeautifulSoup
from datetime import date, datetime
import re
import unicodedata
import pytz
from st_aggrid import AgGrid, GridOptionsBuilder

from pydrive.auth import GoogleAuth
from pydrive.drive import GoogleDrive
import tempfile
import os

# === CONFIGURAZIONE GOOGLE DRIVE ===

@st.cache_resource
def get_drive_connection():
    """Autenticazione a Google Drive"""
    gauth = GoogleAuth()
    # ⚠️ Usa il nome del tuo file JSON delle credenziali OAuth
    gauth.LoadClientConfigFile("client_secret_58332901035-i87ri770nkehokev4osnn3i0d4tv6g78.apps.googleusercontent.com.json")
    gauth.LoadCredentialsFile("mycreds.json")

    if gauth.credentials is None:
        gauth.LocalWebserverAuth()
    elif gauth.access_token_expired:
        gauth.Refresh()
    else:
        gauth.Authorize()

    gauth.SaveCredentialsFile("mycreds.json")
    return GoogleDrive(gauth)

@st.cache_resource
def get_db_path_from_drive():
    """Scarica il DB SQLite da Google Drive in un file temporaneo e restituisce il percorso locale."""
    drive = get_drive_connection()
    file_id = "14QGi9hskRvzcCuLjbxh5CBk2zsNhYKme"  # ⚠️ ID che hai fornito

    temp_dir = tempfile.mkdtemp()
    temp_path = os.path.join(temp_dir, "serie_b.sqlite")

    file = drive.CreateFile({'id': file_id})
    file.GetContentFile(temp_path)

    st.info(f"Database SQLite scaricato da Drive in {temp_path}")
    return temp_path

def upload_db_to_drive(local_path):
    """Carica su Google Drive il database locale modificato."""
    drive = get_drive_connection()
    file_id = "14QGi9hskRvzcCuLjbxh5CBk2zsNhYKme"  # stessa ID del file su Drive
    file = drive.CreateFile({'id': file_id})
    file.SetContentFile(local_path)
    file.Upload()
    st.success("Database aggiornato su Google Drive ✅")

# Questo sarà il path usato per tutto il codice
DB_PATH = get_db_path_from_drive()

# === IL TUO CODICE PRECEDENTE, ADATTATO A USARE DB_PATH ===

def highlight_vincita(row):
    color = ''
    if 'vincita' in row and row['vincita'] not in ("", "0.00"):
        color = 'background-color: darkgreen; color: white'
    elif 'id_draw' in row and pd.notnull(row['id_draw']) and str(row['id_draw']) not in ("", "0.00"):
        color = 'background-color: darkgreen; color: white'
    return [color] * len(row)
    
def get_db_connection():
    try:
        conn = sqlite3.connect(DB_PATH)
        conn.row_factory = sqlite3.Row
        return conn
    except sqlite3.Error as e:
        st.error(f"Errore connessione DB: {e}")
        return None
    
def normalize_str(s):
    if s is None:
        return ""
    s = s.strip().lower()
    s = ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')
    return s

def parse_data_it(data_str, anno=2025):
    data_str = re.sub(r"^\w+,\s*", "", data_str.strip())
    mesi = {
        'gennaio':1, 'febbraio':2, 'marzo':3, 'aprile':4,
        'maggio':5, 'giugno':6, 'luglio':7, 'agosto':8,
        'settembre':9, 'ottobre':10, 'novembre':11, 'dicembre':12
    }
    parti = data_str.split()
    if len(parti) == 2:
        try:
            giorno = int(parti[0])
            mese = mesi.get(parti[1].lower(), 1)
            return date(anno, mese, giorno)
        except:
            return date.today()
    else:
        return date.today()

@st.cache_data(ttl=3600, show_spinner=False)
def estrai_dati_giornata(giornata):
    url = f"https://www.legab.it/seriebkt/calendario/2025-2026/stagione-regolare/{giornata}"
    headers = {"User-Agent": "Mozilla/5.0"}
    try:
        r = requests.get(url, headers=headers, timeout=15)
        r.raise_for_status()
    except requests.RequestException as e:
        st.error(f"Errore rete durante scraping giornata {giornata}: {e}")
        return []
    soup = BeautifulSoup(r.text, 'html.parser')
    partite_html = soup.select("div.versus")
    partite = []

    for partita in partite_html:
        clubs = partita.select("div.club img")
        squadra_casa_raw = clubs[0]['title'] if len(clubs) > 0 else ""
        squadra_trasferta_raw = clubs[1]['title'] if len(clubs) > 1 else ""
        squadra_casa = normalize_str(squadra_casa_raw)
        squadra_trasferta = normalize_str(squadra_trasferta_raw)

        gol_tags = partita.select("span.gol")
        gol_casa = gol_tags[0].text.strip() if len(gol_tags) > 0 else ""
        gol_trasferta = gol_tags[1].text.strip() if len(gol_tags) > 1 else ""

        ora_tag = partita.find_previous("span", class_="giornata-title-hour")
        ora = ora_tag.text.strip().replace("Ore", "").strip() if ora_tag else "15:00"

        data_tag = partita.find_previous("span", class_="giornata-title-day")
        data = data_tag.text.strip() if data_tag else date.today().strftime("%d-%m-%Y")

        data_parsed = parse_data_it(data)
        data_ora_str = f"{data_parsed.strftime('%d/%m/%Y')} {ora}"

        gol_casa_val = int(gol_casa) if gol_casa.isdigit() else None
        gol_trasferta_val = int(gol_trasferta) if gol_trasferta.isdigit() else None

        partite.append({
            "giornata": giornata,
            "squadra_casa": squadra_casa,
            "squadra_trasferta": squadra_trasferta,
            "gol_casa": gol_casa_val,
            "gol_trasferta": gol_trasferta_val,
            "data_ora": data_ora_str
        })

    return partite

def aggiorna_db_partite(conn, partite):
    cursor = conn.cursor()
    cursor.execute("SELECT id, nome_squadra FROM squadre_2025_26")
    squadre_map = {normalize_str(nome): id_s for id_s, nome in cursor.fetchall()}
    aggiornate = 0
    for p in partite:
        casa_id = squadre_map.get(normalize_str(p['squadra_casa']))
        trasferta_id = squadre_map.get(normalize_str(p['squadra_trasferta']))
        if casa_id is None or trasferta_id is None:
            st.warning(f"Squadra non trovata per giornata {p['giornata']}: {p['squadra_casa']} o {p['squadra_trasferta']}")
            continue

        cursor.execute("""
            SELECT id FROM partite_2025_26 
            WHERE giornata_id = ? AND casa_id = ? AND trasferta_id = ?
        """, (p['giornata'], casa_id, trasferta_id))
        risultato = cursor.fetchone()

        if risultato:
            partita_id = risultato[0]
            gol_casa = p['gol_casa'] if isinstance(p['gol_casa'], int) else None
            gol_trasferta = p['gol_trasferta'] if isinstance(p['gol_trasferta'], int) else None

            # Esempio condizionale
            data_ora_dt = pd.to_datetime(p['data_ora'], dayfirst=True)
            data_ora_dt = data_ora_dt.tz_localize('Europe/Rome')
            now = datetime.now(pytz.timezone('Europe/Rome'))

            if data_ora_dt < now and gol_casa is not None:
                aggiornate += 1
                # Qui metti i tuoi update logic
                # ...
        else:
            st.warning(f"Partita non trovata per giornata {p['giornata']} casa {p['squadra_casa']} trasferta {p['squadra_trasferta']}")
    conn.commit()
    st.success(f"Totale righe aggiornate: {aggiornate}")

def get_giornata_partenza():
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT MIN(p.giornata_id)-1 as id_giornata FROM partite_2025_26 p WHERE p.casa_gol IS NULL;")
        result = cursor.fetchone()
        return result[0] if result and result[0] is not None else 1

def aggiorna_database(giornata):
    partite = st.session_state.get('partite')
    if partite:
        with get_db_connection() as conn:
            aggiorna_db_partite(conn, partite)
            aggiorna_top_six(giornata)
            aggiorna_bet_top_team()
        # ⚠️ Carica il DB su Drive dopo modifiche
        upload_db_to_drive(DB_PATH)
        st.success("Database aggiornato correttamente e sincronizzato su Drive.")
        st.session_state.pop('partite', None)
        st.session_state.pop('giornata_caricata', None)
    else:
        st.warning("Nessun dato partita disponibile per aggiornare.")

def caricamento_dati(giornata):
    if 'partite' not in st.session_state or st.session_state.get('giornata_caricata') != giornata:
        partite = estrai_dati_giornata(giornata)
        if not partite:
            st.warning("Nessuna partita trovata o errore nello scraping.")
            return
        st.session_state.partite = partite
        st.session_state.giornata_caricata = giornata
    else:
        partite = st.session_state.partite

    df_visual = pd.DataFrame(partite)
    st.write(f"Dati estratti dalla giornata {giornata}:")
    st.dataframe(df_visual, use_container_width=True)

    st.button("Conferma e Aggiorna Database", on_click=aggiorna_database, args=(giornata,))

def select_query(query, params=()):
    conn = get_db_connection()
    if not conn:
        return []
    try:
        cur = conn.cursor()
        cur.execute(query, params)
        return [dict(row) for row in cur.fetchall()]
    except sqlite3.Error as e:
        st.error(f"Errore SELECT: {e}")
        return []
    finally:
        conn.close()

def update_query(query, params=()):
    conn = get_db_connection()
    if not conn:
        return False
    try:
        cur = conn.cursor()
        cur.execute(query, params)
        conn.commit()
        return True
    except sqlite3.Error as e:
        st.error(f"Errore UPDATE: {e}")
        return False
    finally:
        conn.close()

def aggiorna_top_six(giornata):
    # implementa come nel tuo script originale
    pass  # qui il tuo codice

def aggiorna_bet_top_team():
    # implementa come nel tuo script originale
    pass  # qui il tuo codice

def load_partite():
    conn = get_db_connection()
    query = """
    SELECT p.giornata_id,
           p.data_ora,
           s1.nome_squadra AS squadra_casa,
           p.casa_gol,
           s2.nome_squadra AS squadra_trasferta,
           p.trasferta_gol
    FROM partite_2025_26 p
    JOIN squadre_2025_26 s1 ON p.casa_id = s1.id
    JOIN squadre_2025_26 s2 ON p.trasferta_id = s2.id
    ORDER BY p.giornata_id, p.data_ora
    """
    df = pd.read_sql_query(query, get_db_connection())
    return df

def load_bet():
    conn = get_db_connection()
    query = """
    SELECT b.*, td.squadra_id, s.nome_squadra
    FROM bet_2025_2026 b
    LEFT JOIN top_draw_teams td ON b.fk_id_draw = td.id_draw
    LEFT JOIN squadre_2025_26 s ON td.squadra_id = s.id
    """
    df = pd.read_sql_query(query, get_db_connection())
    return df

def load_top_draw():
    conn = get_db_connection()
    query = """
        SELECT t.*, s.nome_squadra, s.logo_url
        FROM top_draw_teams t
        JOIN squadre_2025_26 s ON t.squadra_id = s.id
    """
    df = pd.read_sql_query(query, get_db_connection())
    return df

# --- UI Streamlit ---

st.title("Interfaccia Serie B 2025/26")

pagina = st.selectbox("Seleziona sezione", ["Visualizza Dati", "Caricamento Dati", "Aggiornamento Dati"])

if pagina == "Visualizza Dati":
    page = st.selectbox("Seleziona tabella", ["Partite", "Scommesse", "Top Draw Teams"])
    if page == "Partite":
        partite = load_partite()
        top_six = select_query("""
            SELECT s.nome_squadra, s.logo_url
            FROM top_draw_teams t
            JOIN squadre_2025_26 s ON t.squadra_id = s.id
        """)
        top_six = pd.DataFrame(top_six)
        logo_dict = dict(zip(top_six['nome_squadra'], top_six['logo_url']))

        partite['logo_casa'] = partite['squadra_casa'].map(logo_dict)
        partite['logo_trasferta'] = partite['squadra_trasferta'].map(logo_dict)
        partite['Risultato'] = partite.apply(
            lambda row: f"{int(row['casa_gol'])} - {int(row['trasferta_gol'])}"
            if pd.notna(row['casa_gol']) and pd.notna(row['trasferta_gol']) else " - ",
            axis=1
        )

        giornate = ['Tutte'] + sorted(partite['giornata_id'].unique())
        giornata_partenza = max(get_giornata_partenza(), 1)
        giornata = st.selectbox(
            "Seleziona giornata",
            giornate,
            index=giornate.index(giornata_partenza) if giornata_partenza in giornate else 1
        )

        if giornata != 'Tutte':
            partite = partite[partite['giornata_id'] == giornata]

        partite = partite.sort_values(by='data_ora', ascending=True)
        col_order = ["giornata_id", "data_ora", "logo_casa", "squadra_casa", "Risultato", "squadra_trasferta", "logo_trasferta"]
        partite_display = partite[col_order]

        st.dataframe(
            partite_display.style.apply(highlight_vincita, axis=1),
            use_container_width=True,
            column_config={
                "giornata_id": st.column_config.TextColumn("Giornata"),
                "data_ora": st.column_config.TextColumn("Data & Ora"),
                "logo_casa": st.column_config.ImageColumn("Logo Casa", width="small"),
                "squadra_casa": st.column_config.TextColumn("Casa"),
                "Risultato": st.column_config.TextColumn("Risultato"),
                "squadra_trasferta": st.column_config.TextColumn("Trasferta"),
                "logo_trasferta": st.column_config.ImageColumn("Logo Trasferta", width="small"),
            }
        )

    elif page == "Scommesse":
        bet = load_bet()
        giornate = ['Tutte'] + sorted(bet['fk_id_giornata'].dropna().unique().astype(int))
        giornata_partenza = get_giornata_partenza()
        giornata = st.selectbox("Seleziona giornata", giornate, index=giornate.index(giornata_partenza) if giornata_partenza in giornate else 1)

        if giornata != 'Tutte':
            bet = bet[bet['fk_id_giornata'] == giornata]
        bet = bet.copy()
        cols_to_remove = ['id', 'fk_id_draw', 'fk_id_giornata', 'fk_id_partita', 'squadra_id']
        bet.drop(columns=[col for col in cols_to_remove if col in bet.columns], inplace=True)
        for col in ['quota', 'vincita', 'saldo_squadra_pareggi', 'saldo_totale']:
            if col in bet.columns:
                bet[col] = bet[col].apply(lambda x: f"{x:.2f}" if pd.notnull(x) else "")
        cols = bet.columns.tolist()
        if 'nome_squadra' in cols:
            cols.insert(0, cols.pop(cols.index('nome_squadra')))
            bet = bet[cols]
        st.dataframe(bet.style.apply(highlight_vincita, axis=1), use_container_width=True)

    else:  # Top Draw Teams
        top_draw = load_top_draw()
        cols_to_remove = ["squadra_id", "in_top_six"]
        top_draw = top_draw.drop(columns=[c for c in cols_to_remove if c in top_draw.columns])
        top_draw = top_draw.sort_values(by=["id_draw", "numero_pareggi"], ascending=[True, False])
        desired_order = ["logo_url", "nome_squadra", "numero_pareggi", "id_draw", "giornata"]
        columns = [c for c in desired_order if c in top_draw.columns]
        columns += [c for c in top_draw.columns if c not in columns]
        top_draw = top_draw[columns]
        st.dataframe(
            top_draw.style.apply(highlight_vincita, axis=1),
            use_container_width=True,
            column_config={
                "logo_url": st.column_config.ImageColumn("Logo", help="Logo", width="small"),
                "nome_squadra": st.column_config.TextColumn("Squadra"),
                "numero_pareggi": st.column_config.NumberColumn("Numero Pareggi"),
                "id_draw": st.column_config.NumberColumn("ID_DRAW", format="%d"),
                "fk_giornata_id": st.column_config.NumberColumn("Giornata")
            }
        )

elif pagina == "Caricamento Dati":
    giornata_sel = st.number_input("Inserisci la giornata da caricare", min_value=1, max_value=38, value=1)
    if st.button("Carica Dati Giornata"):
        caricamento_dati(giornata_sel)

elif pagina == "Aggiornamento Dati":
    st.title("Aggiornamento Dati - Match da giocare")
    giornate_disponibili = select_query(
        "SELECT DISTINCT fk_id_giornata FROM bet_2025_2026 WHERE giocato = 'NO' ORDER BY fk_id_giornata;"
    )
    lista_giornate = [row["fk_id_giornata"] for row in giornate_disponibili] if giornate_disponibili else []

    if lista_giornate:
        giornata_partenza = st.selectbox("Seleziona la giornata da giocare:", options=lista_giornate, index=0)
        bet_rows = select_query(f"""
            SELECT s1.nome_squadra || ' - ' || s2.nome_squadra AS name_match,
                   s1.nome_squadra AS squadra_casa,
                   s2.nome_squadra AS squadra_tras,
                   p.data_ora, 
                   b.fk_id_draw AS id_draw,
                   b.spese_giornata,
                   b.fk_id_giornata
            FROM bet_2025_2026 AS b
            JOIN partite_2025_26 p ON p.id = b.fk_id_partita 
            JOIN squadre_2025_26 s1 ON s1.id = p.casa_id 
            JOIN squadre_2025_26 s2 ON s2.id = p.trasferta_id
            WHERE b.fk_id_giornata = {giornata_partenza}
              AND b.giocato = 'NO';
        """)
        if bet_rows:
            st.info(f"Ci sono {len(bet_rows)} partite da giocare per la giornata {giornata_partenza}.")
            for i, row in enumerate(bet_rows, start=1):
                with st.expander(f"Partita {i}: {row['name_match']} - {row['data_ora']}"):
                    st.write(f"Devi giocare: {row['spese_giornata']} €")
                    giocato = st.radio("Vuoi giocare questa partita?", options=["Sì", "No"], key=f"giocato_{giornata_partenza}_{i}")
                    if giocato == "Sì":
                        quota = st.text_input("Inserisci la quota decimale:", key=f"quota_{giornata_partenza}_{i}")
                        if st.button("Conferma quota", key=f"btn_confirm_{giornata_partenza}_{i}"):
                            try:
                                quota_val = float(quota)
                                if quota_val <= 0:
                                    st.error("Inserisci un valore numerico > 0.")
                                else:
                                    update_sql = f"""
                                        UPDATE bet_2025_2026
                                        SET giocato='SI', quota={quota_val}
                                        WHERE fk_id_giornata={row['fk_id_giornata']}
                                        AND fk_id_draw={row['id_draw']};
                                    """
                                    update_query(update_sql)
                                    st.success(f"Quota {quota_val} salvata per la partita {row['name_match']}")
                                    st.rerun()
                            except ValueError:
                                st.error("Quota deve essere un numero decimale valido.")
        else:
            st.success(f"Sono state giocate tutte le partite per la giornata {giornata_partenza}.")
    else:
        st.warning("Non ci sono giornate con partite da giocare.")
