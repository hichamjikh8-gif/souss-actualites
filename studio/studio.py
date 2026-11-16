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
BYLINE = CFG.get("byline") or "Hicham Jikh Cheddad"
log("Titre:", TITLE, "| paragraphes:", len(PARAS), "| images article:", len(ARTICLE_IMGS))

# ------------------------------------------------------------------ 2. script
def sentences(text):
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
                if words > int(CFG.get("words", 150)): break
                if len(s.split()) < 4 and i > 0: continue
                LINES.append(s); words += len(s.split())
        log("Résumé extractif:", words, "mots")
LINES = [TITLE + "."] + [l for l in LINES if l.strip() and l.strip().rstrip(".…") != TITLE and not (mode != "complet" and re.search(r"[\u1E00-\u1EFF\u2D30-\u2D7F\u0600-\u06FF]", l))]

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
def spoken(s):
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
from kokoro_onnx import Kokoro
KD = os.environ.get("KOKORO_DIR", str(HERE / "models"))
kok = Kokoro(f"{KD}/kokoro-v1.0.onnx", f"{KD}/voices-v1.0.bin")
speed = float(CFG.get("vitesse", 1.05))
segs, audio, t, sr = [], [], 0.0, 24000
for line in LINES:
    sp = spoken(line)
    ph = kok.tokenizer.phonemize(sp, "fr-fr")
    if "(en)" in ph:
        log("Attention prononciation anglaise:", sp)
    a, sr = kok.create(sp, voice=CFG.get("voix", "ff_siwis"), speed=speed, lang="fr-fr")
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
if re.search(r"agadir|souss|inezgane|aït melloul|taghazout", TEXT_ALL) or not THEMES: THEMES.append("agadir")
for th in THEMES + CFG.get("banques", []):
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
if len(MEDIA) < need and CFG.get("commons", True):
    got = 0
    keys = [k for k in CATS if k in TEXT_ALL and k != "maroc"] or ["agadir"]
    cats = [c for k in keys[:3] for c in CATS[k]] + CFG.get("categories_commons", []) + CATS["maroc"]
    for cat in cats:
        if len(MEDIA) >= need: break
        for url, cr in commons_cat(cat, 3):
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
    lh = 76; y0 = (360 - lh * len(lines)) // 2
    for i, l in enumerate(lines):
        w = d.textlength(l, font=F_SUB); x = (W - w) / 2; y = y0 + i * lh
        for dx, dy in ((0, 4), (2, 4), (-2, 4)): d.text((x + dx, y + dy), l, font=F_SUB, fill=(0, 0, 0, 160))
        d.text((x, y), l, font=F_SUB, fill="white")
    return lay

PH_W, PH_H, PH_Y = 1080, 640, 600
class Still:
    def __init__(self, path):
        im = Image.open(path).convert("RGB")
        if im.width < im.height * 1.2:  # photo verticale: on la place sur fond flouté
            canvas = im.resize((int(im.width * 1100 / im.height), 1100))
            bgc = im.resize((1900, 1100)).filter(ImageFilter.GaussianBlur(30))
            bgc.paste(canvas, ((1900 - canvas.width) // 2, 0)); im = bgc
        self.im = im
        sc = max(W / im.width, H / im.height)
        bg = im.resize((int(im.width * sc) + 1, int(im.height * sc) + 1), Image.BILINEAR)
        l, t = (bg.width - W) // 2, (bg.height - H) // 2
        self.bg = ImageEnhance.Brightness(bg.crop((l, t, l + W, t + H)).filter(ImageFilter.GaussianBlur(40))).enhance(0.42)
    def frame(self, p, idx):
        im = self.im; z = 1.0 + 0.12 * (p if idx % 2 == 0 else 1 - p); ar = PH_W / PH_H
        cw = im.width / z; ch = cw / ar
        if ch > im.height / z: ch = im.height / z; cw = ch * ar
        pan = (p - 0.5) * (im.width - cw) * 0.6 * (1 if idx % 3 else -1)
        cx, cy = im.width / 2 + pan, im.height / 2
        ph = im.resize((PH_W, PH_H), Image.BICUBIC, box=(cx - cw / 2, cy - ch / 2, cx + cw / 2, cy + ch / 2))
        fr = self.bg.copy(); fr.paste(ph, (0, PH_Y)); return fr

class Clip:
    """Vidéo fournie: lue image par image (sans son), recadrée dans la zone photo."""
    def __init__(self, path, seconds):
        self.n = int(seconds * FPS) + 2
        cmd = ["ffmpeg", "-loglevel", "error", "-stream_loop", "-1", "-i", str(path), "-t", str(seconds + 0.2), "-r", str(FPS),
               "-vf", f"scale={PH_W}:{PH_H}:force_original_aspect_ratio=increase,crop={PH_W}:{PH_H}", "-f", "rawvideo", "-pix_fmt", "rgb24", "-"]
        self.proc = subprocess.Popen(cmd, stdout=subprocess.PIPE)
        first = subprocess.run(["ffmpeg", "-loglevel", "error", "-i", str(path), "-frames:v", "1", "-vf", "scale=540:-1", "-f", "image2pipe", "-vcodec", "png", "-"], capture_output=True).stdout
        import io
        im = Image.open(io.BytesIO(first)).convert("RGB")
        sc = max(W / im.width, H / im.height)
        bg = im.resize((int(im.width * sc) + 1, int(im.height * sc) + 1))
        l, t = (bg.width - W) // 2, (bg.height - H) // 2
        self.bg = ImageEnhance.Brightness(bg.crop((l, t, l + W, t + H)).filter(ImageFilter.GaussianBlur(40))).enhance(0.42)
        self.last = None
    def frame(self, p, idx):
        raw = self.proc.stdout.read(PH_W * PH_H * 3)
        if len(raw) == PH_W * PH_H * 3: self.last = Image.frombytes("RGB", (PH_W, PH_H), raw)
        fr = self.bg.copy()
        if self.last: fr.paste(self.last, (0, PH_Y))
        return fr

VIDEXT = (".mp4", ".mov", ".m4v", ".webm")
order = list(MEDIA)
slots = max(len(order), int((VOICE_DUR + 1) / float(CFG.get("sec_par_image", 6.5))))
seq = [order[i % len(order)] for i in range(slots)] if len(order) < slots else order
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
d.rectangle((70, 150, 82, 222), fill=ACCENT)
d.text((104, 148), "SOUSS ACTUALITÉS", font=F_BRAND, fill="white")
d.text((106, 202), CFG.get("rubrique", "ACTUALITÉ").upper(), font=F_KICK, fill=ACCENT)
y = 300
tl = textwrap.wrap(TITLE_SHOW, 30)[:4]
for l in tl: d.text((70, y), l, font=F_TITLE, fill="white"); y += 70
d.rectangle((0, PH_Y - 6, W, PH_Y), fill=ACCENT)
url = "souss-actualites.com"; d.text(((W - d.textlength(url, font=F_URL)) / 2, 1790), url, font=F_URL, fill=(235, 235, 235))

CREDITS = [c for c in CREDITS if c]
credit_txt = "Photos : " + ("Wikimedia Commons — " + ", ".join(dict.fromkeys(CREDITS)) if CREDITS else "Souss Actualités")
if CREDITS and len(MEDIA) > len(CREDITS): credit_txt += " ; Souss Actualités"
if CFG.get("musique", "elan") != "aucune": credit_txt += ". Musique originale."
def outro(fr, a):
    ov = Image.new("RGBA", (W, H), (10, 10, 12, int(235 * a))); dd = ImageDraw.Draw(ov); al = int(255 * a)
    dd.rectangle((W / 2 - 6, 760, W / 2 + 6, 840), fill=ACCENT + (al,))
    for txt, f, yy, col in [("SOUSS ACTUALITÉS", font("Inter-Black.otf", 78), 880, (255, 255, 255)),
                            ("souss-actualites.com", font("Inter-SemiBold.otf", 44), 990, ACCENT),
                            (("D’après un texte de " if mode != "complet" else "Un texte de ") + BYLINE, F_KICK, 1080, (220, 220, 220))]:
        dd.text(((W - dd.textlength(txt, font=f)) / 2, yy), txt, font=f, fill=col + (al,))
    yy = 1680
    for l in textwrap.wrap(credit_txt, 72)[:5]:
        dd.text(((W - dd.textlength(l, font=F_SMALL)) / 2, yy), l, font=F_SMALL, fill=(170, 170, 170, al)); yy += 32
    return Image.alpha_composite(fr, ov)

import unicodedata
slug = re.sub(r"[^a-z0-9]+", "-", unicodedata.normalize("NFKD", TITLE.lower()).encode("ascii", "ignore").decode())[:60].strip("-") or "video"
VIDEO = OUT / f"{slug}.mp4"
has_music = (OUT / "music.wav").exists()
af = "[1:a]adelay=500|500,apad,aformat=channel_layouts=stereo:sample_rates=44100"
if has_music:
    af += ",asplit=2[v][sc];[2:a]volume=%s[m0];[m0][sc]sidechaincompress=threshold=0.03:ratio=4:attack=30:release=400[m];[v][m]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.95[a]" % CFG.get("volume_musique", 0.30)
else:
    af += "[a]"
cmd = ["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
       "-i", str(OUT / "voice.wav")] + (["-i", str(OUT / "music.wav")] if has_music else []) + [
       "-filter_complex", af, "-map", "0:v", "-map", "[a]", "-c:v", "libx264", "-preset", "veryfast", "-crf", "23",
       "-pix_fmt", "yuv420p", "-c:a", "aac", "-b:a", "160k", "-shortest", "-movflags", "+faststart", str(VIDEO)]
ff = subprocess.Popen(cmd, stdin=subprocess.PIPE)
ci, XF, cache = 0, 0.7, {}
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
tags = (SOCIAL or {}).get("hashtags") or ["#SoussActualités", "#Maroc", "#Agadir", "#SoussMassa", "#Actualité"]
social = f"{TITLE}\n\n{desc}\n\nL'article complet : {art_url}\n\n{' '.join(tags)}"
(OUT / "description.txt").write_text(social, encoding="utf-8")
json.dump({"video": VIDEO.name, "title": TITLE, "duration": round(DUR, 1), "description": social, "log": LOG},
          open(OUT / "result.json", "w"), ensure_ascii=False, indent=1)
log("TERMINÉ:", VIDEO.name, round(DUR, 1), "s")
