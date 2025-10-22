# serie_b_app_optimized.py
import re
import unicodedata
from datetime import date, datetime
from typing import List, Dict, Any

import pandas as pd
import pytz
import psycopg2
import psycopg2.extras
from psycopg2 import pool
import requests
from bs4 import BeautifulSoup
import streamlit as st

# ---------- CONFIG ----------
DB_URL = "postgresql://neondb_owner:npg_I2pXZvQEy9xY@ep-cold-cloud-ab28il6u-pooler.eu-west-2.aws.neon.tech/neondb?sslmode=require&channel_binding=require"
MIN_DB_CONNS = 1
MAX_DB_CONNS = 5

# Create a global connection pool at module import (Streamlit keeps module between reruns in the same worker)
if "db_pool" not in st.session_state:
    try:
        st.session_state.db_pool = psycopg2.pool.SimpleConnectionPool(
            MIN_DB_CONNS, MAX_DB_CONNS, dsn=DB_URL, cursor_factory=psycopg2.extras.RealDictCursor
        )
    except Exception as e:
        st.session_state.db_pool = None
        st.error(f"Errore inizializzazione pool DB: {e}")

# ---------- DB HELPERS ----------
def get_conn():
    """Prende una connessione dal pool, o None se non disponibile."""
    pool_obj = st.session_state.get("db_pool")
    if not pool_obj:
        st.error("Pool DB non inizializzato.")
        return None
    try:
        return pool_obj.getconn()
    except Exception as e:
        st.error(f"Errore ottenimento connessione dal pool: {e}")
        return None

def put_conn(conn):
    pool_obj = st.session_state.get("db_pool")
    if pool_obj and conn:
        try:
            pool_obj.putconn(conn)
        except Exception:
            try:
                conn.close()
            except Exception:
                pass

def select_query(query: str, params: tuple = ()) -> List[Dict[str, Any]]:
    """Esegue SELECT e restituisce lista di dict. Usa connessione dal pool."""
    conn = get_conn()
    if not conn:
        return []
    try:
        with conn.cursor() as cur:
            cur.execute(query, params)
            rows = cur.fetchall()
            # RealDictCursor returns list of dict-like objects
            return [dict(r) for r in rows] if rows else []
    except Exception as e:
        st.error(f"Errore SELECT: {e}")
        return []
    finally:
        put_conn(conn)

def update_query(query: str, params: tuple = ()) -> bool:
    """Esegue UPDATE/INSERT/DELETE con commit."""
    conn = get_conn()
    if not conn:
        return False
    try:
        with conn.cursor() as cur:
            cur.execute(query, params)
            conn.commit()
            return True
    except Exception as e:
        st.error(f"Errore UPDATE: {e}")
        try:
            conn.rollback()
        except Exception:
            pass
        return False
    finally:
        put_conn(conn)

# ---------- UTIL ----------
def normalize_str(s: str) -> str:
    if s is None:
        return ""
    s = str(s).strip().lower()
    s = ''.join(c for c in unicodedata.normalize('NFD', s) if unicodedata.category(c) != 'Mn')
    return s

def parse_data_it(data_str: str, anno: int = 2025) -> date:
    data_str = re.sub(r"^\w+,\s*", "", data_str.strip())
    mesi = {
        'gennaio':1, 'febbraio':2, 'marzo':3, 'aprile':4,
        'maggio':5, 'giugno':6, 'luglio':7, 'agosto':8,
        'settembre':9, 'ottobre':10, 'novembre':11, 'dicembre':12
    }
    parti = data_str.split()
    try:
        if len(parti) >= 2:
            giorno = int(parti[0])
            mese = mesi.get(parti[1].lower(), 1)
            return date(anno, mese, giorno)
    except Exception:
        pass
    return date.today()

# ---------- CACHING STATIC DATA ----------
@st.cache_data(ttl=3600, show_spinner=False)
def load_squadre_map() -> Dict[str, int]:
    """Mappa nome_normalizzato -> id squadra, usata frequentemente. Cache per ridurre query."""
    rows = select_query("SELECT id, nome_squadra FROM squadre_2025_26")
    return {normalize_str(r["nome_squadra"]): r["id"] for r in rows}

@st.cache_data(ttl=3600, show_spinner=False)
def load_logo_dict() -> Dict[str, str]:
    rows = select_query("SELECT nome_squadra, logo_url FROM squadre_2025_26")
    return {r["nome_squadra"]: r.get("logo_url", "") for r in rows}

@st.cache_data(ttl=300, show_spinner=False)
def fetch_highlight_teams_set() -> set:
    rows = select_query("""
        SELECT s.nome_squadra FROM top_draw_teams t
        JOIN squadre_2025_26 s ON s.id = t.squadra_id
        WHERE id_draw is not NULL;
    """)
    return {r["nome_squadra"] for r in rows}

# ---------- SCRAPING (cached) ----------
@st.cache_data(ttl=3600, show_spinner=False)
def estrai_dati_giornata(giornata: int) -> List[Dict[str, Any]]:
    """Scrape ottimizzato con caching per ridurre chiamate di rete ripetute."""
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

# ---------- DATABASE UPDATE LOGIC ----------
def aggiorna_db_partite(conn, partite: List[Dict[str, Any]]):
    """Aggiorna le partite in database. Lavora con connessione esterna per batch commit."""
    cursor = conn.cursor()
    # Prefetch mappa squadre (id) per evitare query inside loop
    cursor.execute("SELECT id, nome_squadra FROM squadre_2025_26")
    squadre_map = {normalize_str(nome): id_s for id, nome in cursor.fetchall()}

    aggiornate = 0
    tz = pytz.timezone('Europe/Rome')
    for p in partite:
        casa_id = squadre_map.get(normalize_str(p['squadra_casa']))
        trasferta_id = squadre_map.get(normalize_str(p['squadra_trasferta']))
        if casa_id is None or trasferta_id is None:
            st.warning(f"Squadra non trovata per giornata {p['giornata']}: {p['squadra_casa']} o {p['squadra_trasferta']}")
            continue

        cursor.execute("""SELECT id FROM partite_2025_26 WHERE giornata_id = %s AND casa_id = %s AND trasferta_id = %s""",
                       (p['giornata'], casa_id, trasferta_id))
        risultato = cursor.fetchone()

        if risultato:
            partita_id = risultato[0]
            gol_casa = p['gol_casa'] if isinstance(p['gol_casa'], int) else None
            gol_trasferta = p['gol_trasferta'] if isinstance(p['gol_trasferta'], int) else None

            # Parse data/ora in modo robusto
            try:
                data_ora_dt = pd.to_datetime(p['data_ora'], dayfirst=True)
                data_ora_dt = data_ora_dt.tz_localize(tz)
            except Exception:
                data_ora_dt = None

            now = datetime.now(tz)

            if data_ora_dt and data_ora_dt < now and gol_casa is not None:
                aggiornate += 1
                data_ora_sql = data_ora_dt.strftime("%Y-%m-%d %H:%M:%S")
                cursor.execute("""
                    UPDATE partite_2025_26
                    SET data_ora = %s, casa_gol = %s, trasferta_gol = %s
                    WHERE id = %s
                """, (data_ora_sql, gol_casa, gol_trasferta, partita_id))
            else:
                aggiornate += 1
                if data_ora_dt:
                    data_ora_sql = data_ora_dt.strftime("%Y-%m-%d %H:%M:%S")
                    cursor.execute("""
                        UPDATE partite_2025_26
                        SET data_ora = %s
                        WHERE id = %s
                    """, (data_ora_sql, partita_id))
        else:
            st.warning(f"Partita non trovata per giornata {p['giornata']} casa {p['squadra_casa']} trasferta {p['squadra_trasferta']}")

    conn.commit()
    st.success(f"Totale righe aggiornate: {aggiornate}")

def aggiorna_database(giornata: int):
    partite = st.session_state.get('partite')
    if not partite:
        st.warning("Nessun dato partita disponibile per aggiornare.")
        return
    conn = get_conn()
    if not conn:
        return
    try:
        aggiorna_db_partite(conn, partite)
        aggiorna_top_six(giornata)
        st.session_state.pop('partite', None)
        st.session_state.pop('giornata_caricata', None)
    finally:
        put_conn(conn)

# ---------- BATCH LOGIC PER TOP BETS (ottimizzata) ----------
def aggiorna_bet_top_team():
    """Versione più efficiente: minimizza SELECT ripetute e usa dati prefetch."""
    # Recupera la prima giornata con casa_gol NULL
    risultato = select_query("SELECT MIN(p.giornata_id) as id_giornata FROM partite_2025_26 p WHERE p.casa_gol IS NULL;")
    if not risultato or risultato[0]["id_giornata"] is None:
        st.info("Nessuna giornata da processare in aggiorna_bet_top_team.")
        return
    start_giornata = int(risultato[0]["id_giornata"])

    # Pre-fetch bet rows per giornate di interesse (es. start..start+20) - adattabile
    MAX_LOOKAHEAD = 20
    bet_rows = select_query("""
        SELECT b.*, td.squadra_id, b.fk_id_giornata
        FROM bet_2025_2026 b
        LEFT JOIN top_draw_teams td ON b.fk_id_draw = td.id_draw
        WHERE b.fk_id_giornata >= %s AND b.fk_id_giornata <= %s
    """, (start_giornata, start_giornata + MAX_LOOKAHEAD))

    if not bet_rows:
        st.info("Nessun bet da aggiornare nel range considerato.")
        return

    # Per ogni bet cerchiamo la partita corrispondente una sola volta per giornata
    # Prefetch map: (giornata, squadra_id) -> partita_id
    giornate_needed = sorted({int(b["fk_id_giornata"]) for b in bet_rows if b.get("fk_id_giornata")})
    squadra_ids = sorted({int(b["squadra_id"]) for b in bet_rows if b.get("squadra_id")})
    partita_map = {}  # (giornata, squadra_id) -> partita_id

    if giornate_needed and squadra_ids:
        placeholders_giornate = ",".join(["%s"] * len(giornate_needed))
        placeholders_squadre = ",".join(["%s"] * len(squadra_ids))
        query = f"""
            SELECT p.id, p.giornata_id, p.casa_id, p.trasferta_id
            FROM partite_2025_26 p
            WHERE p.giornata_id IN ({placeholders_giornate})
            AND (p.casa_id IN ({placeholders_squadre}) OR p.trasferta_id IN ({placeholders_squadre}))
        """
        params = tuple(giornate_needed) + tuple(squadra_ids) + tuple(squadra_ids)
        rows = select_query(query, params)
        for r in rows:
            # se squadra_id è casa o trasferta, mappiamo
            if r["casa_id"] in squadra_ids:
                partita_map[(r["giornata_id"], r["casa_id"])] = r["id"]
            if r["trasferta_id"] in squadra_ids:
                partita_map[(r["giornata_id"], r["trasferta_id"])] = r["id"]

    # Ora aggiorniamo i bet in memoria e nel DB
    updates_performed = 0
    for b in bet_rows:
        fk_g = int(b["fk_id_giornata"])
        draw = b.get("fk_id_draw")
        squadra_id = b.get("squadra_id")
        partita_id = partita_map.get((fk_g, squadra_id))
        if not partita_id:
            continue

        # Ricostruzione della logica sulle variabili di investimento (semplificata ma equivalente)
        prev = select_query("SELECT * FROM bet_2025_2026 WHERE fk_id_giornata = %s AND fk_id_draw = %s LIMIT 1;",
                            (fk_g - 1, draw))
        if fk_g == 1:
            saldo_squadra_pareggi = -5
            capitale_investito = -5
            spese_giornata = 5
        else:
            if prev and prev[0].get("vincita") is None:
                capitale_investito_prec = prev[0].get("capitale_investito", 0)
                spese_giornata = (prev[0].get("spese_giornata", 5) or 5) * 2
                capitale_investito = (capitale_investito_prec or 0) - spese_giornata
            else:
                capitale_investito = -5
                spese_giornata = 5
            saldo_squadra_pareggi_prec = prev[0].get("saldo_squadra_pareggi", 0) if prev else 0
            saldo_squadra_pareggi = (saldo_squadra_pareggi_prec or 0) - spese_giornata

        # Update singolo parametrizzato
        update_query("""
            UPDATE bet_2025_2026
            SET fk_id_partita = %s,
                saldo_squadra_pareggi = %s,
                capitale_investito = %s,
                spese_giornata = %s
            WHERE id = %s
        """, (partita_id, saldo_squadra_pareggi, capitale_investito, spese_giornata, b["id"]))
        updates_performed += 1

    st.success(f"Aggiornati {updates_performed} bet (aggiorna_bet_top_team).")

# ---------- TOP SIX LOGIC ----------
def aggiorna_top_six(giornata: int):
    teams = select_query("""
        SELECT squadra_id, numero_pareggi, in_top_six, id_draw, fk_giornata_id as giornata
        FROM top_draw_teams
        WHERE fk_giornata_id = %s
    """, (giornata,))

    if not teams:
        st.error(f"Nessuna squadra ha pareggiato in giornata {giornata}")
        return

    insiders = [t for t in teams if t['in_top_six'] == 1]
    outsiders = [t for t in teams if t['in_top_six'] == 0]

    if not insiders or not outsiders:
        st.error(f"Nessuna coppia insider/outside ha pareggiato insieme in giornata {giornata}")
        return

    outsiders.sort(key=lambda x: x['numero_pareggi'], reverse=True)
    insiders.sort(key=lambda x: x['numero_pareggi'])

    # Per minimizzare SELECT multipli, prefetch tutte le partite della giornata
    partite_giornata = select_query("SELECT * FROM partite_2025_26 WHERE giornata_id = %s", (giornata,))

    def find_match_for_team(squadra_id):
        for p in partite_giornata:
            if p['casa_id'] == squadra_id or p['trasferta_id'] == squadra_id:
                return p
        return None

    for out in outsiders:
        for idx, in_ in enumerate(insiders):
            in_match = find_match_for_team(in_['squadra_id'])
            out_match = find_match_for_team(out['squadra_id'])

            if not in_match or not out_match:
                continue

            ingolCasa, ingolTras = in_match['casa_gol'], in_match['trasferta_gol']
            outgolCasa, outgolTras = out_match['casa_gol'], out_match['trasferta_gol']

            if ingolCasa == ingolTras and outgolCasa == outgolTras:
                if out['numero_pareggi'] > in_['numero_pareggi']:
                    update_query("UPDATE top_draw_teams SET in_top_six = 0, id_draw = NULL WHERE squadra_id = %s", (in_['squadra_id'],))
                    update_query("UPDATE top_draw_teams SET in_top_six = 1, id_draw = %s, fk_giornata_id = %s WHERE squadra_id = %s",
                                 (in_['id_draw'], giornata, out['squadra_id']))
                    st.success(f"SWAP giornata {giornata}: outsider {out['squadra_id']} entra al posto di insider {in_['squadra_id']}")
                    insiders[idx] = {'squadra_id': out['squadra_id'], 'numero_pareggi': out['numero_pareggi'], 'id_draw': in_['id_draw']}
                    insiders.sort(key=lambda x: x['numero_pareggi'])
                    break

# ---------- LOADERS (per UI) ----------
@st.cache_data(ttl=300, show_spinner=False)
def load_partite_df() -> pd.DataFrame:
    query = """
    SELECT 
        p.giornata_id,
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
    conn = get_conn()
    if not conn:
        return pd.DataFrame()

    try:
        # --- DEBUG: esecuzione manuale senza pandas ---
        cur = conn.cursor()
        cur.execute(query)
        rows = cur.fetchall()
        cols = [desc[0] for desc in cur.description]

        # Crea manualmente il DataFrame per evitare conversioni strane
        df = pd.DataFrame(rows, columns=cols)

        return df

    except Exception as e:
        st.error(f"Errore read_sql per partite: {e}")
        return pd.DataFrame()
    finally:
        put_conn(conn)

@st.cache_data(ttl=300, show_spinner=False)
def load_bet_df() -> pd.DataFrame:
    query = """
    SELECT b.*, td.squadra_id, s.nome_squadra
    FROM bet_2025_2026 b
    LEFT JOIN top_draw_teams td ON b.fk_id_draw = td.id_draw
    LEFT JOIN squadre_2025_26 s ON td.squadra_id = s.id
    """

    conn = get_conn()
    if not conn:
        return pd.DataFrame()
    try:
        # --- DEBUG: esecuzione manuale senza pandas ---
        cur = conn.cursor()
        cur.execute(query)
        rows = cur.fetchall()
        cols = [desc[0] for desc in cur.description]

        # Crea manualmente il DataFrame per evitare conversioni strane
        df = pd.DataFrame(rows, columns=cols)

        return df
        # return pd.read_sql_query(query, con=conn)
    except Exception as e:
        st.error(f"Errore read_sql per bet: {e}")
        return pd.DataFrame()
    finally:
        put_conn(conn)

@st.cache_data(ttl=300, show_spinner=False)
def load_top_draw_df() -> pd.DataFrame:
    query = """
    SELECT t.*, s.nome_squadra, s.logo_url
    FROM top_draw_teams t
    JOIN squadre_2025_26 s ON t.squadra_id = s.id
    """

    conn = get_conn()
    if not conn:
        return pd.DataFrame()
    try:
        # --- DEBUG: esecuzione manuale senza pandas ---
        cur = conn.cursor()
        cur.execute(query)
        rows = cur.fetchall()
        cols = [desc[0] for desc in cur.description]

        # Crea manualmente il DataFrame per evitare conversioni strane
        df = pd.DataFrame(rows, columns=cols)

        return df
    except Exception as e:
        st.error(f"Errore read_sql per top_draw: {e}")
        return pd.DataFrame()
    finally:
        put_conn(conn)

# ---------- UI (Streamlit) ----------
st.set_page_config(page_title="Interfaccia Serie B 2025/26", layout="centered")
st.title("Interfaccia Serie B 2025/26")

# Layout più semplice per mobile: poche colonne, expander per dettaglio
pagina = st.selectbox("Seleziona sezione", ["Visualizza Dati", "Caricamento Dati", "Aggiornamento Dati"])

# Precarico cache utili
squadre_map = load_squadre_map()
logo_dict = load_logo_dict()
highlight_teams = fetch_highlight_teams_set()

def highlight_vincita_row(row):
    """Funzione di styling leggera: usa dati precaricati per non interrogare il DB per ogni riga."""
    color = ''
    # controllo 'vincita' (se presente)
    if 'vincita' in row and row['vincita'] not in (None, "", "0.00"):
        color = 'background-color: darkgreen; color: white'
    elif ('squadra_casa' in row and 'squadra_trasferta' in row and
          (row['squadra_casa'] in highlight_teams or row['squadra_trasferta'] in highlight_teams)):
        color = 'background-color: darkgreen; color: white'
    return [color] * len(row)

if pagina == "Visualizza Dati":
    page = st.selectbox("Seleziona tabella", ["Partite", "Scommesse", "Top Draw Teams"])

    if page == "Partite":
        df = load_partite_df()
        st.write("Righe trovate:", len(df))  # debug visivo
        if df.empty:
            st.warning("Nessuna partita disponibile (verifica il DB o il filtro giornata).")
        else:
            df['logo_casa'] = df['squadra_casa'].map(logo_dict)
            df['logo_trasferta'] = df['squadra_trasferta'].map(logo_dict)

            def format_result(row):
                try:
                    if pd.notna(row['casa_gol']) and pd.notna(row['trasferta_gol']):
                        return f"{int(row['casa_gol'])} - {int(row['trasferta_gol'])}"
                except Exception:
                    pass
                return " - "
            df['Risultato'] = df.apply(format_result, axis=1)

            giornata = st.number_input("Seleziona giornata", min_value=1, max_value=38, value=1)
            try:
                df_display = df[df['giornata_id'].astype(int) == int(giornata)]
            except Exception:
                df_display = df
            
            df = df_display.sort_values(by='data_ora', ascending=True)

            col_order = ["giornata_id", "data_ora", "logo_casa", "squadra_casa", "Risultato", "squadra_trasferta", "logo_trasferta"]
            partite_display = df[col_order]  # include highlight per lo style

            st.dataframe(
                partite_display.style
                    .apply(highlight_vincita_row, axis=1),
                width='stretch',
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
        bet = load_bet_df()
        if bet.empty:
            st.warning("Nessuna scommessa disponibile.")
        else:
            # prepare giornate
            if 'fk_id_giornata' in bet.columns:
                try:
                    # Filtra solo valori numerici, ignora stringhe tipo 'fk_id_giornata'
                    giornate = sorted([
                        int(g) for g in bet['fk_id_giornata'].dropna().unique()
                        if str(g).isdigit()
                    ])
                except Exception:
                    giornate = []
                #giornata = st.selectbox("Seleziona giornata", options=["Tutte"] + giornate, index=0)
                giornata = st.number_input("Seleziona giornata", min_value=1, max_value=38, value=1)
                try:
                    df_display = bet[bet['giornata_id'].astype(int) == int(giornata)]
                except Exception:
                    df_display = bet
                if giornata != 'Tutte':
                    bet = bet[bet['fk_id_giornata'] == giornata]
                bet = bet.copy()
                cols_to_remove = ['id', 'fk_id_giornata', 'fk_id_partita', 'squadra_id']
                bet.drop(columns=[col for col in cols_to_remove if col in bet.columns], inplace=True)
                for col in ['quota', 'vincita', 'saldo_squadra_pareggi', 'saldo_totale']:
                    if col in bet.columns:
                        bet[col] = bet[col].apply(lambda x: f"{x:.2f}" if pd.notnull(x) else "")
                cols = bet.columns.tolist()
                if 'nome_squadra' in cols:
                    cols.insert(0, cols.pop(cols.index('nome_squadra')))
                    bet = bet[cols]
                bet = bet.sort_values(by='fk_id_draw', ascending=True)

                st.dataframe(bet.style.apply(highlight_vincita_row, axis=1), width='stretch')

##
            #     if giornata != "Tutte":
            #         df = bet[bet['fk_id_giornata'] == int(giornata)]
            # # format numeri
            # for col in ['quota', 'vincita', 'saldo_squadra_pareggi', 'saldo_totale']:
            #     if col in df.columns:
            #         df[col] = df[col].apply(
            #             lambda x: f"{float(x):.2f}" if pd.notnull(x) and str(x).replace('.', '', 1).isdigit() else x
            #         )
            # # remove heavy cols if present
            # cols_to_remove = ['id', 'fk_id_partita', 'squadra_id']
            # df_display = df.drop(columns=[c for c in cols_to_remove if c in df.columns])
            # st.dataframe(df_display.style.apply(highlight_vincita_row, axis=1), use_container_width=True)

    else:  # Top Draw Teams
        top_draw = load_top_draw_df()
        if top_draw.empty:
            st.warning("Nessun top draw disponibile.")
        else:
            cols_to_remove = ["squadra_id", "in_top_six"]
            top_draw = top_draw.drop(columns=[c for c in cols_to_remove if c in top_draw.columns])

            top_draw = top_draw.sort_values(
                by=["id_draw", "numero_pareggi"],
                ascending=[True, False]
            )
            desired_order = ["logo_url", "nome_squadra", "numero_pareggi", "id_draw", "giornata"]

            columns = [c for c in desired_order if c in top_draw.columns]
            columns += [c for c in top_draw.columns if c not in columns]
            top_draw = top_draw[columns]

            def highlight_vincita_top(row):
                color = ''
                if 'nome_squadra' in row and row['nome_squadra'] in highlight_teams:
                    color = 'background-color: darkgreen; color: white'
                return [color] * len(row)

            st.dataframe(
                top_draw.style.apply(highlight_vincita_top, axis=1),
                width='stretch',
                column_config={
                    "logo_url": st.column_config.ImageColumn("Logo", help="Logo della squadra", width="small"),
                    "nome_squadra": st.column_config.TextColumn("Squadra"),
                    "numero_pareggi": st.column_config.NumberColumn("Numero Pareggi"),
                    "id_draw": st.column_config.NumberColumn("ID_DRAW", format="%d"),
                    "giornata": st.column_config.NumberColumn("Giornata")
                }
            )

elif pagina == "Caricamento Dati":
    # UI compatto per mobile
    giornata_sel = st.number_input("Inserisci la giornata da caricare", min_value=1, max_value=38, value=1, step=1)
    if st.button("Carica Dati Giornata"):
        # carica e salva in session_state per conferma
        partite = estrai_dati_giornata(int(giornata_sel))
        if not partite:
            st.warning("Nessuna partita trovata o errore nello scraping.")
        else:
            st.session_state.partite = partite
            st.session_state.giornata_caricata = int(giornata_sel)
            st.success(f"Trovate {len(partite)} partite per la giornata {giornata_sel}. Verifica e conferma.")

    # Mostra tabella se presente in session_state
    if st.session_state.get('partite'):
        df_visual = pd.DataFrame(st.session_state['partite'])
        with st.expander("Dati estratti (toccabile per aprire)"):
            st.dataframe(df_visual, use_container_width=True)
        col1, col2 = st.columns([1, 1])
        with col1:
            if st.button("Conferma e Aggiorna Database"):
                aggiorna_database(st.session_state.get('giornata_caricata', 1))
        with col2:
            if st.button("Aggiorna Calcolo Partite Successive"):
                aggiorna_bet_top_team()

elif pagina == "Aggiornamento Dati":
    st.title("Aggiornamento Dati - Match da giocare")
    # carico giornate disponibili per giocare
    giornate_disponibili = select_query("SELECT DISTINCT fk_id_giornata FROM bet_2025_2026 WHERE giocato = 'NO' ORDER BY fk_id_giornata;")
    lista_giornate = [row["fk_id_giornata"] for row in giornate_disponibili] if giornate_disponibili else []

    if not lista_giornate:
        st.warning("Non ci sono giornate con partite da giocare.")
    else:
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
            WHERE b.fk_id_giornata = %s
              AND b.giocato = 'NO';
        """, (giornata_partenza,))

        if bet_rows:
            st.info(f"Ci sono {len(bet_rows)} partite da giocare per la giornata {giornata_partenza}.")
            for i, row in enumerate(bet_rows, start=1):
                with st.expander(f"Partita {i}: {row['name_match']} - {row['data_ora']}"):
                    st.write(f"Spese giornata: {row.get('spese_giornata', '')} €")
                    giocato = st.radio("Vuoi giocare questa partita?", options=["Sì", "No"], key=f"giocato_{giornata_partenza}_{i}")
                    if giocato == "Sì":
                        quota = st.text_input("Inserisci la quota decimale:", key=f"quota_{giornata_partenza}_{i}")
                        if st.button("Conferma quota", key=f"btn_confirm_{giornata_partenza}_{i}"):
                            try:
                                quota_val = float(quota)
                                if quota_val <= 0:
                                    st.error("Inserisci un valore numerico > 0.")
                                else:
                                    update_query("""
                                        UPDATE bet_2025_2026
                                        SET giocato='SI', quota=%s
                                        WHERE fk_id_giornata=%s
                                        AND fk_id_draw=%s;
                                    """, (quota_val, row['fk_id_giornata'], row['id_draw']))
                                    st.success(f"Quota {quota_val} salvata per la partita {row['name_match']}")
                                    st.experimental_rerun()
                            except ValueError:
                                st.error("Quota deve essere un numero decimale valido.")
        else:
            st.success(f"Sono state giocate tutte le partite per la giornata {giornata_partenza}.")

# Nota: non chiudiamo il pool esplicitamente, Streamlit gestisce il processo.
