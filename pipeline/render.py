import json, subprocess, math, textwrap, sys, os
CFG = json.load(open(sys.argv[1]))
WD = CFG["workdir"]
from PIL import Image, ImageDraw, ImageFont, ImageFilter, ImageEnhance

W, H, FPS = 1080, 1920, 25
IMG = WD + "/imgs/"
ORDER = CFG["images"]
ORDER = [o if o != "a7" else "a07" for o in ORDER]
INTER = "/usr/share/fonts/opentype/inter/"
LORA = "/usr/share/fonts/truetype/google-fonts/Lora-Variable.ttf"
ACCENT = (230, 120, 30)

segs = json.load(open(WD + "/segs.json"))
total_voice = segs[-1]["end"]
OUTRO = 3.5
DUR = total_voice + 1.0 + OUTRO
NF = int(DUR * FPS)
LIMIT = None

def font(path, size, wght=None):
    f = ImageFont.truetype(path, size)
    if wght:
        try: f.set_variation_by_axes([wght])
        except Exception: pass
    return f

F_BRAND = font(INTER + "Inter-Black.otf", 46)
F_KICK = font(INTER + "Inter-SemiBold.otf", 30)
F_TITLE = font(LORA, 54, 700)
F_SUB = font(INTER + "Inter-Bold.otf", 60)
F_URL = font(INTER + "Inter-SemiBold.otf", 32)
F_SMALL = font(INTER + "Inter-Medium.otf", 24)

# ---- subtitles: split long lines into short chunks timed by length
chunks = []
for s in segs:
    words = s["text"].split()
    groups, cur = [], []
    for w in words:
        cur.append(w)
        if len(" ".join(cur)) > 38 and len(cur) >= 4:
            groups.append(cur); cur = []
    if cur:
        if groups and len(cur) <= 2: groups[-1] += cur
        else: groups.append(cur)
    n = sum(len(" ".join(g)) for g in groups)
    t = s["start"]; d = s["end"] - s["start"]
    for g in groups:
        txt = " ".join(g); dd = d * len(txt) / n
        chunks.append((t, t + dd, txt)); t += dd

def text_layer(txt):
    lay = Image.new("RGBA", (W, 360), (0, 0, 0, 0))
    d = ImageDraw.Draw(lay)
    lines = textwrap.wrap(txt, 26)
    if len(lines)>1 and len(lines[-1])<10: lines = textwrap.wrap(txt, max(14, len(txt)//len(lines)+4))
    lh = 76; y0 = (360 - lh * len(lines)) // 2
    for i, l in enumerate(lines):
        w = d.textlength(l, font=F_SUB)
        x = (W - w) / 2; y = y0 + i * lh
        for dx, dy in ((0, 4), (2, 4), (-2, 4)):
            d.text((x + dx, y + dy), l, font=F_SUB, fill=(0, 0, 0, 160))
        d.text((x, y), l, font=F_SUB, fill=(255, 255, 255, 255))
    return lay
sub_cache = {}

# ---- photos
PH_W, PH_H, PH_Y = 1080, 640, 600
photos = []
for o in ORDER:
    im = Image.open(IMG + o + ".jpg").convert("RGB")
    sc = max(W / im.width, H / im.height)
    bg = im.resize((int(im.width * sc) + 1, int(im.height * sc) + 1), Image.BILINEAR)
    l = (bg.width - W) // 2; t = (bg.height - H) // 2
    bg = bg.crop((l, t, l + W, t + H)).filter(ImageFilter.GaussianBlur(40))
    bg = ImageEnhance.Brightness(bg).enhance(0.42)
    photos.append((im, bg))
per = (total_voice + 1.0) / len(photos)
XF = 0.7

def photo_frame(idx, local):
    im, bg = photos[idx]
    p = min(max(local / per, 0), 1)
    z = 1.0 + 0.12 * (p if idx % 2 == 0 else 1 - p)
    # crop box with target aspect
    ar = PH_W / PH_H
    cw = im.width / z; ch = cw / ar
    if ch > im.height / z: ch = im.height / z; cw = ch * ar
    pan = (p - 0.5) * (im.width - cw) * 0.6 * (1 if idx % 3 else -1)
    cx = im.width / 2 + pan; cy = im.height / 2
    box = (cx - cw / 2, cy - ch / 2, cx + cw / 2, cy + ch / 2)
    ph = im.resize((PH_W, PH_H), Image.BICUBIC, box=box)
    fr = bg.copy(); fr.paste(ph, (0, PH_Y))
    return fr

# ---- static overlay
static = Image.new("RGBA", (W, H), (0, 0, 0, 0))
d = ImageDraw.Draw(static)
d.rectangle((70, 150, 82, 222), fill=ACCENT)
d.text((104, 148), "SOUSS ACTUALITÉS", font=F_BRAND, fill="white")
d.text((106, 202), CFG["kicker"], font=F_KICK, fill=ACCENT)
title = CFG["title"]
y = 300
for l in textwrap.wrap(title, 30):
    d.text((70, y), l, font=F_TITLE, fill=(255, 255, 255)); y += 70
d.rectangle((0, PH_Y - 6, W, PH_Y), fill=ACCENT)
url = "souss-actualites.com"
d.text(((W - d.textlength(url, font=F_URL)) / 2, 1790), url, font=F_URL, fill=(235, 235, 235))

def outro(fr, a):
    ov = Image.new("RGBA", (W, H), (10, 10, 12, int(235 * a)))
    dd = ImageDraw.Draw(ov)
    al = int(255 * a)
    dd.rectangle((W/2 - 6, 760, W/2 + 6, 840), fill=ACCENT + (al,))
    for txt, f, yy, col in [("SOUSS ACTUALITÉS", font(INTER + "Inter-Black.otf", 78), 880, (255, 255, 255)),
                            ("souss-actualites.com", font(INTER + "Inter-SemiBold.otf", 44), 990, ACCENT),
                            (CFG.get("byline", "Hicham Jikh Cheddad"), F_KICK, 1080, (220, 220, 220))]:
        dd.text(((W - dd.textlength(txt, font=f)) / 2, yy), txt, font=f, fill=col + (al,))
    cr = CFG["credits"]
    yy = 1700
    for l in textwrap.wrap(cr, 70):
        dd.text(((W - dd.textlength(l, font=F_SMALL)) / 2, yy), l, font=F_SMALL, fill=(170, 170, 170, al)); yy += 32
    return Image.alpha_composite(fr, ov)

nf = int(LIMIT * FPS) if LIMIT else NF
ff = subprocess.Popen(["ffmpeg", "-y", "-loglevel", "error", "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}",
                       "-r", str(FPS), "-i", "-", "-i", WD + "/voice.wav", "-i", WD + "/music.wav",
                       "-filter_complex", "[1:a]adelay=500|500,apad,aformat=channel_layouts=stereo:sample_rates=44100,asplit=2[v][sc];[2:a]volume=0.30[m0];[m0][sc]sidechaincompress=threshold=0.03:ratio=4:attack=30:release=400[m];[v][m]amix=inputs=2:duration=first:normalize=0,alimiter=limit=0.95[a]", "-map", "0:v", "-map", "[a]",
                       "-c:v", "libx264", "-preset", "veryfast", "-crf", "20", "-pix_fmt", "yuv420p",
                       "-c:a", "aac", "-b:a", "160k", "-shortest", "-movflags", "+faststart",
                       WD + "/" + CFG["out"]], stdin=subprocess.PIPE)
ci = 0
for f in range(nf):
    t = f / FPS
    tv = t - 0.5  # voice offset
    idx = min(int(t // per), len(photos) - 1); local = t - idx * per
    fr = photo_frame(idx, local)
    if idx + 1 < len(photos) and local > per - XF:
        nxt = photo_frame(idx + 1, local - per)
        fr = Image.blend(fr, nxt, (local - (per - XF)) / XF)
    fr = fr.convert("RGBA")
    fr = Image.alpha_composite(fr, static)
    # subtitle
    while ci < len(chunks) - 1 and tv > chunks[ci][1]: ci += 1
    c = chunks[ci]
    if c[0] - 0.05 <= tv <= c[1] + 0.3:
        if c[2] not in sub_cache: sub_cache = {c[2]: text_layer(c[2])}
        fr.alpha_composite(sub_cache[c[2]], (0, 1300))
    # progress bar
    dr = ImageDraw.Draw(fr)
    dr.rectangle((70, 1760, W - 70, 1766), fill=(85, 85, 85))
    dr.rectangle((70, 1760, 70 + (W - 140) * min(t / DUR, 1), 1766), fill=ACCENT)
    if t > DUR - OUTRO:
        fr = outro(fr, min((t - (DUR - OUTRO)) / 0.8, 1))
    ff.stdin.write(fr.convert("RGB").tobytes())
ff.stdin.close(); ff.wait()
print("frames", nf, "dur", DUR)
