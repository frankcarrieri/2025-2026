import psycopg2
import psycopg2.extras
import pandas as pd
import streamlit as st
import requests
from bs4 import BeautifulSoup
from datetime import date
import re
import unicodedata
import pytz
from datetime import datetime
from st_aggrid import AgGrid, GridOptionsBuilder

DB_URL = "postgresql://neondb_owner:npg_I2pXZvQEy9xY@ep-cold-cloud-ab28il6u-pooler.eu-west-2.aws.neon.tech/neondb?sslmode=require&channel_binding=require"

def get_db_connection():
    try:
        conn = psycopg2.connect(DB_URL)
        return conn
    except Exception as e:
        st.error(f"Errore connessione DB: {e}")
        return None

def select_query(query, params=()):
    conn = get_db_connection()
    if not conn:
        return []
    try:
        cur = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cur.execute(query, params)
        results = cur.fetchall()
        return [dict(row) for row in results]
    except Exception as e:
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
    except Exception as e:
        st.error(f"Errore UPDATE: {e}")
        return False
    finally:
        conn.close()

def highlight_vincita(row):
    color = ''
    if 'vincita' in row and row['vincita'] not in ("", "0.00"):
        color = 'background-color: darkgreen; color: white'
    elif 'id_draw' in row and row['id_draw'] is not None and str(row['id_draw']) not in ("", "0.00"):
        color = 'background-color: darkgreen; color: white'
    return [color] * len(row)

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
            WHERE giornata_id = %s AND casa_id = %s AND trasferta_id = %s
        """, (p['giornata'], casa_id, trasferta_id))
        risultato = cursor.fetchone()

        if risultato:
            partita_id = risultato[0]
            gol_casa = p['gol_casa'] if isinstance(p['gol_casa'], int) else None
            gol_trasferta = p['gol_trasferta'] if isinstance(p['gol_trasferta'], int) else None

            data_ora_dt = pd.to_datetime(p['data_ora'], dayfirst=True)
            data_ora_dt = data_ora_dt.tz_localize('Europe/Rome')
            now = datetime.now(pytz.timezone('Europe/Rome'))

            if data_ora_dt < now and gol_casa is not None:
                aggiornate += 1
                cursor.execute("""
                    UPDATE partite_2025_26
                    SET data_ora = %s, casa_gol = %s, trasferta_gol = %s
                    WHERE id = %s""", (p['data_ora'], gol_casa, gol_trasferta, partita_id))
                # Altre logiche di aggiornamento sono da mantenere se necessarie
        else:
            st.warning(f"Partita non trovata per giornata {p['giornata']} casa {p['squadra_casa']} trasferta {p['squadra_trasferta']}")

    conn.commit()
    st.success(f"Totale righe aggiornate: {aggiornate}")

def get_giornata_partenza():
    with get_db_connection() as conn:
        cursor = conn.cursor()
        cursor.execute("SELECT COALESCE(MIN(p.giornata_id)-1, 1) FROM partite_2025_26 p WHERE p.casa_gol IS NULL;")
        result = cursor.fetchone()
        return result[0] if result and result[0] else 1

def aggiorna_database(giornata):
    partite = st.session_state.get('partite')
    if partite:
        with get_db_connection() as conn:
            aggiorna_db_partite(conn, partite)
            aggiorna_top_six(giornata)
            aggiorna_bet_top_team()
        st.success("Database aggiornato correttamente.")
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

def aggiorna_bet_top_team():
    # Implementazione adattata a PostgreSQL usando select_query, update_query
    for top_team in range(1, 7):
        risultato = select_query(
            "SELECT MIN(p.giornata_id) as id_giornata from partite_2025_26 p where p.casa_gol is NULL;"
        )
        if risultato:
            giornata_partenza = risultato[0]["id_giornata"]
        else:
            continue
        
        while giornata_partenza <= 38:
            partita_query = f"""
                SELECT p.id
                FROM partite_2025_26 p
                JOIN squadre_2025_26 s1 ON s1.id = p.casa_id
                JOIN squadre_2025_26 s2 ON s2.id = p.trasferta_id
                JOIN top_draw_teams tdt1 ON tdt1.squadra_id = s1.id
                JOIN top_draw_teams tdt2 ON tdt2.squadra_id = s2.id
                WHERE p.giornata_id = {giornata_partenza}
                AND (tdt1.id_draw = {top_team} OR tdt2.id_draw = {top_team})
                LIMIT 1;
            """
            id_partita_res = select_query(partita_query)
            if not id_partita_res:
                giornata_partenza += 1
                continue
            id_partita = id_partita_res[0]["id"]

            bet_query = f"""
                SELECT * FROM bet_2025_2026
                WHERE fk_id_giornata = {giornata_partenza} AND fk_id_draw = {top_team}
                LIMIT 1;
            """
            bet_row = select_query(bet_query)
            if not bet_row:
                giornata_partenza += 1
                continue

            bet_prec_query = f"""
                SELECT * FROM bet_2025_2026
                WHERE fk_id_giornata = {giornata_partenza - 1} AND fk_id_draw = {top_team}
                LIMIT 1;
            """
            bet_row_prec = select_query(bet_prec_query)

            if giornata_partenza == 1:
                saldo_squadra_pareggi = -5
                capitale_investito = -5
                spese_giornata = 5
            else:
                if bet_row_prec and bet_row_prec[0].get("vincita") is None:
                    capitale_investito_prec = bet_row_prec[0]["capitale_investito"]
                    spese_giornata = bet_row_prec[0]["spese_giornata"] * 2
                    capitale_investito = capitale_investito_prec - spese_giornata
                else:
                    capitale_investito = -5
                    spese_giornata = 5

                saldo_squadra_pareggi_prec = bet_row_prec[0]["saldo_squadra_pareggi"] if bet_row_prec else 0
                saldo_squadra_pareggi = saldo_squadra_pareggi_prec - spese_giornata

            update_sql = f"""
                UPDATE bet_2025_2026
                SET fk_id_partita = {id_partita},
                    saldo_squadra_pareggi = {saldo_squadra_pareggi},
                    capitale_investito = {capitale_investito},
                    spese_giornata = {spese_giornata}
                WHERE id = {bet_row[0]["id"]};
            """
            update_query(update_sql)
            giornata_partenza += 1

def aggiorna_top_six(giornata):
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

    for out in outsiders:
        for idx, in_ in enumerate(insiders):
            in_match = select_query(
                "SELECT * FROM partite_2025_26 WHERE giornata_id = %s AND (casa_id = %s OR trasferta_id = %s)",
                (giornata, in_['squadra_id'], in_['squadra_id']))
            out_match = select_query(
                "SELECT * FROM partite_2025_26 WHERE giornata_id = %s AND (casa_id = %s OR trasferta_id = %s)",
                (giornata, out['squadra_id'], out['squadra_id']))

            if not in_match or not out_match:
                continue

            ingolCasa, ingolTras = in_match[0]['casa_gol'], in_match[0]['trasferta_gol']
            outgolCasa, outgolTras = out_match[0]['casa_gol'], out_match[0]['trasferta_gol']

            if ingolCasa == ingolTras and outgolCasa == outgolTras:
                if out['numero_pareggi'] > in_['numero_pareggi']:
                    update_query("UPDATE top_draw_teams SET in_top_six = 0, id_draw = NULL WHERE squadra_id = %s", (in_['squadra_id'],))
                    update_query("UPDATE top_draw_teams SET in_top_six = 1, id_draw = %s, fk_giornata_id = %s WHERE squadra_id = %s",
                                (in_['id_draw'], giornata, out['squadra_id']))
                    st.success(f"SWAP giornata {giornata}: outsider {out['squadra_id']} (pareggi={out['numero_pareggi']}) entra al posto di insider {in_['squadra_id']} (pareggi={in_['numero_pareggi']})")
                    insiders[idx] = {'squadra_id': out['squadra_id'], 'numero_pareggi': out['numero_pareggi'], 'id_draw': in_['id_draw']}
                    insiders.sort(key=lambda x: x['numero_pareggi'])
                    break

def load_partite():
    conn = get_db_connection()
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
    df = pd.read_sql_query(query, con=get_db_connection())
    return df

def load_bet():
    conn = get_db_connection()
    query = """
    SELECT b.*, td.squadra_id, s.nome_squadra
    FROM bet_2025_2026 b
    LEFT JOIN top_draw_teams td ON b.fk_id_draw = td.id_draw
    LEFT JOIN squadre_2025_26 s ON td.squadra_id = s.id
    """
    df = pd.read_sql_query(query, con=get_db_connection())
    return df

def load_top_draw():
    conn = get_db_connection()
    query = """
        SELECT t.*, s.nome_squadra, s.logo_url
        FROM top_draw_teams t
        JOIN squadre_2025_26 s ON t.squadra_id = s.id
    """
    df = pd.read_sql_query(query, con=get_db_connection())
    return df

# --- Streamlit UI ---

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
            lambda row: f"{int(row['casa_gol'])} - {int(row['trasferta_gol'])}" if pd.notna(row['casa_gol']) and pd.notna(row['trasferta_gol']) else " - ",
            axis=1
        )

        giornate = ['Tutte'] + sorted(partite['giornata_id'].unique())
        giornata_partenza = max(get_giornata_partenza(), 1)
        giornata = st.selectbox("Seleziona giornata",
                               giornate,
                               index=giornate.index(giornata_partenza) if giornata_partenza in giornate else 1)

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
        giornata = st.selectbox("Seleziona giornata", giornate,
                               index=giornate.index(giornata_partenza) if giornata_partenza in giornate else 1)

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

        top_draw = top_draw.sort_values(
            by=["id_draw", "numero_pareggi"],
            ascending=[True, False]
        )
        desired_order = ["logo_url", "nome_squadra", "numero_pareggi", "id_draw", "giornata"]

        columns = [c for c in desired_order if c in top_draw.columns]
        columns += [c for c in top_draw.columns if c not in columns]
        top_draw = top_draw[columns]

        st.dataframe(
            top_draw.style.apply(highlight_vincita, axis=1),
            use_container_width=True,
            column_config={
                "logo_url": st.column_config.ImageColumn("Logo", help="Logo della squadra", width="small"),
                "nome_squadra": st.column_config.TextColumn("Squadra"),
                "numero_pareggi": st.column_config.NumberColumn("Numero Pareggi"),
                "id_draw": st.column_config.NumberColumn("ID_DRAW", format="%d"),
                "giornata": st.column_config.NumberColumn("Giornata")
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
        giornata_partenza = st.selectbox(
            "Seleziona la giornata da giocare:",
            options=lista_giornate,
            index=0
        )

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

                    giocato = st.radio(
                        "Vuoi giocare questa partita?", 
                        options=["Sì", "No"], 
                        key=f"giocato_{giornata_partenza}_{i}"
                    )

                    if giocato == "Sì":
                        quota = st.text_input(
                            "Inserisci la quota decimale:", 
                            key=f"quota_{giornata_partenza}_{i}"
                        )
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
                                    st.experimental_rerun()
                            except ValueError:
                                st.error("Quota deve essere un numero decimale valido.")
        else:
            st.success(f"Sono state giocate tutte le partite per la giornata {giornata_partenza}.")
    else:
        st.warning("Non ci sono giornate con partite da giocare.")
