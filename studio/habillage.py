"""Habillage « chaîne d'info » du Studio vidéo.

Bandeau d'accroche (URGENT / À LA UNE), synthé ville + date, bandeau défilant des titres du site,
transitions variées, vignettage + grain, et piste d'effets sonores (whoosh).
Tout est optionnel : chaque élément se désactive via job.json (clé "habillage": {...}) et
le studio retombe sur l'ancien rendu si ce module plante.
"""
import math, re, datetime
import numpy as np
from PIL import Image, ImageDraw

URGENT_RE = (r"\burgent|alerte|vigilance|\bdrame|séisme|seisme|incendie|inondation|accident|décès|deces|arrestation|"
             r"عاجل|إنذار|زلزال|حريق|فيضان|حادث|وفاة|اعتقال")

VILLES = [("Agadir", "أكادير"), ("Inezgane", "إنزكان"), ("Aït Melloul", "أيت ملول"), ("Taroudant", "تارودانت"),
          ("Tiznit", "تيزنيت"), ("Chtouka", "اشتوكة"), ("Biougra", "بيوكرى"), ("Tata", "طاطا"), ("Taghazout", "تغازوت"),
          ("Tafraout", "تافراوت"), ("Sidi Ifni", "سيدي إفني"), ("Guelmim", "كلميم"), ("Essaouira", "الصويرة"),
          ("Ouarzazate", "ورزازات"), ("Laâyoune", "العيون"), ("Dakhla", "الداخلة"), ("Marrakech", "مراكش"),
          ("Casablanca", "الدار البيضاء"), ("Rabat", "الرباط"), ("Tanger", "طنجة"), ("Fès", "فاس"),
          ("Tindouf", "تندوف"), ("Souss-Massa", "سوس ماسة")]
MOIS_FR = ["JANV.", "FÉVR.", "MARS", "AVR.", "MAI", "JUIN", "JUIL.", "AOÛT", "SEPT.", "OCT.", "NOV.", "DÉC."]
MOIS_AR = ["يناير", "فبراير", "مارس", "أبريل", "ماي", "يونيو", "يوليوز", "غشت", "شتنبر", "أكتوبر", "نونبر", "دجنبر"]


def ease(p):
    p = min(max(p, 0.0), 1.0)
    return 0.5 - 0.5 * math.cos(math.pi * p)


def ease_out(p):
    p = min(max(p, 0.0), 1.0)
    return 1 - (1 - p) ** 3


def back_out(p):
    p = min(max(p, 0.0), 1.0); c1 = 1.70158; c3 = c1 + 1
    return 1 + c3 * (p - 1) ** 3 + c1 * (p - 1) ** 2


def fade(img, a):
    if a >= 1: return img
    r, g, b, al = img.split()
    return Image.merge("RGBA", (r, g, b, al.point(lambda v: int(v * max(a, 0)))))


class Habillage:
    def __init__(self, ctx):
        """ctx : dict fourni par studio.py (W, H, FPS, AR, ACCENT, INTRO, DUR, TITLE, TEXT, NEUTRAL, T, tlen, font, arfont, CFG, log)."""
        self.c = ctx
        self.W, self.H, self.AR, self.ACC = ctx["W"], ctx["H"], ctx["AR"], ctx["ACCENT"]
        cfg = (ctx["CFG"].get("habillage") or {})
        on = lambda k, d=True: cfg.get(k, d) is not False
        self._on = on
        neutral = ctx["NEUTRAL"]
        self.do_badge = on("bandeau") and not neutral
        self.do_lower = on("synthe") and not neutral
        self.do_ticker = on("defilant") and not neutral
        self.do_trans = on("transitions")
        self.do_look = on("grain")
        self.do_sfx = on("sons")
        self._badge = self._lower = self._ticker = None
        self._build()

    # ------------------------------------------------------------------ construction
    def _txt(self, d, xy, s, f, fill):
        self.c["T"](d, xy, s, f, fill)

    def _w(self, d, s, f):
        return self.c["tlen"](d, s, f)

    def _build(self):
        c, W, H, AR = self.c, self.W, self.H, self.AR
        tmp = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
        # 1) bandeau d'accroche
        if self.do_badge:
            lab = (c["CFG"].get("habillage") or {}).get("bandeau_texte")
            if not lab:
                urgent = re.search(URGENT_RE, (c["TITLE"] + " " + c["TEXT"][:600]).lower())
                lab = ("عاجل" if urgent else "في الواجهة") if AR else ("URGENT" if urgent else "À LA UNE")
            self.badge_red = (214, 40, 40) if lab in ("URGENT", "عاجل") else self.ACC
            f = c["arfont"](40) if AR else c["font"]("Inter-Black.otf", 36)
            tw = self._w(tmp, lab, f); bw, bh = int(tw + 112), 72
            im = Image.new("RGBA", (bw, bh), (0, 0, 0, 0)); d = ImageDraw.Draw(im)
            d.rounded_rectangle((0, 0, bw - 1, bh - 1), radius=14, fill=self.badge_red + (255,))
            self._txt(d, ((bw - tw) / 2 + (-18 if AR else 18), 14 if not AR else 4), lab, f, (255, 255, 255, 255))
            self._badge = im; self.badge_lab = lab
        # 2) synthé ville + date
        if self.do_lower:
            full = (c["TITLE"] + " " + c["TEXT"]).lower()
            ville = None
            for fr, ar in VILLES:
                if fr.lower() in full or ar in full:
                    ville = ar if AR else fr.upper(); break
            ville = ville or ("المغرب" if AR else "MAROC")
            try:
                from zoneinfo import ZoneInfo
                now = datetime.datetime.now(ZoneInfo("Africa/Casablanca"))
            except Exception:
                now = datetime.datetime.utcnow()
            date = f"{now.day} {MOIS_AR[now.month - 1]} {now.year}" if AR else f"{now.day} {MOIS_FR[now.month - 1]} {now.year}"
            fv = c["arfont"](46) if AR else c["font"]("Inter-Black.otf", 44)
            fd = c["arfont"](36) if AR else c["font"]("Inter-SemiBold.otf", 34)
            vw, dw = self._w(tmp, ville, fv), self._w(tmp, date, fd)
            h1, h2 = 80, 62
            bw = int(max(vw, dw) + 70)
            im = Image.new("RGBA", (bw + 14, h1 + h2), (0, 0, 0, 0)); d = ImageDraw.Draw(im)
            if AR:  # miroir : blocs collés à droite, barre blanche à droite
                d.rectangle((0, 0, bw, h1), fill=self.ACC + (255,))
                d.rectangle((bw - int(dw + 70), h1, bw, h1 + h2), fill=(14, 14, 16, 235))
                d.rectangle((bw + 6, 0, bw + 13, h1 + h2), fill=(255, 255, 255, 255))
                self._txt(d, (bw - 35 - vw, 6), ville, fv, (255, 255, 255, 255))
                self._txt(d, (bw - 35 - dw, h1 + 4), date, fd, (235, 235, 235, 255))
            else:
                d.rectangle((0, 0, 7, h1 + h2), fill=(255, 255, 255, 255))
                d.rectangle((14, 0, 14 + bw, h1), fill=self.ACC + (255,))
                d.rectangle((14, h1, 14 + int(dw + 70), h1 + h2), fill=(14, 14, 16, 235))
                self._txt(d, (49, 14), ville, fv, (255, 255, 255, 255))
                self._txt(d, (49, h1 + 12), date, fd, (235, 235, 235, 255))
            self._lower = im; self.lower_txt = (ville, date)
        # 3) bandeau défilant
        if self.do_ticker:
            heads = [h for h in (c.get("HEADLINES") or []) if h and h.strip().rstrip(".…") != c["TITLE"]][:8]
            if heads:
                ft = c["arfont"](38) if AR else c["font"]("Inter-SemiBold.otf", 36)
                fu = c["font"]("Inter-Bold.otf", 34)
                # morceaux posés un par un (la police arabe n'a ni « • » ni lettres latines) ; ordre inversé en arabe
                pieces = []
                for h in heads:
                    pieces += [("t", h, ft), ("dot", None, None)]
                pieces += [("t", "souss-actualites.com", fu), ("dot", None, None)]
                if AR: pieces = pieces[::-1]
                ws = [(int(self._w(tmp, x, f)) if k == "t" else 70) for k, x, f in pieces]
                tw = sum(ws) + 4
                strip = Image.new("RGBA", (tw, 66), (0, 0, 0, 0)); d = ImageDraw.Draw(strip); x = 0
                for (k, txt_, f), w_ in zip(pieces, ws):
                    if k == "t":
                        rtl = bool(re.search(r"[\u0600-\u06FF]", txt_))
                        self._txt(d, (x, 2 if rtl else 10), txt_, f, (255, 255, 255, 255) if f is ft else self.ACC + (255,))
                    else:
                        d.ellipse((x + 29, 27, x + 41, 39), fill=self.ACC + (255,))
                    x += w_
                lab = "باختصار" if AR else "EN BREF"
                fl = c["arfont"](38) if AR else c["font"]("Inter-Black.otf", 32)
                lw = int(self._w(tmp, lab, fl) + 50)
                tab = Image.new("RGBA", (lw, 66), self.ACC + (255,)); dt = ImageDraw.Draw(tab)
                self._txt(dt, (25, 10 if not AR else 4), lab, fl, (255, 255, 255, 255))
                self._ticker = (strip, tab, tw)
        # 3b) accroche géante (3 à 5 mots) : visible dès la première image, qui sert de couverture TikTok
        self._hook = None
        hook = (c.get("HOOK") or "").strip()
        if hook and self._on("accroche"):
            import textwrap
            hook = hook if AR else hook.upper()
            fh = c["arfont"](104) if AR else c["font"]("Inter-Black.otf", 100)
            words, lines, cur = hook.split(), [], ""
            for w in words:
                t_ = (cur + " " + w).strip()
                if cur and self._w(tmp, t_, fh) > 860: lines.append(cur); cur = w
                else: cur = t_
            if cur: lines.append(cur)
            lines = lines[:3]
            lh = 156 if AR else 138; pad = 34; bh = lh - 14
            kw = {"direction": "rtl", "language": "ar"} if AR else {}
            im = Image.new("RGBA", (W, lh * len(lines) + 20), (0, 0, 0, 0)); d = ImageDraw.Draw(im)
            for i, ln in enumerate(lines):
                lw = self._w(d, ln, fh); x = (W - lw) / 2; y = i * lh
                try:  # centrage vertical sur les vraies limites des lettres (l'arabe a des hampes plus hautes)
                    bb = d.textbbox((0, 0), ln, font=fh, **kw); ty = y + bh / 2 - (bb[1] + bb[3]) / 2
                except Exception:
                    ty = y + 14
                col = self.ACC if i % 2 == 0 else (255, 255, 255)
                d.rectangle((x - pad, y, x + lw + pad, y + bh), fill=col + (255,))
                self._txt(d, (x, ty), ln, fh, (255, 255, 255, 255) if i % 2 == 0 else (16, 16, 18, 255))
            self._hook = im
        # 4) vignettage + grain
        if self.do_look:
            yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
            r = np.sqrt(((xx - W / 2) / (W / 2)) ** 2 + ((yy - H / 2) / (H / 2)) ** 2) / math.sqrt(2)
            a = (np.clip((r - 0.55) / 0.45, 0, 1) ** 1.6 * 110).astype(np.uint8)
            v = np.zeros((H, W, 4), np.uint8); v[..., 3] = a
            self._vign = Image.fromarray(v, "RGBA")
            rng = np.random.default_rng(7); self._grain = []
            for _ in range(3):
                n = rng.integers(0, 256, (H // 2, W // 2), dtype=np.uint8)
                g = np.zeros((H // 2, W // 2, 4), np.uint8); g[..., 0] = g[..., 1] = g[..., 2] = n; g[..., 3] = 13
                self._grain.append(Image.fromarray(g, "RGBA").resize((W, H), Image.NEAREST))

    # ------------------------------------------------------------------ transitions
    KINDS = ["fondu", "glisse", "flash", "zoom"]

    def kind(self, idx):
        return self.KINDS[idx % 4] if self.do_trans else "fondu"

    def transition(self, a, b, q, idx):
        """a : image qui part, b : image qui arrive (RGB), q : 0→1."""
        k = self.kind(idx); W, H = self.W, self.H
        if k == "glisse":
            e = ease(q); off = int(W * e)
            out = Image.new("RGB", (W, H))
            if self.AR:
                out.paste(a, (off, 0)); out.paste(b, (off - W, 0))
            else:
                out.paste(a, (-off, 0)); out.paste(b, (W - off, 0))
            return out
        if k == "flash":
            base = a if q < 0.5 else b
            w = 1 - abs(q - 0.5) * 2
            return Image.blend(base, Image.new("RGB", (W, H), (255, 255, 255)), 0.85 * w ** 1.5)
        if k == "zoom":
            s = 1 + 0.22 * ease(q)
            aw, ah = int(W * s), int(H * s)
            az = a.resize((aw, ah), Image.BILINEAR).crop(((aw - W) // 2, (ah - H) // 2, (aw - W) // 2 + W, (ah - H) // 2 + H))
            return Image.blend(az, b, ease(q))
        return Image.blend(a, b, q)

    # ------------------------------------------------------------------ calques par image
    def look(self, fr, f):
        if not self.do_look: return fr
        fr.alpha_composite(self._vign)
        fr.alpha_composite(self._grain[f % 3])
        return fr

    def overlay(self, fr, t, tv):
        """Après le titre statique et avant les sous-titres. t : temps vidéo, tv : temps voix (t - INTRO)."""
        W, H, AR = self.W, self.H, self.AR
        INTRO, DUR = self.c["INTRO"], self.c["DUR"]
        end = DUR - 4.0
        # accroche géante : pleine dès l'image 0 (couverture), léger zoom, puis s'efface
        if self._hook is not None and 0 <= tv < 2.3:
            im = self._hook; k = 1 + 0.05 * ease(tv / 2.3)
            if tv > 1.9: im = fade(im, 1 - ease((tv - 1.9) / 0.4))
            if k > 1.001: im = im.resize((int(im.width * k), int(im.height * k)), Image.BILINEAR)
            fr.alpha_composite(im, (int((W - im.width) / 2), int(900 - im.height / 2)))
        # bandeau d'accroche : pop, puis point qui pulse
        if self._badge is not None and 0 <= tv and t < end:
            im = self._badge
            s = 1.0
            if t > end - 0.4: s *= ease((end - t) / 0.4)
            bw, bh = max(1, int(im.width * s)), max(1, int(im.height * s))
            x = 70 + (im.width - bw) // 2 if AR else W - 70 - im.width + (im.width - bw) // 2
            y = 150 + (im.height - bh) // 2
            fr.alpha_composite(im.resize((bw, bh), Image.BILINEAR), (int(x), int(y)))
            if s > 0.95:
                d = ImageDraw.Draw(fr)
                p = 0.5 + 0.5 * math.sin(tv * 2 * math.pi * 1.2)
                cx = (x + bw - 28) if AR else (x + 28); cy = y + bh / 2; rr = 7 + 3 * p
                d.ellipse((cx - rr, cy - rr, cx + rr, cy + rr), fill=(255, 255, 255, int(150 + 105 * p)))
        # synthé ville + date (entre après l'accroche, reste ~6 s)
        if self._lower is not None and 2.3 <= tv <= 9.1:
            im = self._lower
            p_in, p_out = ease_out((tv - 2.3) / 0.45), ease((tv - 8.6) / 0.5)
            k = p_in * (1 - p_out)
            if k > 0.01:
                if AR:
                    x = W - 70 - im.width + int((1 - k) * (im.width + 90))
                else:
                    x = 56 - int((1 - k) * (im.width + 90))
                fr.alpha_composite(fade(im, min(1, k * 1.4)), (x, 1150))
        # bandeau défilant
        if self._ticker is not None and t >= INTRO - 0.3 and t < end:
            strip, tab, tw = self._ticker
            a = ease((t - (INTRO - 0.3)) / 0.5)
            y0 = 1780
            band = Image.new("RGBA", (W, 66), (10, 10, 12, int(225 * a)))
            off = int((tv if tv > 0 else 0) * 130) % tw
            # le premier titre démarre juste à côté de l'étiquette, puis défile en boucle
            x0 = (W - tab.width - tw + off) if AR else (tab.width - off)
            for k in (-1, 0, 1, 2):
                xk = x0 + k * tw
                if xk < W and xk + tw > 0: band.alpha_composite(strip, (xk, 0))
            band.alpha_composite(fade(tab, a), ((W - tab.width) if AR else 0, 0))
            fr.alpha_composite(fade(band, a) if a < 1 else band, (0, y0))
        return fr

    def ticker_on(self):
        return self._ticker is not None

    # ------------------------------------------------------------------ sons
    def sfx(self, path, dur, trans_times):
        """Écrit une piste stéréo 44,1 kHz (whoosh aux transitions, impact à l'ouverture). Renvoie True si écrite."""
        if not self.do_sfx: return False
        import soundfile as sf
        sr = 44100; n = int((dur + 1) * sr); out = np.zeros(n, np.float32)
        rng = np.random.default_rng(3)

        def whoosh(length=0.55, gain=0.22):
            m = int(length * sr); x = rng.standard_normal(m).astype(np.float32)
            env = np.sin(np.linspace(0, math.pi, m)) ** 2
            cut = 0.02 + 0.25 * np.sin(np.linspace(0, math.pi, m))  # filtre passe-bas qui s'ouvre puis se referme
            y = np.zeros(m, np.float32); s = 0.0
            for i in range(m):
                s += cut[i] * (x[i] - s); y[i] = s
            y *= env; y /= (np.abs(y).max() + 1e-6)
            return y * gain

        def impact(gain=0.30):
            m = int(0.6 * sr); tt = np.arange(m) / sr
            y = np.sin(2 * math.pi * (70 - 30 * tt) * tt) * np.exp(-tt * 7)
            y += 0.4 * rng.standard_normal(m) * np.exp(-tt * 40)
            return (y / np.abs(y).max() * gain).astype(np.float32)

        def put(sig, at):
            i = int(at * sr)
            if i < 0 or i >= n: return
            j = min(n, i + len(sig)); out[i:j] += sig[: j - i]

        if self.do_badge: put(impact(), self.c["INTRO"])
        for tc, k in trans_times:
            if k != "fondu":
                w = whoosh(0.5 if k == "glisse" else 0.42, 0.20 if k != "flash" else 0.15)
                put(w, tc - len(w) / sr / 2)
        if self.do_lower: put(whoosh(0.35, 0.10), self.c["INTRO"] + 2.3)
        if not out.any(): return False
        sf.write(path, np.stack([out, out], 1), sr)
        return True
