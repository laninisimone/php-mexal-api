#!/usr/bin/env python3
"""Estrae dal manuale WebAPI Passepartout (PDF) le informazioni che ?info=true non espone:
parametri e obbligatorietà per operazione, strutture campi di ripiego, canale servizi.

    pip install pypdf
    python3 tools/api-docs/estrai-manuale.py ManWebapi.pdf docs/api/sorgenti/manuale.json

Il risultato è un'istantanea versionata: va rigenerato solo quando cambia il manuale. Gli
intervalli di pagina in STRUTTURE si riferiscono alla v3.1 (10 luglio 2026).
"""
import re, json, sys
from pypdf import PdfReader

if len(sys.argv) != 3:
    sys.exit(__doc__)

lines = []  # (pagina, testo)
for numero, pagina in enumerate(PdfReader(sys.argv[1]).pages, start=1):
    for l in (pagina.extract_text() or '').split('\n'):
        s = l.strip()
        if s == 'Manuale WebAPI Passepartout' or re.match(r'^Versione 3\.1\s+Pag\. \d+$', s):
            continue
        lines.append((numero, s))

def join_split_types(seq):
    """'quantita Numerico con' + 'virgola No ...' -> una riga sola."""
    out = []
    skip = False
    for i, (p, s) in enumerate(seq):
        if skip:
            skip = False; continue
        if re.search(r' Numerico con$', s) and i + 1 < len(seq) and seq[i + 1][1].startswith('virgola'):
            s = s + ' ' + seq[i + 1][1]; skip = True
        out.append((p, s))
    return out

lines = join_split_types(lines)

# ---------------------------------------------------------------- operazioni
P_TYPES = r'(string|integer|decimal|number|boolean|array|object|Alfanumerico|Numerico con virgola|Numerico|Data e ora|String|Number|Integer|Boolean|Array|Object|string/number/array)'
param_re = re.compile(r'^(\S+) ' + P_TYPES + r' (Sì|No|Si) (body|path|query|header)\s?(.*)$')
op_re = re.compile(r'^(GET|POST|PUT|DELETE)\s+(/[^\s?]+)$')
STOP = ('Esempio', 'Codici di Stato', 'Note Operative', 'ℹ Nota', '⚠ Nota', 'Campi Restituiti', 'Campi Disponibili', 'Struttura Campi')

ops, cur, state = [], None, None
i = 0
while i < len(lines):
    page, s = lines[i]
    m = op_re.match(s)
    if not m and re.match(r'^(GET|POST|PUT|DELETE)\s+/\S+-$', s) and i + 1 < len(lines):
        m = op_re.match(s + lines[i + 1][1])
        if m: i += 1
    if m and page >= 35 and page < 265 and not m.group(2).startswith('/webapi'):
        prev = lines[i - 1][1]
        cur = {'metodo': m.group(1), 'path': m.group(2), 'titolo': prev if len(prev) < 60 else '', 'pagina': page,
               'descrizione': '', 'parametri': [], 'note': []}
        ops.append(cur); state = 'op'; i += 1; continue
    if cur is None:
        i += 1; continue
    if s.startswith('Campo Tipo Obbl'):
        state = 'params'
    elif s.startswith('Note Operative'):
        state = 'notes'
    elif state == 'op':
        if s and not s.startswith(('Parametri', 'Esempio')) and not cur['descrizione'] and not s.startswith(STOP):
            cur['descrizione'] = s
        elif s.startswith(STOP):
            state = None
    elif state == 'params':
        r = param_re.match(s)
        if r:
            cur['parametri'].append({'campo': r.group(1), 'tipo': r.group(2), 'obbligatorio': r.group(3) != 'No',
                                     'posizione': r.group(4), 'descrizione': r.group(5).strip()})
        elif not s or s.startswith(STOP) or s.startswith(('Nel body', 'Esempio')):
            state = None
        elif cur['parametri']:
            cur['parametri'][-1]['descrizione'] = (cur['parametri'][-1]['descrizione'] + ' ' + s).strip()
    elif state == 'notes':
        if s.startswith('•'):
            cur['note'].append(s.lstrip('• ').strip())
        elif s and cur['note'] and s[0].islower():
            cur['note'][-1] += ' ' + s
        else:
            state = None
    i += 1

def norm(path):
    p = re.sub(r'\{[^}]*\}@\{[^}]*\}', '{}', path)
    p = re.sub(r'\{[^}]*\}', '{}', p)
    return p.rstrip('/')

for o in ops:
    o['path_norm'] = norm(o['path'])
    for p in o['parametri']:
        p['descrizione'] = re.sub(r'\s+', ' ', p['descrizione'])
    o['note'] = [re.sub(r'\s+', ' ', n) for n in o['note'] if len(n) < 400]

# ---------------------------------------------------------------- strutture di ripiego
F_TYPES = r'(Alfanumerico|Numerico con virgola|Numerico|Data e ora|Array\[\d+\](?: numerico| alfanumerico)?|Array(?: numerico| alfanumerico)?|string|integer|number|boolean|array|object)'
field_re = re.compile(r'^([A-Za-z][\w\.\[\]]*) ' + F_TYPES + r'(?: (Sì|No))?(?: (.*))?$')
F_STOP = ('Esempio', 'Parametri', 'Note Operative', 'Campo Tipo Obbl', 'Inserimento', 'Formato Response',
          'Codici di Stato', 'ℹ Nota', '⚠ Nota', 'Lista ', 'Lettura ', 'Campi aggiuntivi')

def fields_in(p_from, p_to):
    out, on = [], False
    for page, s in lines:
        if page < p_from or page > p_to:
            continue
        if s.startswith(('Campo Tipo Descrizione', 'Campo Tipo Chiave Descrizione')):
            on = True; continue
        if not on:
            continue
        if s.startswith(F_STOP):
            on = False; continue
        r = field_re.match(s)
        if r:
            nome = r.group(1)
            if nome.endswith('[]') or nome in ('Utenti', 'Aziende'):
                continue
            nome = re.sub(r'^\w+\[\]\.', '', nome)
            out.append({'nome': nome, 'tipo': r.group(2), 'chiave': r.group(3) == 'Sì',
                        'descrizione': (r.group(4) or '').strip()})
        elif out and s and not s.startswith('Campi '):
            out[-1]['descrizione'] = (out[-1]['descrizione'] + ' ' + s).strip()
    seen, uniq = set(), []
    for f in out:
        if f['nome'] not in seen:
            seen.add(f['nome']); uniq.append(f)
    return uniq

STRUTTURE = {
    '/lotti': (200, 201),
    '/distinte-base/fasi': (135, 136),
    '/distinte-base/componenti': (137, 137),
    '/documenti/lavorazione/impegni': (171, 172),
    '/impegni': (171, 172),
    '/dati-generali/installazione': (124, 125),
    '/dati-generali/utenti': (125, 125),
    '/aziende': (87, 88),
    '/progressivi-articoli': (235, 236),
}
strutture = {path: fields_in(*rng) for path, rng in STRUTTURE.items()}
# Le pagine 124-125 contengono sia installazione sia utenti.
strutture['/dati-generali/installazione'] = strutture['/dati-generali/installazione'][:5]
# Tabelle CRUD di dati-generali: il manuale documenta solo chiave intera "codice" + "descrizione".
for t in ['categorie-prezzi', 'categorie-provvigioni', 'categorie-sconti', 'categorie-provvigioni-articoli',
          'categorie-sconti-articoli', 'zone-clienti-fornitori', 'lingue-straniere', 'fasi-lavorazione']:
    strutture['/dati-generali/' + t] = [
        {'nome': 'codice', 'tipo': 'Numerico', 'chiave': True, 'descrizione': 'Codice'},
        {'nome': 'descrizione', 'tipo': 'Alfanumerico', 'chiave': False, 'descrizione': 'Descrizione'},
    ]

# ---------------------------------------------------------------- servizi
S_TYPES = r'(String|Number|Integer|Boolean|Array|Object|Date|Decimal|string|number|integer|boolean|array|object)'
sparam_re = re.compile(r'^(\S+) ' + S_TYPES + r' (Sì|No|Si|Dipende|Condizionale|Cond\.)\s?(.*)$')
sresp_re = re.compile(r'^([a-z][\w\.\[\]]*) ' + S_TYPES + r' (.*)$')
servizi, sez, gruppo, state = [], None, [], None
cmd_re = re.compile(r'^([a-z]+(?:_[a-z0-9]+)+) (.+)$')
for page, s in lines:
    if page < 267 or page > 335:
        continue
    m = re.match(r'^Servizio: (.+)$', s)
    if m:
        sez = {'titolo': m.group(1), 'descrizione': []}; gruppo = []; state = 'intro'; continue
    if sez is None:
        continue
    nuovo = lambda cmd, titolo: {'cmd': cmd, 'titolo': titolo, 'descrizione': ' '.join(sez['descrizione']).strip(),
                                 'pagina': page, 'parametri': [], 'risposta': [], 'note': []}
    m = re.match(r'^Comando: (\S+)', s)
    if m:
        gruppo = [nuovo(m.group(1), sez['titolo'])]
        servizi.extend(gruppo); state = None; continue
    # Più comandi con gli stessi parametri (stampe PDF, inserimento righe).
    if s.startswith('Comandi disponibili'):
        gruppo = []; state = 'comandi'; continue
    if state == 'comandi':
        r = cmd_re.match(s)
        if r:
            gruppo.append(nuovo(r.group(1), sez['titolo'] + ' - ' + r.group(2)))
            servizi.append(gruppo[-1])
            continue
        if s.startswith('Comando '):
            continue
        state = None
    if state == 'intro' and not gruppo:
        if s and not s.startswith(('Metodo', 'URL')):
            sez['descrizione'].append(s)
        continue
    if not gruppo:
        continue
    if s.startswith('Campo Tipo Obbl'):
        state = 'params'; continue
    if s.startswith(('Campi della Response', 'Campi Response')):
        state = 'resp_wait'; continue
    if state == 'resp_wait' and s.startswith('Campo Tipo'):
        state = 'resp'; continue
    if s.startswith('Note Operative'):
        state = 'notes'; continue
    for cur in gruppo:
        if state == 'params':
            r = sparam_re.match(s)
            if r:
                cur['parametri'].append({'campo': r.group(1), 'tipo': r.group(2), 'obbligatorio': r.group(3) in ('Sì', 'Si'),
                                         'descrizione': r.group(4).strip()})
            elif not s or s.startswith(('Esempio', 'ℹ', '⚠', 'Campi obbligatori', 'Logica', 'I seguenti')):
                pass
            elif cur['parametri']:
                cur['parametri'][-1]['descrizione'] = (cur['parametri'][-1]['descrizione'] + ' ' + s).strip()
        elif state == 'resp':
            r = sresp_re.match(s)
            if r:
                cur['risposta'].append({'campo': r.group(1), 'tipo': r.group(2), 'descrizione': r.group(3).strip()})
            elif s and not s.startswith(('Esempio', 'ℹ', '⚠', 'Note')) and cur['risposta']:
                cur['risposta'][-1]['descrizione'] = (cur['risposta'][-1]['descrizione'] + ' ' + s).strip()
        elif state == 'notes':
            if s.startswith('•'):
                cur['note'].append(s.lstrip('• ').strip())
            elif s and cur['note'] and s[0].islower():
                cur['note'][-1] += ' ' + s
    if state == 'params' and (not s or s.startswith(('Esempio', 'ℹ', '⚠', 'Campi obbligatori', 'Logica', 'I seguenti'))):
        state = None
    elif state == 'resp' and (not s or s.startswith(('Esempio', 'ℹ', '⚠', 'Note'))):
        state = None
    elif state == 'notes' and not (s.startswith('•') or (s and s[0].islower())):
        state = None

for sv in servizi:
    for key, campo in (('parametri', 'campo'), ('risposta', 'campo')):
        seen, uniq = set(), []
        for x in sv[key]:
            if x[campo] not in seen:
                seen.add(x[campo]); uniq.append(x)
        sv[key] = uniq
    sv['descrizione'] = re.sub(r'\s+', ' ', sv['descrizione'])[:600]

out = {
    'sorgente': 'Manuale WebAPI Passepartout v3.1 (10 luglio 2026)',
    'operazioni': ops,
    'strutture': strutture,
    'servizi': servizi,
}
json.dump(out, open(sys.argv[2], 'w'), indent=1, ensure_ascii=False)
print(len(ops), 'operazioni;', sum(len(v) for v in strutture.values()), 'campi struttura;', len(servizi), 'servizi')
for k, v in strutture.items():
    print(' ', k, len(v), [f['nome'] for f in v][:8])
for sv in servizi:
    print(' ', sv['cmd'], len(sv['parametri']), 'param', len(sv['risposta']), 'resp |', sv['titolo'])
