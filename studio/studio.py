#!/usr/bin/env python3
"""Souss Actualités — Studio vidéo.
Article (lien ou texte) + médias facultatifs -> vidéo verticale 1080x1920 avec voix, musique, sous-titres.
Usage: python3 studio.py <dossier_job>   (contient job.json et éventuellement media/)
"""
import json, os, re, sys, html, subprocess, textwrap, random, math, urllib.parse
from pathlib import Path
import requests

HERE = Path(__file__).resolve().parent
JOB = Path(sys.argv[1]).resolve()
OUT = JOB / "out"; OUT.mkdir(exist_ok=True)
IMGDIR = OUT / "imgs"; IMGDIR.mkdir(exist_ok=True)
CFG = json.load(open(JOB / "job.json"))
UA = {"User-Agent": "SoussActualitesStudio/1.0 (contact: souss-actualites.com)"}
LOG = []
def log(*a):
    s = " ".join(str(x) for x in a); print(s, flush=True); LOG.append(s)

# ------------------------------------------------------------------ 1. article
def clean(t):
    t = html.unescape(re.sub(r"<[^>]+>", " ", t))
    t = re.sub(r"\s+([,.])", r"\1", t)
    t = re.sub(r"[ \t ]+", " ", t)
    return t

def from_url(url):
    """Lit un article WordPress via l'API REST (titre, texte, photos)."""
    p = urllib.parse.urlparse(url)
    slug = [s for s in p.path.split("/") if s][-1]
    slug = urllib.parse.unquote(slug)
    api = f"{p.scheme}://{p.netloc}/wp-json/wp/v2/posts"
    r = requests.get(api, params={"slug": slug, "_embed": "1"}, headers=UA, timeout=30)
    posts = r.json() if r.ok else []
    if not posts:  # repli: page HTML
        h = requests.get(url, headers=UA, timeout=30).text
        title = clean(re.search(r"<h1[^>]*>(.*?)</h1>", h, re.S).group(1))
        body = re.search(r"<main.*?</main>", h, re.S)
        body = body.group(0) if body else h
        imgs = re.findall(r'<img[^>]+src="([^"]+\.(?:jpe?g|png|webp))"', body)
        return title, body, imgs
    post = posts[0]
    title = clean(post["title"]["rendered"])
    content = post["content"]["rendered"]
    imgs = []
    try:
        imgs.append(post["_embedded"]["wp:featuredmedia"][0]["source_url"])
    except Exception:
        pass
    imgs += re.findall(r'<img[^>]+src="([^"]+)"', content)
    global WP_CATS, WP_AUTHOR
    try:
        WP_AUTHOR = clean(post["_embedded"]["author"][0]["name"])
    except Exception:
        WP_AUTHOR = ""
    try:
        WP_CATS = [clean(t["name"]) for t in post["_embedded"]["wp:term"][0]]
    except Exception:
        WP_CATS = []
    return title, content, imgs

def paragraphs(content_html):
    parts = re.split(r"</p>|<br\s*/?>|</h[1-6]>|</li>|\n\n", content_html)
    out = []
    for p in parts:
        t = clean(p).strip()
        if not t or re.search(r"(Écouter l.article|Partager sur|^Hicham Jikh Cheddad$)", t):
            continue
        out.append(t)
    return out

WP_CATS, WP_AUTHOR = [], ""
if CFG.get("url"):
    TITLE, CONTENT, ARTICLE_IMGS = from_url(CFG["url"].strip())
    PARAS = paragraphs(CONTENT)
else:
    raw = CFG.get("text", "").strip()
    lines = [l.strip() for l in raw.splitlines() if l.strip()]
    TITLE = CFG.get("title") or (lines[0] if lines else "Souss Actualités")
    PARAS = lines[1:] if not CFG.get("title") else lines
    ARTICLE_IMGS = []
if CFG.get("title"):
    TITLE = CFG["title"]
TITLE = TITLE.strip().rstrip(".…").strip()
_letters = [c for c in TITLE if c.isalpha()]
if _letters and sum(c.isupper() for c in _letters) / len(_letters) > 0.7:
    TITLE = TITLE[0] + TITLE[1:].lower()
    PROPER = ["Maroc", "Afrique", "Agadir", "Souss", "Massa", "Atlas", "Lions", "Fifa", "Caf", "Rabat", "Casablanca",
              "Marrakech", "Tanger", "Fès", "Taroudant", "Tiznit", "Inezgane", "Aït", "Melloul", "Chtouka", "Tata", "Essaouira",
              "Taghazout", "Oufella", "Hassania", "Brésil", "Portugal", "Espagne", "France", "Europe", "Mohammed", "Royaume",
              "Akhannouch", "Sahara", "Dakhla", "Laâyoune", "Guelmim", "Ouarzazate", "Qatar"]
    for w in PROPER:
        TITLE = re.sub(r"\b" + w.lower() + r"\b", w, TITLE)
_sample = TITLE + " " + " ".join(PARAS[:5])
_ar = len(re.findall(r"[\u0600-\u06FF]", _sample)); _lat = len(re.findall(r"[A-Za-zÀ-ÿ]", _sample))
LANG = CFG.get("langue", "auto")
if LANG not in ("fr", "ar"): LANG = "ar" if _ar > _lat else "fr"
AR = LANG == "ar"
log("Langue:", LANG)
BYLINE = WP_AUTHOR or CFG.get("byline") or "Hicham Jikh Cheddad"
if AR and BYLINE.strip().lower() in ("hicham jikh cheddad", "hicham jikh"): BYLINE = "هشام جيخ شداد"
CAT_AR = {"économie": "اقتصاد", "sport": "رياضة", "politique": "سياسة", "société": "مجتمع", "culture": "ثقافة",
          "international": "دولي", "actualités": "مستجدات", "actualité": "مستجدات", "l'actu du jour": "أخبار اليوم",
          "tourisme": "سياحة", "business": "أعمال", "tendances": "توجهات", "enquêtes": "تحقيقات", "opinion": "رأي"}
if (not CFG.get("rubrique") or CFG.get("rubrique", "").strip().lower() in ("actualité", "actualité · agadir")) and WP_CATS:
    CFG["rubrique"] = WP_CATS[0]
if AR:
    rub = (CFG.get("rubrique") or "").strip()
    CFG["rubrique"] = CAT_AR.get(rub.lower().replace("’", "'"), rub if re.search(r"[\u0600-\u06FF]", rub) else "أخبار")
log("Rubrique:", CFG.get("rubrique"), "| catégories WP:", WP_CATS)
log("Titre:", TITLE, "| paragraphes:", len(PARAS), "| images article:", len(ARTICLE_IMGS))

# ------------------------------------------------------------------ 2. script
def sentences(text):
    if re.search(r"[\u0600-\u06FF]", text):
        s = re.split(r"(?<=[.!?…؟])\s+", text)
        return [x.strip() for x in s if len(x.strip()) > 1]
    s = re.split(r"(?<=[.!?…»])\s+(?=[A-ZÀ-ÖØ-Ý«\"0-9])", text)
    return [x.strip() for x in s if len(x.strip()) > 1]

SOCIAL = None
def claude_summary():
    key = os.environ.get("ANTHROPIC_API_KEY")
    if not key:
        return None
    art = "\n\n".join(PARAS)[:20000]
    prompt = (
        "Tu es le rédacteur vidéo de Souss Actualités. À partir de l'article ci-dessous, écris le texte "
        "d'une vidéo verticale d'environ 60 secondes (140 à 160 mots), en français, fidèle à l'article "
        "(aucun fait ajouté, déclarations attribuées, hypothèses présentées comme telles). Phrases courtes, rythme journalistique, "
        "première phrase accrocheuse. Réponds UNIQUEMENT en JSON: "
        '{"lines": ["phrase 1", "phrase 2", ...], "description": "texte pour réseaux sociaux (2 phrases)", '
        '"hashtags": ["#..."], "keywords_images": ["mots-clés en anglais ou français pour chercher des photos de lieux, sans personnes"]}'
        f"\n\nTITRE: {TITLE}\n\nARTICLE:\n{art}")
    try:
        r = requests.post("https://api.anthropic.com/v1/messages", timeout=120, headers={
            "x-api-key": key, "anthropic-version": "2023-06-01", "content-type": "application/json"},
            json={"model": os.environ.get("CLAUDE_MODEL", "claude-sonnet-5-5"), "max_tokens": 2000,
                  "messages": [{"role": "user", "content": prompt}]})
        txt = r.json()["content"][0]["text"]
        data = json.loads(txt[txt.find("{"): txt.rfind("}") + 1])
        log("Résumé: Claude API OK")
        return data
    except Exception as e:
        log("Résumé Claude indisponible:", e)
        return None

mode = CFG.get("mode", "resume")
if mode == "complet":
    LINES = [s for p in PARAS for s in sentences(p)]
else:
    data = claude_summary()
    if data:
        LINES = data["lines"]; SOCIAL = data
    else:  # résumé extractif: chapeau + première phrase de chaque intertitre/paragraphe
        LINES, words = [], 0
        for i, p in enumerate(PARAS):
            ss = sentences(p)
            if not ss: continue
            take = ss[:2] if i < 2 else ss[:1]
            for s in take:
                if words > int(CFG.get("words", 70 if AR else 150)): break
                if len(s.split()) < 4 and i > 0: continue
                LINES.append(s); words += len(s.split())
        log("Résumé extractif:", words, "mots")
LINES = [TITLE + ("" if AR else ".")] + [l for l in LINES if l.strip() and l.strip().rstrip(".…") != TITLE and not (mode != "complet" and re.search(r"[\u1E00-\u1EFF\u2D30-\u2D7F]" if AR else r"[\u1E00-\u1EFF\u2D30-\u2D7F\u0600-\u06FF]", l))]

# --- prononciation
from num2words import num2words
FIX = {
    r"\bMohammed VI\b": "Mohamède six", r"\bMohammed V\b": "Mohamède cinq", r"\bHassan II\b": "Hassane deux",
    r"\bMohammed\b": "Mohamède", r"\bMohamed\b": "Mohamède", r"\bFIFA\b": "Fifa", r"\b[Ff]ootball": "foutbol", r"\b[Ff]ootballeur": "foutboleur", r"\bCAF\b": "Caf",
    r"\bCAN\b": "la Can", r"\bONU\b": "O N U", r"\bMMDH\b": "milliards de dirhams", r"\bMDH\b": "millions de dirhams", r"\bMAD\b": "dirhams", r"\bDH\b": "dirhams", r"\bdhs?\b": "dirhams",
    r"\bkm\b": "kilomètres", r"\bM€": "millions d'euros", r"%": " pour cent", r"\bSouss-Massa\b": "Souss Massa",
    r"\bAït\b": "Aït", r"\bOufella\b": "Oufella", r"\bGadiris\b": "Gadiris",
}
FIX.update(CFG.get("prononciation", {}))
def spoken_ar(s):
    t = re.sub(r"\d+(?:[.,]\d+)?", lambda m: num2words(float(m.group(0).replace(",", ".")) if re.search(r"[.,]", m.group(0)) else int(m.group(0)), lang="ar"), s)
    t = t.replace("«", "").replace("»", "").replace("…", "،").replace("—", "،").replace("–", "،")
    return re.sub(r"\s+", " ", t).strip()
def spoken(s):
    if AR: return spoken_ar(s)
    t = s
    for a, b in FIX.items(): t = re.sub(a, b, t)
    t = re.sub(r"(\d+)\s*(?:ᵉ|e|ème|eme)\b", lambda m: num2words(int(m.group(1)), lang="fr", to="ordinal"), t)
    t = re.sub(r"(\d+)\s*(?:er|re)\b", lambda m: "premier" if m.group(1) == "1" else num2words(int(m.group(1)), lang="fr", to="ordinal"), t)
    def num(m):
        x = m.group(0).replace(" ", "").replace(" ", "").replace(" ", "")
        if "," in x:
            a, b = x.split(",", 1); return num2words(int(a), lang="fr") + " virgule " + num2words(int(b), lang="fr")
        return num2words(int(x), lang="fr")
    t = re.sub(r"\d{1,3}(?:[   ]\d{3})+(?:,\d+)?|\d+(?:,\d+)?", num, t)
    t = t.replace("«", "").replace("»", "").replace("…", ".").replace("—", ",").replace("–", ",")
    return re.sub(r"\s+", " ", t).strip()

# ------------------------------------------------------------------ 3. voix (Kokoro)
import numpy as np, soundfile as sf
KD = os.environ.get("KOKORO_DIR", str(HERE / "models"))
speed = float(CFG.get("vitesse", 1.05))
if AR:
    import wave, io
    from piper import PiperVoice
    from piper.config import SynthesisConfig
    pv = PiperVoice.load(f"{KD}/ar_JO-kareem-medium.onnx")
    def say(txt):
        buf = io.BytesIO()
        with wave.open(buf, "wb") as wf:
            pv.synthesize_wav(txt, wf, syn_config=SynthesisConfig(length_scale=0.88 / speed))
        buf.seek(0); a, r = sf.read(buf, dtype="float32"); return a, r
else:
    from kokoro_onnx import Kokoro
    kok = Kokoro(f"{KD}/kokoro-v1.0.onnx", f"{KD}/voices-v1.0.bin")
    def say(txt):
        if "(en)" in kok.tokenizer.phonemize(txt, "fr-fr"): log("Attention prononciation anglaise:", txt)
        return kok.create(txt, voice=CFG.get("voix", "ff_siwis"), speed=speed, lang="fr-fr")
segs, audio, t, sr = [], [], 0.0, 24000
for line in LINES:
    sp = spoken(line)
    a, sr = say(sp)
    gap = np.zeros(int(sr * 0.32), dtype=a.dtype)
    segs.append({"text": line, "start": t, "end": t + len(a) / sr})
    t += len(a) / sr + 0.32; audio += [a, gap]
sf.write(OUT / "voice.wav", np.concatenate(audio), sr)
VOICE_DUR = t
log("Voix:", round(VOICE_DUR, 1), "s,", len(segs), "phrases")

# ------------------------------------------------------------------ 4. images / vidéos
MEDIA, CREDITS = [], []
def dl(url, name):
    try:
        r = requests.get(url, headers=UA, timeout=60)
        if r.ok and len(r.content) > 20000:
            p = IMGDIR / name; p.write_bytes(r.content); return p
    except Exception as e:
        log("dl échoué", url, e)

# a) médias fournis par l'utilisateur
mdir = JOB / "media"
if mdir.exists():
    for f in sorted(mdir.iterdir()):
        if f.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp", ".mp4", ".mov", ".m4v", ".webm"):
            MEDIA.append(f)
log("Médias utilisateur:", len(MEDIA))
ONLY_MINE = bool(MEDIA) or CFG.get("seulement_mes_photos", False)
if ONLY_MINE:
    CFG["photos_article"] = False; CFG["commons"] = False
    log("Mode : uniquement les photos fournies")
# b) photos de l'article
if CFG.get("photos_article", True):
    seen = set()
    for i, u in enumerate(ARTICLE_IMGS):
        base = re.sub(r"-\d+x\d+(?=\.\w+$)", "", u)
        if base in seen or "logo" in base.lower() or base.endswith(".svg"): continue
        seen.add(base)
        p = dl(base, f"article_{i}{Path(urllib.parse.urlparse(base).path).suffix or '.jpg'}") or dl(u, f"article_{i}.jpg")
        if p: MEDIA.append(p)
    log("Photos de l'article:", len([m for m in MEDIA if "article_" in str(m)]))
# b2) banque de photos vérifiées
TEXT_ALL = (TITLE + " " + " ".join(PARAS)).lower()
THEMES = []
if re.search(r"football|lions de l.atlas|stade|fifa|caf\b|coupe du monde|can 20|botola|hassania|sélection nationale", TEXT_ALL): THEMES.append("football")
if re.search(r"agadir|inezgane|aït melloul|taghazout|oufella", TITLE.lower() + " " + " ".join(PARAS[:3]).lower()): THEMES.append("agadir")
for th in ([] if ONLY_MINE else THEMES + CFG.get("banques", [])):
    bd = HERE / "banque" / th
    if not bd.exists(): continue
    cr = json.load(open(bd / "credits.json"))
    files = sorted(bd.glob("*.jpg")); random.Random(TITLE).shuffle(files)
    for f in files:
        if len(MEDIA) >= max(int(VOICE_DUR / float(CFG.get("sec_par_image", 6.5))) + 1, 4): break
        MEDIA.append(f); CREDITS.append(cr.get(f.name, ""))
log("Banque:", THEMES)
# c) Wikimedia Commons (photos réelles, libres) pour compléter
need = max(int(VOICE_DUR / float(CFG.get("sec_par_image", 6.5))) + 1, 4)
PLACES = ["Agadir", "Inezgane", "Aït Melloul", "Taroudant", "Tiznit", "Chtouka", "Tata", "Essaouira", "Guelmim", "Taghazout",
          "Tafraout", "Sidi Ifni", "Rabat", "Casablanca", "Marrakech", "Tanger", "Fès", "Fes", "Laâyoune", "Dakhla", "Ouarzazate", "Souss"]
TOPICS = {"stade": "stadium", "football": "stadium", "lions de l'atlas": "stadium", "plage": "beach", "port": "port",
          "marina": "marina", "souk": "souk", "kasbah": "kasbah", "oufella": "Agadir Oufella", "argan": "argan",
          "aéroport": "airport", "université": "university", "hôpital": "hospital", "tramway": "tramway",
          "agricult": "agriculture", "pêche": "fishing port", "tourisme": "beach", "mosquée": "mosque",
          "école": "school", "route": "road", "autoroute": "motorway", "barrage": "dam", "eau": "dam",
          "pluie": "rain", "neige": "snow mountains", "séisme": "Atlas mountains", "train": "train station"}
def commons_queries():
    if SOCIAL and SOCIAL.get("keywords_images"):
        return SOCIAL["keywords_images"]
    full = (TITLE + " " + " ".join(PARAS)).lower()
    places = [p for p in PLACES if p.lower() in full] or ["Agadir"]
    topics = [v for k, v in TOPICS.items() if k in full]
    q = []
    for p in places[:3]:
        for tp in (topics[:2] or [""]):
            q.append((p + " " + tp).strip())
        q.append(p)
    if "maroc" in full and not q: q.append("Morocco")
    return q + CFG.get("mots_images", [])

CATS = {"agadir": ["Views of Agadir", "Agadir Oufella", "Beaches of Agadir", "Marina of Agadir"],
        "taroudant": ["Taroudant", "Walls of Taroudant"], "tiznit": ["Tiznit"], "inezgane": ["Inezgane"],
        "essaouira": ["Essaouira", "Medina of Essaouira"], "taghazout": ["Taghazout"], "tafraout": ["Tafraout"],
        "rabat": ["Views of Rabat", "Kasbah of the Udayas"], "casablanca": ["Views of Casablanca", "Hassan II Mosque"],
        "marrakech": ["Views of Marrakesh", "Jemaa el-Fnaa"], "tanger": ["Views of Tangier"], "fès": ["Views of Fez"],
        "dakhla": ["Dakhla"], "laâyoune": ["Laayoune"], "ouarzazate": ["Ouarzazate"], "tata": ["Tata Province"],
        "maroc": ["Landscapes of Morocco", "Atlas Mountains"]}
BAD = r"portrait|player|joueur|minist|presiden|président|selfie|logo|map|carte|flag|drapeau|soldier|soldat|army|armée|police|militar|weapon|gun|protest|manif|accident|fire|incendie|people|person|man |woman|girl|boy|face"
def commons_cat(cat, limit):
    try:
        r = requests.get("https://commons.wikimedia.org/w/api.php", headers=UA, timeout=30, params={
            "action": "query", "generator": "categorymembers", "gcmtitle": "Category:" + cat, "gcmtype": "file", "gcmlimit": 80,
            "prop": "imageinfo", "iiprop": "url|size|extmetadata", "iiurlwidth": 1600, "format": "json"}).json()
    except Exception as e:
        log("Commons erreur", e); return []
    out = []
    for pg in (r.get("query") or {}).get("pages", {}).values():
        if not pg.get("imageinfo"): continue
        ii = pg["imageinfo"][0]; md = ii.get("extmetadata", {})
        lic = md.get("LicenseShortName", {}).get("value", "")
        desc = (pg["title"] + " " + re.sub(r"<[^>]+>", " ", md.get("ImageDescription", {}).get("value", ""))).lower()
        if ii["width"] < 1400 or ii["width"] < ii["height"] * 1.3: continue
        if not re.search(r"cc|public domain|pd", lic, re.I) or re.search(BAD, desc): continue
        artist = re.sub(r"<[^>]+>", "", md.get("Artist", {}).get("value", "")).strip()[:40]
        out.append((ii["thumburl"], f"{artist} ({lic})"))
    random.Random(cat).shuffle(out)
    return out[:limit]
KEYMAP = {"puce": "microchip", "cerveau": "brain", "pensée": "brain", "neurone": "neuron", "implant": "brain implant",
    "intelligence artificielle": "artificial intelligence", "ia": "artificial intelligence", "robot": "robot", "ordinateur": "computer",
    "smartphone": "smartphone", "internet": "data center", "numérique": "data center", "satellite": "satellite", "espace": "space",
    "santé": "hospital", "hôpital": "hospital", "médecin": "hospital", "maladie": "laboratory", "vaccin": "vaccine", "science": "laboratory",
    "recherche": "laboratory", "étude": "laboratory", "école": "school classroom", "université": "university campus", "élèves": "school classroom",
    "économie": "business district", "banque": "bank building", "bourse": "stock exchange", "prix": "market stall", "inflation": "market stall",
    "agriculture": "agriculture field", "agricole": "agriculture field", "argan": "argan tree", "pêche": "fishing boats", "eau": "dam reservoir",
    "sécheresse": "drought", "pluie": "rain", "climat": "climate", "énergie": "solar power plant", "solaire": "solar power plant",
    "éolien": "wind farm", "pétrole": "oil refinery", "gaz": "gas pipeline", "tourisme": "beach resort", "hôtel": "hotel",
    "aéroport": "airport", "avion": "airplane", "train": "train", "tramway": "tramway", "route": "highway", "autoroute": "highway",
    "port": "port", "voiture": "traffic", "élection": "ballot box", "parlement": "parliament building", "justice": "courthouse",
    "tribunal": "courthouse", "police": "police car", "mosquée": "mosque", "ramadan": "mosque", "culture": "museum", "musique": "concert",
    "festival": "festival", "cinéma": "cinema", "livre": "library", "football": "stadium", "stade": "stadium", "sport": "stadium",
    "athlétisme": "athletics track", "basket": "basketball court", "tennis": "tennis court", "immobilier": "apartment buildings",
    "logement": "apartment buildings", "construction": "construction site", "chantier": "construction site", "séisme": "earthquake damage",
    "incendie": "forest fire", "mer": "sea", "océan": "ocean", "plage": "beach", "montagne": "mountains", "désert": "desert",
    "maroc": "Morocco", "afrique": "Africa", "europe": "Europe", "france": "Paris", "espagne": "Spain", "migration": "border",
    "téléphone": "smartphone", "réseaux sociaux": "smartphone", "jeunes": "university students", "femmes": "conference",
    "emploi": "office", "entreprise": "office building", "industrie": "factory", "usine": "factory", "commerce": "market",
    "souk": "souk", "artisanat": "handicraft", "cuisine": "Moroccan cuisine", "thé": "Moroccan tea", "désinformation": "newspaper",
    "presse": "newspaper", "médias": "newspaper", "technologie": "technology", "innovation": "technology", "données": "data center"}
STOP = set("le la les un une des du de d l et ou à au aux en dans sur pour par avec sans sous plus moins très est sont été être a ont "
           "ce cette ces qui que quoi dont où se sa son ses leur leurs nos notre vos votre il elle ils elles on nous vous si ne pas "
           "et-si comme mais donc car ni tout tous toute toutes après avant entre depuis chez quand comment pourquoi".split())
def topic_queries():
    if SOCIAL and SOCIAL.get("keywords_images"):
        return SOCIAL["keywords_images"]
    head = (TITLE + " " + " ".join(PARAS[:4])).lower()
    found = []
    tl = TITLE.lower()
    def pos(k):
        m = re.search(r"\b" + re.escape(k) + r"s?\b", tl)
        if m: return m.start()
        m = re.search(r"\b" + re.escape(k) + r"s?\b", head)
        return 1000 + m.start() if m else None
    hits = sorted((pos(k), k) for k in KEYMAP if pos(k) is not None)
    for _, k in hits:
        if KEYMAP[k] not in found: found.append(KEYMAP[k])
    title_words = [w for w in re.findall(r"[a-zà-ÿ]{5,}", TITLE.lower()) if w not in STOP]
    qs = []
    if len(found) >= 2: qs.append(found[0] + " " + found[1])
    qs += found[:5]
    if len(found) < 2: qs += [w for w in title_words if w not in KEYMAP][:2]
    places = [k for k in CATS if k in head and k != "maroc"]
    return qs, places
def commons_search(q, limit):
    try:
        r = requests.get("https://commons.wikimedia.org/w/api.php", headers=UA, timeout=30, params={
            "action": "query", "generator": "search", "gsrsearch": q + " filetype:bitmap", "gsrnamespace": 6, "gsrlimit": 40,
            "prop": "imageinfo", "iiprop": "url|size|extmetadata", "iiurlwidth": 1600, "format": "json"}).json()
    except Exception as e:
        log("Commons erreur", e); return []
    out = []
    for pg in sorted((r.get("query") or {}).get("pages", {}).values(), key=lambda p: p.get("index", 99)):
        if not pg.get("imageinfo"): continue
        ii = pg["imageinfo"][0]; md = ii.get("extmetadata", {})
        lic = md.get("LicenseShortName", {}).get("value", "")
        desc = (pg["title"] + " " + re.sub(r"<[^>]+>", " ", md.get("ImageDescription", {}).get("value", ""))).lower()
        if ii["width"] < 1400 or ii["width"] < ii["height"] * 1.3: continue
        if not re.search(r"cc|public domain|pd", lic, re.I) or re.search(BAD, desc): continue
        words = [w for w in re.findall(r"[a-zà-ÿ]{4,}", q.lower())]
        if words and not any(w in desc for w in words): continue
        artist = re.sub(r"<[^>]+>", "", md.get("Artist", {}).get("value", "")).strip()[:40]
        out.append((ii["thumburl"], f"{artist} ({lic})"))
        if len(out) >= limit: break
    return out
if len(MEDIA) < need and CFG.get("commons", True):
    got = 0
    qs, places = topic_queries()
    log("Recherche photos:", qs, "| lieux:", places)
    sources_list = [("s", q) for q in qs] + [("c", c) for k in places[:2] for c in CATS[k]]
    for kind, q in sources_list:
        if len(MEDIA) >= need: break
        res = commons_search(q, 3) if kind == "s" else commons_cat(q, 2)
        for url, cr in res:
            if len(MEDIA) >= need: break
            p = dl(url, f"commons_{got}.jpg")
            if p: MEDIA.append(p); CREDITS.append(cr); got += 1
    log("Photos Commons:", got)
if not MEDIA:
    raise SystemExit("Aucune image trouvée: ajoutez des photos.")

# ------------------------------------------------------------------ 5. musique
if CFG.get("musique", "elan") != "aucune":
    subprocess.run([sys.executable, str(HERE / "music.py"), str(OUT / "music.wav"), str(VOICE_DUR + 6.0), CFG.get("musique", "elan")], check=True)

# ------------------------------------------------------------------ 6. montage
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageEnhance
W, H, FPS = 1080, 1920, 25
F = HERE / "fonts"
ACCENT = tuple(CFG.get("couleur", [230, 120, 30]))
def font(n, s, w=None):
    f = ImageFont.truetype(str(F / n), s)
    if w:
        try: f.set_variation_by_axes([w])
        except Exception: pass
    return f
F_BRAND, F_KICK = font("Inter-Black.otf", 46), font("Inter-SemiBold.otf", 30)
F_TITLE, F_SUB = font("Lora-Variable.ttf", 54, 700), font("Inter-Bold.otf", 60)
F_URL, F_SMALL = font("Inter-SemiBold.otf", 32), font("Inter-Medium.otf", 24)
from PIL import features
RAQM = features.check("raqm")
def arfont(size, naskh=False):
    import glob
    pats = ["/usr/share/fonts/truetype/noto/NotoNaskhArabic-Bold.ttf"] if naskh else []
    pats += ["/usr/share/fonts/truetype/noto/NotoSansArabic-Bold.ttf", "/usr/share/fonts/truetype/noto/NotoKufiArabic-Bold.ttf"]
    pats += glob.glob("/usr/share/fonts/**/*Arabic*Bold*.ttf", recursive=True)
    for p_ in pats:
        if os.path.exists(p_):
            return ImageFont.truetype(p_, size, layout_engine=ImageFont.Layout.RAQM if RAQM else ImageFont.Layout.BASIC)
    raise SystemExit("Police arabe introuvable")
def shape(txt):
    if RAQM or not re.search(r"[\u0600-\u06FF]", txt): return txt
    import arabic_reshaper
    from bidi.algorithm import get_display
    return get_display(arabic_reshaper.reshape(txt))
def tlen(d_, txt, f):
    return d_.textlength(shape(txt), font=f, direction="rtl") if (RAQM and re.search(r"[\u0600-\u06FF]", txt)) else d_.textlength(shape(txt), font=f)
def T(d_, xy, txt, f, fill):
    if RAQM and re.search(r"[\u0600-\u06FF]", txt): d_.text(xy, txt, font=f, fill=fill, direction="rtl", language="ar")
    else: d_.text(xy, shape(txt), font=f, fill=fill)
if AR:
    F_BRAND, F_KICK, F_TITLE, F_SUB = arfont(52), arfont(32), arfont(56, True), arfont(62)
    F_SMALL = arfont(26)
log("Moteur texte RAQM:", RAQM)
TITLE_SHOW = TITLE.replace("ᵉ", "e")
OUTRO = 3.5
DUR = VOICE_DUR + 0.5 + OUTRO
NF = int(DUR * FPS)

chunks = []
for s in segs:
    words = s["text"].split(); groups, cur = [], []
    for w in words:
        cur.append(w)
        if len(" ".join(cur)) > 38 and len(cur) >= 4: groups.append(cur); cur = []
    if cur:
        if groups and len(cur) <= 2: groups[-1] += cur
        else: groups.append(cur)
    n = sum(len(" ".join(g)) for g in groups); t0 = s["start"]; d = s["end"] - s["start"]
    for g in groups:
        tx = " ".join(g); dd = d * len(tx) / n; chunks.append((t0, t0 + dd, tx)); t0 += dd

def text_layer(txt):
    lay = Image.new("RGBA", (W, 360), (0, 0, 0, 0)); d = ImageDraw.Draw(lay)
    lines = textwrap.wrap(txt, 26)
    if len(lines) > 1 and len(lines[-1]) < 10: lines = textwrap.wrap(txt, max(14, len(txt) // len(lines) + 4))
    lh = 90 if AR else 76; y0 = (360 - lh * len(lines)) // 2
    if FULL:
        bw = max(tlen(d, l, F_SUB) for l in lines) + 70
        d.rounded_rectangle(((W - bw) / 2, y0 - 22, (W + bw) / 2, y0 + lh * len(lines) + 18), radius=22, fill=(0, 0, 0, 150))
    for i, l in enumerate(lines):
        w = tlen(d, l, F_SUB); x = (W - w) / 2; y = y0 + i * lh
        for dx, dy in ((0, 4), (2, 4), (-2, 4)): T(d, (x + dx, y + dy), l, F_SUB, (0, 0, 0, 160))
        T(d, (x, y), l, F_SUB, "white")
    return lay

PH_W, PH_H, PH_Y = 1080, 640, 600
FULL = CFG.get("plein_ecran", True)
def ease(p): return 0.5 - 0.5 * math.cos(math.pi * min(max(p, 0), 1))
class Still:
    """Photo plein écran avec mouvement « Ken Burns » bien visible."""
    def __init__(self, path):
        im = Image.open(path).convert("RGB")
        sc = max(W / im.width, H / im.height)
        if sc > 1:  # agrandir une seule fois en bonne qualité
            im = im.resize((int(im.width * sc * 1.0) + 1, int(im.height * sc) + 1), Image.LANCZOS)
        self.im = im
        self.land = im.width / im.height > W / H * 1.15
    def frame(self, p, idx):
        im = self.im; e = ease(p); ar = W / H
        if self.land:   # photo horizontale : traversée de gauche à droite (ou l'inverse) + léger zoom
            z = 1.0 + 0.10 * e
            ch = im.height / z; cw = ch * ar
            span = im.width - cw
            x0 = span * (e if idx % 2 == 0 else 1 - e)
            y0 = (im.height - ch) / 2
        else:           # photo verticale : zoom avant/arrière marqué + petite dérive
            zmax = 1.32
            z = 1.0 + (zmax - 1.0) * (e if idx % 2 == 0 else 1 - e)
            cw = min(im.width, im.height * ar) / z; ch = cw / ar
            dx, dy = [(0.5, 0.35), (0.5, 0.65), (0.35, 0.5), (0.65, 0.5)][idx % 4]
            x0 = (im.width - cw) * (0.5 + (dx - 0.5) * e)
            y0 = (im.height - ch) * (0.5 + (dy - 0.5) * e)
        return im.resize((W, H), Image.BILINEAR, box=(x0, y0, x0 + cw, y0 + ch))

class Clip:
    """Vidéo fournie: plein écran (recadrée), sans son."""
    def __init__(self, path, seconds):
        cmd = ["ffmpeg", "-loglevel", "error", "-stream_loop", "-1", "-i", str(path), "-t", str(seconds + 0.2), "-r", str(FPS),
               "-vf", f"scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H}", "-f", "rawvideo", "-pix_fmt", "rgb24", "-"]
        self.proc = subprocess.Popen(cmd, stdout=subprocess.PIPE); self.last = Image.new("RGB", (W, H))
    def frame(self, p, idx):
        raw = self.proc.stdout.read(W * H * 3)
        if len(raw) == W * H * 3: self.last = Image.frombytes("RGB", (W, H), raw)
        return self.last.copy()

VIDEXT = (".mp4", ".mov", ".m4v", ".webm")
order = list(MEDIA)
slots = max(len(order), int((VOICE_DUR + 1) / float(CFG.get("sec_par_image", 6.5))))
seq = order if ONLY_MINE else ([order[i % len(order)] for i in range(slots)] if len(order) < slots else order)
per = (VOICE_DUR + 1.0) / len(seq)
sources = {}
def src(i):
    if i not in sources:
        p = Path(seq[i])
        try:
            sources[i] = Clip(p, per + 1) if p.suffix.lower() in VIDEXT else Still(p)
        except Exception as e:
            log("média illisible", p, e); sources[i] = Still(next(x for x in seq if Path(x).suffix.lower() not in VIDEXT))
        for k in list(sources):
            if k < i - 1: del sources[k]
    return sources[i]

static = Image.new("RGBA", (W, H), (0, 0, 0, 0)); d = ImageDraw.Draw(static)
if AR:
    d.rectangle((W - 82, 150, W - 70, 222), fill=ACCENT)
    b = "أخبار سوس"; T(d, (W - 104 - tlen(d, b, F_BRAND), 140), b, F_BRAND, "white")
    k = CFG.get("rubrique") or "أخبار"; T(d, (W - 106 - tlen(d, k, F_KICK), 205), k, F_KICK, ACCENT)
    y = 290
    for l in textwrap.wrap(TITLE_SHOW, 32)[:4]: T(d, (W - 70 - tlen(d, l, F_TITLE), y), l, F_TITLE, "white"); y += 76
else:
    d.rectangle((70, 150, 82, 222), fill=ACCENT)
    d.text((104, 148), "SOUSS ACTUALITÉS", font=F_BRAND, fill="white")
    d.text((106, 202), CFG.get("rubrique", "ACTUALITÉ").upper(), font=F_KICK, fill=ACCENT)
    y = 300
    tl = textwrap.wrap(TITLE_SHOW, 30)[:4]
    for l in tl: d.text((70, y), l, font=F_TITLE, fill="white"); y += 70
_g = Image.new("L", (1, H))
for _y in range(H):
    if _y < 700: v = int(225 * (1 - _y / 700) ** 1.2) + (30 if _y < 560 else 0)
    elif _y > 1150: v = int(235 * ((_y - 1150) / 770) ** 0.9)
    else: v = 0
    _g.putpixel((0, _y), max(0, min(255, v)))
_shade = Image.new("RGBA", (W, H), (0, 0, 0, 0)); _shade.putalpha(_g.resize((W, H)))
static = Image.alpha_composite(_shade, static); d = ImageDraw.Draw(static)
url = "souss-actualites.com"; d.text(((W - d.textlength(url, font=F_URL)) / 2, 1790), url, font=F_URL, fill=(235, 235, 235))

CREDITS = [c for c in CREDITS if c]
credit_txt = "Photos : " + ("Wikimedia Commons — " + ", ".join(dict.fromkeys(CREDITS)) if CREDITS else "Souss Actualités")
if CREDITS and len(MEDIA) > len(CREDITS): credit_txt += " ; Souss Actualités"
if CFG.get("musique", "elan") != "aucune": credit_txt += ". Musique originale."
if AR and not CREDITS: credit_txt = "الصور: أخبار سوس" + ("، موسيقى أصلية" if CFG.get("musique", "elan") != "aucune" else "")
def outro(fr, a):
    ov = Image.new("RGBA", (W, H), (10, 10, 12, int(235 * a))); dd = ImageDraw.Draw(ov); al = int(255 * a)
    dd.rectangle((W / 2 - 6, 760, W / 2 + 6, 840), fill=ACCENT + (al,))
    rows = [("أخبار سوس" if AR else "SOUSS ACTUALITÉS", arfont(84) if AR else font("Inter-Black.otf", 78), 870, (255, 255, 255)),
            ("souss-actualites.com", font("Inter-SemiBold.otf", 44), 1005 if AR else 990, ACCENT),
            ((("عن مقال لـ " if mode != "complet" else "بقلم ") if AR else ("D’après un texte de " if mode != "complet" else "Un texte de ")) + BYLINE, F_KICK, 1095 if AR else 1080, (220, 220, 220))]
    for txt, f, yy, col in rows:
        T(dd, ((W - tlen(dd, txt, f)) / 2, yy), txt, f, col + (al,))
    yy = 1680
    for l in textwrap.wrap(credit_txt, 72)[:5]:
        T(dd, ((W - tlen(dd, l, F_SMALL)) / 2, yy), l, F_SMALL, (170, 170, 170, al)); yy += 34
    return Image.alpha_composite(fr, ov)

import unicodedata
slug = re.sub(r"[^a-z0-9]+", "-", unicodedata.normalize("NFKD", TITLE.lower().replace("œ", "oe").replace("æ", "ae")).encode("ascii", "ignore").decode())[:60].strip("-") or "video"
if slug == "video" or len(slug) < 6: slug = "souss-actualites-" + JOB.name
VIDEO = OUT / f"{slug}.mp4"
has_music = (OUT / "music.wav").exists()
af = "[1:a]adelay=500|500,apad,aformat=channel_layouts=stereo:sample_rates=44100"
if has_music:
    af += ",asplit=2[v][sc];[2:a]volume=%s[m0];[m0][sc]sidechaincompress=threshold=0.03:ratio=4:attack=30:release=400[m];[v][m]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.95[a]" % CFG.get("volume_musique", 0.30)
else:
    af += "[a]"
cmd = ["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
       "-i", str(OUT / "voice.wav")] + (["-i", str(OUT / "music.wav")] if has_music else []) + [
       "-filter_complex", af, "-map", "0:v", "-map", "[a]", "-c:v", "libx264", "-preset", "veryfast", "-crf", "26", "-maxrate", "2M", "-bufsize", "4M",
       "-pix_fmt", "yuv420p", "-c:a", "aac", "-b:a", "160k", "-shortest", "-movflags", "+faststart", str(VIDEO)]
ff = subprocess.Popen(cmd, stdin=subprocess.PIPE)
ci, XF, cache = 0, 0.9, {}
for f in range(NF):
    t = f / FPS; tv = t - 0.5
    idx = min(int(t // per), len(seq) - 1); local = t - idx * per
    fr = src(idx).frame(min(max(local / per, 0), 1), idx)
    if idx + 1 < len(seq) and local > per - XF and Path(seq[idx + 1]).suffix.lower() not in VIDEXT:
        fr = Image.blend(fr, src(idx + 1).frame(0, idx + 1), (local - (per - XF)) / XF)
    fr = Image.alpha_composite(fr.convert("RGBA"), static)
    while ci < len(chunks) - 1 and tv > chunks[ci][1]: ci += 1
    c = chunks[ci]
    if c[0] - 0.05 <= tv <= c[1] + 0.3:
        if c[2] not in cache: cache = {c[2]: text_layer(c[2])}
        fr.alpha_composite(cache[c[2]], (0, 1300))
    dr = ImageDraw.Draw(fr)
    dr.rectangle((70, 1760, W - 70, 1766), fill=(85, 85, 85))
    dr.rectangle((70, 1760, 70 + (W - 140) * min(t / DUR, 1), 1766), fill=ACCENT)
    if t > DUR - OUTRO: fr = outro(fr, min((t - (DUR - OUTRO)) / 0.8, 1))
    ff.stdin.write(fr.convert("RGB").tobytes())
ff.stdin.close(); ff.wait()
subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-ss", "2", "-i", str(VIDEO), "-frames:v", "1", "-vf", "scale=540:-1", str(OUT / "miniature.jpg")])

# ------------------------------------------------------------------ 7. textes réseaux sociaux
art_url = CFG.get("url", "souss-actualites.com")
desc = (SOCIAL or {}).get("description") or (LINES[1] if len(LINES) > 1 else TITLE)
tags = (SOCIAL or {}).get("hashtags") or (["#أخبار_سوس", "#المغرب", "#أكادير", "#سوس_ماسة", "#SoussActualités"] if AR else ["#SoussActualités", "#Maroc", "#Agadir", "#SoussMassa", "#Actualité"])
social = f"{TITLE}\n\n{desc}\n\n{'المقال كاملاً' if AR else 'L' + chr(39) + 'article complet'} : {urllib.parse.unquote(art_url)}\n\n{' '.join(tags)}"
(OUT / "description.txt").write_text(social, encoding="utf-8")
json.dump({"video": VIDEO.name, "title": TITLE, "duration": round(DUR, 1), "description": social, "log": LOG},
          open(OUT / "result.json", "w"), ensure_ascii=False, indent=1)
log("TERMINÉ:", VIDEO.name, round(DUR, 1), "s")
