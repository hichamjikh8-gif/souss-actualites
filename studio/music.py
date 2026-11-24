"""Musique originale générée (libre de droits). Usage: music.py sortie.wav durée [elan|calme|dramatique|<morceau classique>]"""
import numpy as np, soundfile as sf, sys
OUTF, DUR = sys.argv[1], float(sys.argv[2]); MOOD = sys.argv[3] if len(sys.argv) > 3 else "elan"
# ---- Musique classique (enregistrements du domaine public / libres, Wikimedia Commons)
CLASSIQUE = {
    "mozart_nachtmusik": ("File:Mozart K525 Serenade in G Major 1 - Allegro.ogg", "Mozart – Une petite musique de nuit"),
    "mozart_romance": ("File:Mozart K525 Serenade in G Major 2 - Romance.ogg", "Mozart – Une petite musique de nuit (Romance)"),
    "mozart_piano": ("File:Mozart - Piano Sonata No. 16 in C major - II. Andante.ogg", "Mozart – Sonate pour piano n°16 (Andante)"),
    "mozart_concerto": ("File:Mozart; Concerto No. 25 in C- II. Andante.ogg", "Mozart – Concerto pour piano n°25 (Andante)"),
    "beethoven_lune": ("File:Ludwig van Beethoven - sonata no. 14 in c sharp minor 'moonlight', op. 27 no. 2 - i. adagio sostenuto.ogg", "Beethoven – Sonate « Clair de lune »"),
    "vivaldi_printemps": ("File:The Modena Chamber Orchestra - Vivaldi's Spring, RV 269 - I. Allegro.ogg", "Vivaldi – Le Printemps"),
    "chopin_nocturne": ("File:Chopin Nocturne No. 2 in E Flat Major, Op. 9.ogg", "Chopin – Nocturne op. 9 n°2"),
    "bach_air": ("File:Air (Bach).ogg", "Bach – Air de la Suite n°3"),
}
# ---- Nouvelles mélodies (téléchargées depuis Wikimedia Commons au premier usage, puis gardées en cache)
NOUVELLES = {
    "debussy_lune": ("File:Clair de lune (Claude Debussy) Suite bergamasque.ogg", "Debussy – Clair de lune", "Claude Debussy", "Public domain"),
    "debussy_arabesque": ("File:Claude Debussy - Première Arabesque - Patrizia Prati.ogg", "Debussy – Première Arabesque", "Patrizia Prati", "CC BY-SA 4.0"),
    "grieg_matin": ("File:Henrik Ibsen, Edvard Grieg - Morgenstemning (Morning Mood).ogg", "Grieg – Au matin (Peer Gynt)", "Pracchia-78", "Public domain"),
    "dvorak_largo": ("File:Antonin Dvorak - symphony no. 9 in e minor 'from the new world', op. 95 - ii. largo.ogg", "Dvořák – Symphonie du Nouveau Monde (Largo)", "Musopen", "Public domain"),
    "handel_saba": ("File:Handel - Arrival of the Queen of Sheba.ogg", "Haendel – L’Arrivée de la reine de Saba", "Wikimedia Commons", "CC BY-SA 2.0"),
    "vivaldi_hiver": ("File:Vivaldi - Four Seasons 4 Winter mvt 1 Allegro non molto - John Harrison violin.oga", "Vivaldi – L’Hiver", "John Harrison", "CC BY-SA 4.0"),
    "albeniz_leyenda": ("File:Isaac Albeniz - suite espanola op. 47 - leyenda.ogg", "Albéniz – Asturias (Leyenda)", "Musopen", "Public domain"),
    "tarrega_alhambra": ("File:Recuerdos de la Alhambra.ogg", "Tárrega – Souvenirs de l’Alhambra", "Carlo Alberto Boni", "CC BY-SA 3.0"),
}
for _k, (_t, _n, _a, _l) in NOUVELLES.items(): CLASSIQUE[_k] = (_t, _n)

def telecharger(cle, dest):
    """Télécharge une mélodie de Commons (une seule fois). Renvoie True si le fichier est prêt."""
    import urllib.request, urllib.parse
    if dest.exists() and dest.stat().st_size > 50000: return True
    dest.parent.mkdir(parents=True, exist_ok=True)
    url = "https://commons.wikimedia.org/wiki/Special:FilePath/" + urllib.parse.quote(NOUVELLES[cle][0][5:].replace(" ", "_"))
    for _ in range(3):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "SoussActualitesStudio/1.0 (https://souss-actualites.com)"})
            data = urllib.request.urlopen(req, timeout=90).read()
            if len(data) > 50000: dest.write_bytes(data); return True
        except Exception as e:
            print("téléchargement musique:", e)
    return False

if MOOD in CLASSIQUE:
    import json, subprocess, os
    from pathlib import Path
    title, nom = CLASSIQUE[MOOD]
    here = Path(__file__).resolve().parent / "musique"
    src = here / f"{MOOD}.ogg"
    cr = json.load(open(here / "credits.json")).get(MOOD, {})
    if MOOD in NOUVELLES:
        src = here / "extra" / f"{MOOD}.ogg"; cr = {"artist": NOUVELLES[MOOD][2], "licence": NOUVELLES[MOOD][3]}
        if not telecharger(MOOD, src):  # en cas d'échec : Mozart piano, pour ne jamais bloquer la vidéo
            MOOD, nom = "mozart_piano", CLASSIQUE["mozart_piano"][1]; src = here / "mozart_piano.ogg"
            cr = json.load(open(here / "credits.json")).get(MOOD, {})
    fo = 4.0
    subprocess.run(["ffmpeg", "-y", "-loglevel", "error", "-stream_loop", "-1", "-i", str(src), "-t", str(DUR),
                    "-af", f"loudnorm=I=-18:TP=-2,afade=t=in:d=1.5,afade=t=out:st={max(DUR - fo, 0)}:d={fo}",
                    "-ar", "44100", "-ac", "2", OUTF], check=True)
    open(OUTF + ".credit", "w").write(f"Musique : {nom} ({cr.get('artist', '')}, {cr.get('licence', '')}, Wikimedia Commons)")
    sys.exit(0)
SR = 44100
P = {"elan":       dict(bpm=96, prog=[[50,57,62,65],[46,58,62,65],[53,57,60,65],[48,55,60,64]], roots=[38,34,41,36], drums=True, pad=0.22, arp=0.10),
     "calme":      dict(bpm=72, prog=[[48,55,60,64],[45,52,57,60],[41,48,53,57],[43,50,55,59]], roots=[36,33,29,31], drums=False, pad=0.20, arp=0.13),
     "dramatique": dict(bpm=84, prog=[[45,52,57,60],[41,48,53,57],[43,50,55,59],[40,47,52,56]], roots=[33,29,31,28], drums=True, pad=0.28, arp=0.07)}[MOOD]
beat = 60 / P["bpm"]; bar = 4 * beat; N = int(SR * DUR); mix = np.zeros((N, 2))
def midi(n): return 440 * 2 ** ((n - 69) / 12)
def env(n, a, r):
    e = np.ones(n); ai = max(1, int(a * SR)); ri = max(1, int(r * SR)); e[:ai] = np.linspace(0, 1, ai); e[-ri:] *= np.linspace(1, 0, ri); return e
def add(sig, start, gain=1.0, pan=0.0):
    i = int(start * SR)
    if i < 0: sig = sig[-i:]; i = 0
    j = min(N, i + len(sig))
    if i >= N: return
    s = sig[:j - i] * gain; mix[i:j, 0] += s * (1 - pan); mix[i:j, 1] += s * (1 + pan)
def lowpass(x, a):
    from scipy.signal import lfilter
    return lfilter([a], [1, a - 1], x)
pads = {}
for ci, ch in enumerate(P["prog"]):
    n = int(bar * SR) + int(0.6 * SR); tt = np.arange(n) / SR; s = np.zeros(n)
    for note in ch:
        f = midi(note)
        for det in (-0.12, 0.0, 0.12):
            ph = 2 * np.pi * f * (1 + det / 100) * tt; s += (2 * ((ph / (2 * np.pi)) % 1) - 1) * 0.5 + np.sin(ph)
    s = lowpass(s, 0.035) * env(n, 0.6, 0.6); pads[ci] = s / np.max(np.abs(s))
def pluck(f, dur=1.0):
    n = int(dur * SR); tt = np.arange(n) / SR
    return (np.sin(2*np.pi*f*tt) + 0.35*np.sin(4*np.pi*f*tt) + 0.12*np.sin(6*np.pi*f*tt)) * np.exp(-tt * 4.5) * env(n, 0.005, 0.05)
def kick():
    n = int(0.35 * SR); tt = np.arange(n) / SR; f = 50 + 90 * np.exp(-tt * 30)
    return np.sin(2*np.pi*np.cumsum(f)/SR) * np.exp(-tt * 9)
def hat():
    n = int(0.06 * SR); s = np.random.randn(n); s = s - lowpass(s, 0.3); return s * np.exp(-np.arange(n) / SR * 70)
def bassnote(f, dur):
    n = int(dur * SR); tt = np.arange(n) / SR; return (np.sin(2*np.pi*f*tt) + 0.2*np.sin(4*np.pi*f*tt)) * env(n, 0.02, 0.15)
np.random.seed(1); K, Hh = kick(), hat()
for b in range(int(DUR / bar) + 1):
    st = b * bar; ci = b % 4; ch = P["prog"][ci]; inten = min(1.0, 0.45 + b * 0.06)
    add(pads[ci], st - 0.3, P["pad"] * inten)
    arp = [ch[1] + 12, ch[2] + 12, ch[3] + 12, ch[2] + 12]
    step = 2 if MOOD != "calme" else 1
    for k in range(4 * step):
        add(pluck(midi(arp[k % 4])), st + k * beat / step, P["arp"] * inten, pan=(-0.4 if k % 2 else 0.4))
    if b >= 2:
        for k in range(4): add(bassnote(midi(P["roots"][ci]), beat * 0.95), st + k * beat, 0.30 if MOOD != "calme" else 0.18)
    if P["drums"] and b >= 4:
        for k in range(4): add(K, st + k * beat, 0.55)
        for k in range(8): add(Hh, st + k * beat / 2, 0.06 if k % 2 else 0.035)
ir_n = int(1.8 * SR); ir = np.random.randn(ir_n) * np.exp(-np.arange(ir_n) / SR * 3.2); ir /= np.sum(np.abs(ir)) / 6
from numpy.fft import rfft, irfft
L = N + ir_n
for c in range(2):
    wet = irfft(rfft(mix[:, c], L) * rfft(ir, L), L)[:N]; mix[:, c] = mix[:, c] * 0.8 + wet * 0.25
fi, fo = int(1.5 * SR), int(4 * SR)
mix[:fi] *= np.linspace(0, 1, fi)[:, None]; mix[-fo:] *= np.linspace(1, 0, fo)[:, None]
mix /= np.max(np.abs(mix)) / 0.9
sf.write(OUTF, mix.astype(np.float32), SR)
