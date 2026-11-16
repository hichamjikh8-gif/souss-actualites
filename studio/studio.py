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
CAT_FR = (WP_CATS[0] if WP_CATS else (CFG.get("rubrique") or "")).strip()
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
                if words > int(CFG.get("words", 95 if AR else 150)): break
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
import wave, io, asyncio, tempfile
def say_edge(txt, voice):
    """Voix neuronale naturelle + minutage exact de chaque mot."""
    import edge_tts
    rate = f"{int(round((speed - 1) * 100)) + (5 if AR else 0):+d}%"
    async def run():
        try: com = edge_tts.Communicate(txt, voice, rate=rate, boundary="WordBoundary")
        except TypeError: com = edge_tts.Communicate(txt, voice, rate=rate)
        aud, words = bytearray(), []
        async for ch in com.stream():
            if ch["type"] == "audio": aud += ch["data"]
            elif ch["type"] == "WordBoundary": words.append((ch["offset"] / 1e7, ch["duration"] / 1e7, ch["text"]))
        return bytes(aud), words
    aud, words = asyncio.run(run())
    if not aud: raise RuntimeError("audio vide")
    mp3 = tempfile.mktemp(suffix=".mp3"); wav = mp3[:-4] + ".wav"; open(mp3, "wb").write(aud)
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-i", mp3, "-ac", "1", "-ar", "24000", wav], check=True)
    a, r = sf.read(wav, dtype="float32")
    return a, r, (words or None)
pv, kok = None, None
def say_piper(txt):
    global pv
    from piper import PiperVoice
    from piper.config import SynthesisConfig
    if pv is None: pv = PiperVoice.load(f"{KD}/ar_JO-kareem-medium.onnx")
    buf = io.BytesIO()
    with wave.open(buf, "wb") as wf:
        pv.synthesize_wav(txt, wf, syn_config=SynthesisConfig(length_scale=0.85 / speed))
    buf.seek(0); a, r = sf.read(buf, dtype="float32"); return a, r, None
def say_kokoro(txt):
    global kok
    from kokoro_onnx import Kokoro
    if kok is None: kok = Kokoro(f"{KD}/kokoro-v1.0.onnx", f"{KD}/voices-v1.0.bin")
    a, r = kok.create(spoken(txt), voice="ff_siwis", speed=speed, lang="fr-fr"); return a, r, None
VOICE = CFG.get("voix_ar", "ar-SA-HamedNeural") if AR else CFG.get("voix_fr", "fr-FR-HenriNeural")
def neural_text(txt):
    t_ = txt.replace("«", "").replace("»", "").replace("ᵉ", "e")
    for a_, b_ in {r"\bMDH\b": "millions de dirhams", r"\bMMDH\b": "milliards de dirhams", r"\bDH\b": "dirhams"}.items():
        t_ = re.sub(a_, b_, t_)
    return re.sub(r"\s+", " ", t_).strip()
NEURAL_OK = True
def say(txt):
    global NEURAL_OK
    if VOICE != "kokoro" and NEURAL_OK:
        try:
            return say_edge(neural_text(txt), VOICE)
        except Exception as e:
            NEURAL_OK = False; log("Voix neuronale indisponible, repli:", e)
    return say_piper(spoken(txt)) if AR else say_kokoro(txt)
log("Voix:", VOICE)
segs, audio, t, sr = [], [], 0.0, 24000
for line in LINES:
    a, sr, words = say(line)
    dur = len(a) / sr
    if not words:  # minutage estimé à partir de la longueur des mots
        ws = line.split(); tot = sum(len(w) + 1 for w in ws) or 1; c0 = 0.05; words = []
        for w in ws:
            dd = (dur - 0.1) * (len(w) + 1) / tot; words.append((c0, dd, w)); c0 += dd
    gap = np.zeros(int(sr * 0.30), dtype=a.dtype)
    segs.append({"text": line, "start": t, "end": t + dur, "words": [(t + o, t + o + d_, w) for o, d_, w in words]})
    t += dur + 0.30; audio += [a, gap]
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
COULEURS = {"sport": (28, 168, 92), "politique": (214, 52, 52), "économie": (38, 118, 228), "business": (38, 118, 228),
            "international": (128, 92, 238), "société": (0, 158, 168), "culture": (198, 58, 158), "tourisme": (0, 172, 198),
            "enquêtes": (202, 150, 28), "tendances": (236, 72, 120), "opinion": (230, 120, 30)}
_cat = CAT_FR.lower().replace("’", "'")
ACCENT = tuple(CFG["couleur"]) if CFG.get("couleur") else next((c for k, c in COULEURS.items() if k in _cat), (230, 120, 30))
log("Couleur de rubrique:", CAT_FR, ACCENT)
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
OUTRO = 4.0
INTRO = float(CFG.get("intro", 2.0))
DUR = INTRO + VOICE_DUR + OUTRO
NF = int(DUR * FPS)

# Sous-titres « karaoké » : 1 à 2 lignes, le mot prononcé est surligné
chunks = []
for sg in segs:
    cur = []
    for wd in sg["words"]:
        cur.append(wd)
        txt_ = " ".join(x[2] for x in cur)
        if len(txt_) > 34 or (re.search(r"[.,;:!?؟،]$", wd[2]) and len(cur) >= 3):
            chunks.append(cur); cur = []
    if cur:
        if chunks and len(cur) <= 1 and chunks[-1][-1][1] >= sg["start"]: chunks[-1] += cur
        else: chunks.append(cur)
SUB_MAXW = 900
def layout(words_):
    d_ = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
    sp = tlen(d_, " ", F_SUB) or 16
    lines_, cur_, cw_ = [], [], 0
    for i, w in enumerate(words_):
        ww = tlen(d_, w, F_SUB)
        if cur_ and cw_ + sp + ww > SUB_MAXW: lines_.append((cur_, cw_)); cur_, cw_ = [], 0
        cur_.append((i, w, ww)); cw_ += (sp if len(cur_) > 1 else 0) + ww
    if cur_: lines_.append((cur_, cw_))
    return lines_, sp
def text_layer(words_, hi):
    lay = Image.new("RGBA", (W, 360), (0, 0, 0, 0)); d = ImageDraw.Draw(lay)
    lines_, sp = layout(words_)
    lh = 92 if AR else 80; y0 = (360 - lh * len(lines_)) // 2
    bw = max(cw for _, cw in lines_) + 80
    d.rounded_rectangle(((W - bw) / 2, y0 - 24, (W + bw) / 2, y0 + lh * len(lines_) + 16), radius=24, fill=(0, 0, 0, 155))
    for li, (ln, cw) in enumerate(lines_):
        y = y0 + li * lh
        cx = (W + cw) / 2 if AR else (W - cw) / 2
        for i, w, ww in ln:
            x = cx - ww if AR else cx
            if i == hi:
                d.rounded_rectangle((x - 12, y - 4, x + ww + 12, y + lh - 14), radius=14, fill=ACCENT + (255,))
            else:
                T(d, (x + 2, y + 4), w, F_SUB, (0, 0, 0, 170))
            T(d, (x, y), w, F_SUB, (255, 255, 255, 255))
            cx = (cx - ww - sp) if AR else (cx + ww + sp)
    return lay

PH_W, PH_H, PH_Y = 1080, 640, 600
FULL = CFG.get("plein_ecran", True)
def ease(p): return 0.5 - 0.5 * math.cos(math.pi * min(max(p, 0), 1))
EFFET = CFG.get("effet", "3d")
def Still(path):
    if EFFET == "3d":
        try:
            sys.path.insert(0, str(HERE))
            from effet3d import Photo3D
            return Photo3D(path, W, H)
        except Exception as e:
            log("Effet 3D indisponible, zoom classique :", e)
    return Still2D(path)
class Still2D:
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
per = (INTRO + VOICE_DUR + 1.0) / len(seq)
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
_mc = OUT / "music.wav.credit"
if CFG.get("musique", "elan") != "aucune": credit_txt += (". " + _mc.read_text() if _mc.exists() else ". Musique originale.")
if AR and not CREDITS: credit_txt = "الصور: أخبار سوس" + (("، " + _mc.read_text()) if _mc.exists() else ("، موسيقى أصلية" if CFG.get("musique", "elan") != "aucune" else ""))
# ---- Chiffres clés animés
STOPU = {"de", "du", "des", "d'", "et", "à", "au", "en", "le", "la", "les", "un", "une", "sur", "pour", "و", "في", "من", "على"}
FIGS, _last = [], -99
_allw = [w_ for sg in segs[1:] for w_ in sg["words"]]
_i = 0
while _i < len(_allw):
    w0 = _allw[_i]
    if re.search(r"\d", w0[2]):
        j = _i; num = []
        while j < len(_allw) and re.fullmatch(r"[\d.,\u00a0\u202f%ᵉe]+|\d+(er|e|ème)?", _allw[j][2].strip(".,;:")):
            num.append(_allw[j][2].strip(",;:")); j += 1
        unit = []
        while j < len(_allw) and len(unit) < 3 and not re.search(r"\d", _allw[j][2]):
            wd = _allw[j][2]; unit.append(wd.strip(".,;:!?؟،")); j += 1
            if re.search(r"[.,;:!?؟،]$", wd): break
        while unit and unit[-1].lower() in STOPU: unit.pop()
        val = " ".join(num).strip(".")
        is_year = re.fullmatch(r"(19|20)\d\d", val) is not None
        if val and not (is_year and not unit) and w0[0] - _last > 3.5:
            FIGS.append((w0[0], val, " ".join(unit[:3]))); _last = w0[0]
        _i = max(j, _i + 1)
    else:
        _i += 1
log("Chiffres clés:", [(round(a_, 1), b_, c_) for a_, b_, c_ in FIGS])
F_FIG = font("Inter-Black.otf", 170)
def fig_card(val, unit):
    fu = arfont(54) if (AR and re.search(r"[\u0600-\u06FF]", unit)) else font("Inter-Bold.otf", 54)
    tmp = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
    vw = tmp.textlength(val, font=F_FIG); uw = tlen(tmp, unit, fu) if unit else 0
    cw_ = int(max(vw, uw) + 120); ch_ = 300 if unit else 230
    card = Image.new("RGBA", (cw_, ch_), (0, 0, 0, 0)); dc = ImageDraw.Draw(card)
    dc.rounded_rectangle((0, 0, cw_ - 1, ch_ - 1), radius=40, fill=(12, 12, 14, 215))
    dc.rounded_rectangle((0, 0, cw_ - 1, 14), radius=7, fill=ACCENT + (255,))
    dc.text(((cw_ - vw) / 2, 30), val, font=F_FIG, fill=ACCENT + (255,))
    if unit: T(dc, ((cw_ - uw) / 2, 212), unit, fu, (255, 255, 255, 255))
    return card
_figcards = {}
def back_ease(p):
    p = min(max(p, 0), 1); c1 = 1.70158; c3 = c1 + 1
    return 1 + c3 * (p - 1) ** 3 + c1 * (p - 1) ** 2
def figure(fr, tv):
    for k, (t0, val, unit) in enumerate(FIGS):
        if t0 - 0.05 <= tv <= t0 + 2.4:
            if k not in _figcards: _figcards[k] = fig_card(val, unit)
            card = _figcards[k]; dt = tv - t0
            sc_ = max(0.05, back_ease(dt / 0.35)); al = min(1, dt / 0.15) * (1 - max(0, (dt - 2.1) / 0.3))
            cw_, ch_ = int(card.width * sc_), int(card.height * sc_)
            c2 = fade(card.resize((max(cw_, 1), max(ch_, 1)), Image.BILINEAR), al)
            fr.alpha_composite(c2, (int((W - cw_) / 2), int(830 - ch_ / 2)))
    return fr
_intro_brand = Image.new("RGBA", (W, H), (0, 0, 0, 0)); _ib = ImageDraw.Draw(_intro_brand)
LOGO = HERE / "logo" / "logo.png"
_card_w, _card_h = 820, 600
_cy = 640
_ib.rounded_rectangle(((W - _card_w) / 2, _cy, (W + _card_w) / 2, _cy + _card_h), radius=36, fill=(255, 255, 255, 255))
if LOGO.exists():
    _lg = Image.open(LOGO).convert("RGBA"); _lg.thumbnail((_card_w - 80, _card_h - 70), Image.LANCZOS)
    _intro_brand.alpha_composite(_lg, (int((W - _lg.width) / 2), int(_cy + (_card_h - _lg.height) / 2)))
else:
    _bf = arfont(110) if AR else font("Inter-Black.otf", 96); _bt = "أخبار سوس" if AR else "SOUSS ACTUALITÉS"
    T(_ib, ((W - tlen(_ib, _bt, _bf)) / 2, _cy + 240), _bt, _bf, (20, 20, 20, 255))
_kt = CFG.get("rubrique") or ("أخبار" if AR else "Actualité")
_kf = F_KICK if AR else font("Inter-Bold.otf", 40)
_kt = _kt if AR else _kt.upper()
_kw = tlen(_ib, _kt, _kf) + 80
_ib.rounded_rectangle(((W - _kw) / 2, _cy + _card_h + 40, (W + _kw) / 2, _cy + _card_h + 120), radius=40, fill=ACCENT + (255,))
T(_ib, ((W - tlen(_ib, _kt, _kf)) / 2, _cy + _card_h + 56), _kt, _kf, (255, 255, 255, 255))
def fade(img, a):
    if a >= 1: return img
    r_, g_, b_, al_ = img.split(); al_ = al_.point(lambda v: int(v * max(a, 0))); return Image.merge("RGBA", (r_, g_, b_, al_))
def intro(fr, t):
    out_a = 1 - ease((t - (INTRO - 0.45)) / 0.45)        # disparition à la fin de l'intro
    ov = Image.new("RGBA", (W, H), (8, 8, 10, int(185 * out_a)))
    fr = Image.alpha_composite(fr, ov)
    dd = ImageDraw.Draw(fr)
    bar = ease(t / 0.6) * 460                              # barre orange qui s'ouvre
    if out_a > 0: dd.rectangle((W / 2 - bar / 2, 590, W / 2 + bar / 2, 604), fill=ACCENT + (int(255 * out_a),))
    a_ = ease((t - 0.15) / 0.6) * out_a                    # logo qui apparaît en glissant
    sh = int(40 * (1 - ease((t - 0.15) / 0.6)))
    layer = fade(_intro_brand, a_)
    if sh: layer = layer.transform(layer.size, Image.AFFINE, (1, 0, 0, 0, 1, -sh))
    return Image.alpha_composite(fr, layer)
def outro(fr, a):
    ov = Image.new("RGBA", (W, H), (10, 10, 12, int(235 * a))); dd = ImageDraw.Draw(ov); al = int(255 * a)
    dd.rectangle((W / 2 - 6, 760, W / 2 + 6, 840), fill=ACCENT + (al,))
    rows = [("أخبار سوس" if AR else "SOUSS ACTUALITÉS", arfont(84) if AR else font("Inter-Black.otf", 78), 870, (255, 255, 255)),
            ("souss-actualites.com", font("Inter-SemiBold.otf", 44), 1005 if AR else 990, ACCENT),
            ((("عن مقال لـ " if mode != "complet" else "بقلم ") if AR else ("D’après un texte de " if mode != "complet" else "Un texte de ")) + BYLINE, F_KICK, 1095 if AR else 1080, (220, 220, 220)),
            ("تابعونا  @soussactualites" if AR else "Abonnez-vous  @soussactualites", arfont(40) if AR else font("Inter-Bold.otf", 40), 1230, (255, 255, 255))]
    _sw = tlen(dd, rows[-1][0], rows[-1][1]) + 70
    dd.rounded_rectangle(((W - _sw) / 2, 1212, (W + _sw) / 2, 1298), radius=43, fill=ACCENT + (al,))
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
_dl = int(INTRO * 1000)
af = f"[1:a]adelay={_dl}|{_dl},apad,aformat=channel_layouts=stereo:sample_rates=44100"
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
    t = f / FPS; tv = t - INTRO
    idx = min(int(t // per), len(seq) - 1); local = t - idx * per
    fr = src(idx).frame(min(max(local / per, 0), 1), idx)
    if idx + 1 < len(seq) and local > per - XF and Path(seq[idx + 1]).suffix.lower() not in VIDEXT:
        fr = Image.blend(fr, src(idx + 1).frame(0, idx + 1), (local - (per - XF)) / XF)
    fr = fr.convert("RGBA")
    if t < INTRO:
        fr = intro(fr, t)
        if t > INTRO - 0.45: fr = Image.alpha_composite(fr, fade(static, ease((t - (INTRO - 0.45)) / 0.45)))
    else:
        fr = Image.alpha_composite(fr, static)
    while ci < len(chunks) - 1 and tv > chunks[ci + 1][0][0] - 0.02: ci += 1
    c = chunks[ci] if chunks else None
    if c and c[0][0] - 0.05 <= tv <= c[-1][1] + 0.6:
        hi = max([k for k, w_ in enumerate(c) if w_[0] <= tv] or [0])
        key = (ci, hi)
        if key not in cache: cache = {key: text_layer([w_[2] for w_ in c], hi)}
        fr.alpha_composite(cache[key], (0, 1300))
    dr = ImageDraw.Draw(fr)
    dr.rectangle((70, 1760, W - 70, 1766), fill=(85, 85, 85))
    dr.rectangle((70, 1760, 70 + (W - 140) * min(t / DUR, 1), 1766), fill=ACCENT)
    if FIGS and t >= INTRO: fr = figure(fr, tv)
    if t > DUR - OUTRO: fr = outro(fr, min((t - (DUR - OUTRO)) / 0.8, 1))
    ff.stdin.write(fr.convert("RGB").tobytes())
ff.stdin.close(); ff.wait()
subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-ss", "2", "-i", str(VIDEO), "-frames:v", "1", "-vf", "scale=540:-1", str(OUT / "miniature.jpg")])
# ---- Miniature YouTube 1280x720
try:
    TW, TH = 1280, 720
    first = next((m for m in seq if Path(m).suffix.lower() not in VIDEXT), None)
    if first:
        bgi = Image.open(first).convert("RGB")
    else:
        bgi = Image.open(io.BytesIO(subprocess.run(["ffmpeg", "-loglevel", "error", "-ss", "1", "-i", str(seq[0]), "-frames:v", "1", "-f", "image2pipe", "-vcodec", "png", "-"], capture_output=True).stdout)).convert("RGB")
    sc_ = max(TW / bgi.width, TH / bgi.height); bgi = bgi.resize((int(bgi.width * sc_) + 1, int(bgi.height * sc_) + 1), Image.LANCZOS)
    l_, t_ = (bgi.width - TW) // 2, (bgi.height - TH) // 2; th = bgi.crop((l_, t_, l_ + TW, t_ + TH)).convert("RGBA")
    g = Image.new("L", (TW, 1))
    for x in range(TW):
        v = (1 - x / TW) if not AR else (x / TW)
        g.putpixel((x, 0), int(235 * min(1, max(0, v * 1.35 - 0.05))))
    sh_ = Image.new("RGBA", (TW, TH), (0, 0, 0, 0)); sh_.putalpha(g.resize((TW, TH))); th = Image.alpha_composite(th, sh_)
    dt_ = ImageDraw.Draw(th)
    ftt = arfont(74, True) if AR else font("Lora-Variable.ttf", 70, 700)
    lines_t = textwrap.wrap(TITLE_SHOW, 24 if AR else 22)[:4]
    yy = TH - 70 - len(lines_t) * (92 if AR else 84)
    xb = TW - 60 if AR else 60
    dt_.rectangle((xb - 8, yy - 40, xb + 8, yy - 40 + 18) if False else (xb - (140 if AR else 0), yy - 34, xb + (0 if AR else 140), yy - 22), fill=ACCENT)
    for l in lines_t:
        x_ = (xb - tlen(dt_, l, ftt)) if AR else xb
        T(dt_, (x_ + 3, yy + 3), l, ftt, (0, 0, 0, 200)); T(dt_, (x_, yy), l, ftt, (255, 255, 255, 255)); yy += 92 if AR else 84
    if LOGO.exists():
        lg = Image.open(LOGO).convert("RGBA"); lg.thumbnail((230, 166), Image.LANCZOS)
        card = Image.new("RGBA", (lg.width + 30, lg.height + 24), (0, 0, 0, 0))
        ImageDraw.Draw(card).rounded_rectangle((0, 0, card.width - 1, card.height - 1), radius=16, fill=(255, 255, 255, 255))
        card.alpha_composite(lg, (15, 12))
        th.alpha_composite(card, ((TW - card.width - 40) if not AR else 40, 36))
    kt_ = (CFG.get("rubrique") or "") if AR else (CFG.get("rubrique") or "").upper()
    if kt_:
        fk = arfont(34) if AR else font("Inter-Bold.otf", 32); kw_ = tlen(dt_, kt_, fk) + 50
        x0 = (TW - 40 - kw_) if AR else 40
        dt_.rounded_rectangle((x0, 40, x0 + kw_, 98), radius=29, fill=ACCENT)
        T(dt_, (x0 + 25, 49 if not AR else 44), kt_, fk, (255, 255, 255, 255))
    th.convert("RGB").save(OUT / "miniature_youtube.jpg", quality=92)
    log("Miniature YouTube créée")
except Exception as e:
    log("Miniature YouTube impossible:", e)

# ------------------------------------------------------------------ 7. textes réseaux sociaux
art_url = CFG.get("url", "souss-actualites.com")
desc = (SOCIAL or {}).get("description") or (LINES[1] if len(LINES) > 1 else TITLE)
tags = (SOCIAL or {}).get("hashtags") or (["#أخبار_سوس", "#المغرب", "#أكادير", "#سوس_ماسة", "#SoussActualités"] if AR else ["#SoussActualités", "#Maroc", "#Agadir", "#SoussMassa", "#Actualité"])
social = f"{TITLE}\n\n{desc}\n\n{'المقال كاملاً' if AR else 'L' + chr(39) + 'article complet'} : {urllib.parse.unquote(art_url)}\n\n{' '.join(tags)}"
(OUT / "description.txt").write_text(social, encoding="utf-8")
json.dump({"video": VIDEO.name, "title": TITLE, "duration": round(DUR, 1), "description": social, "log": LOG},
          open(OUT / "result.json", "w"), ensure_ascii=False, indent=1)
log("TERMINÉ:", VIDEO.name, round(DUR, 1), "s")
