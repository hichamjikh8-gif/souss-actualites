"""Effet « photo 3D » : l'IA (Depth Anything V2) estime la profondeur de la photo,
puis une caméra virtuelle se déplace dans la scène (parallaxe, travelling, zoom en profondeur)."""
import math
from pathlib import Path
import numpy as np
import cv2
from PIL import Image

_SESS = None
MODEL = Path(__file__).resolve().parent / "profondeur" / "depth_anything_v2_small_q.onnx"


def _session():
    global _SESS
    if _SESS is None:
        import onnxruntime as ort
        so = ort.SessionOptions(); so.log_severity_level = 3
        _SESS = ort.InferenceSession(str(MODEL), so, providers=["CPUExecutionProvider"])
    return _SESS


def profondeur(rgb):
    """Carte de proximité 0 (loin) … 1 (près), même taille que l'image."""
    h, w = rgb.shape[:2]
    s = 518 / min(h, w)
    nh, nw = max(14, int(round(h * s / 14)) * 14), max(14, int(round(w * s / 14)) * 14)
    x = cv2.resize(rgb, (nw, nh), interpolation=cv2.INTER_CUBIC).astype(np.float32) / 255.0
    x = (x - [0.485, 0.456, 0.406]) / [0.229, 0.224, 0.225]
    x = x.transpose(2, 0, 1)[None].astype(np.float32)
    d = _session().run(None, {"pixel_values": x})[0][0]
    lo, hi = np.percentile(d, 2), np.percentile(d, 98)
    d = np.clip((d - lo) / max(hi - lo, 1e-6), 0, 1)
    d = cv2.resize(d, (w, h), interpolation=cv2.INTER_CUBIC)
    # lisser et « gonfler » légèrement le premier plan pour éviter les déchirures sur les bords
    d = cv2.dilate(d, np.ones((9, 9), np.uint8))
    d = cv2.GaussianBlur(d, (0, 0), sigmaX=max(w, h) / 220)
    return d.astype(np.float32)


def ease(p):
    p = min(max(p, 0.0), 1.0)
    return 0.5 - 0.5 * math.cos(math.pi * p)


class Photo3D:
    def __init__(self, path, W, H):
        im = Image.open(path).convert("RGB")
        self.W, self.H = W, H
        sc = max(W / im.width, H / im.height) * 1.06  # petite marge pour le mouvement
        im = im.resize((int(im.width * sc) + 1, int(im.height * sc) + 1), Image.LANCZOS)
        self.img = np.asarray(im)
        self.dep = profondeur(self.img)
        self.ih, self.iw = self.img.shape[:2]
        self.land = self.iw / self.ih > W / H * 1.15
        yy, xx = np.mgrid[0:H, 0:W].astype(np.float32)
        self.gx, self.gy = xx - W / 2, yy - H / 2  # coordonnées centrées

    def frame(self, p, idx):
        W, H = self.W, self.H
        e = ease(p); s = 2 * e - 1  # -1 → 1
        mode = idx % 3
        # 1) cadrage de base (crop qui bouge dans la photo)
        if self.land:   # photo large : la caméra traverse la scène
            span = self.iw - W
            cx = W / 2 + span * (e if idx % 2 == 0 else 1 - e)
            cy = self.ih / 2
            zoom = 1.0 + 0.06 * e
        else:
            cx, cy = self.iw / 2, self.ih / 2
            zoom = 1.0 + 0.10 * (e if idx % 2 == 0 else 1 - e)
        bx = cx + self.gx / zoom
        by = cy + self.gy / zoom
        # 2) proximité de chaque point visé
        n = cv2.remap(self.dep, bx, by, cv2.INTER_LINEAR, borderMode=cv2.BORDER_REFLECT)
        # 3) mouvement de caméra dans la profondeur
        if mode == 0:      # travelling avant : le premier plan grossit plus vite que le fond
            dz = 0.22 * e
            f = 1.0 / (1.0 + dz * n)
            mx = cx + (bx - cx) * f + 26 * s * (n - 0.5)
            my = cy + (by - cy) * f
        elif mode == 1:    # orbite : la caméra tourne autour du sujet
            mx = bx - 110 * s * (n - 0.35)
            my = by - 10 * s * (n - 0.5)
        else:              # élévation : la caméra monte et s'approche
            dz = 0.10 * e
            f = 1.0 / (1.0 + dz * n)
            mx = cx + (bx - cx) * f
            my = cy + (by - cy) * f + 80 * s * (n - 0.4)
        out = cv2.remap(self.img, mx.astype(np.float32), my.astype(np.float32), cv2.INTER_LINEAR,
                        borderMode=cv2.BORDER_REFLECT)
        return Image.fromarray(out)
