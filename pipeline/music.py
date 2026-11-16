import numpy as np, soundfile as sf, sys
DUR_ARG = float(sys.argv[2]) if len(sys.argv) > 2 else 58.0
SR = 44100; BPM = 96; beat = 60 / BPM; bar = 4 * beat
DUR = DUR_ARG
N = int(SR * DUR)
t = np.arange(N) / SR
mix = np.zeros((N, 2))

def midi(n): return 440 * 2 ** ((n - 69) / 12)
# D minor epic-uplifting progression: Dm Bb F C
prog = [[50, 57, 62, 65], [46, 58, 62, 65], [53, 57, 60, 65], [48, 55, 60, 64]]
roots = [38, 34, 41, 36]

def env(n, a, r):
    e = np.ones(n); ai = int(a * SR); ri = int(r * SR)
    e[:ai] = np.linspace(0, 1, ai); e[-ri:] *= np.linspace(1, 0, ri); return e

def add(sig, start, gain=1.0, pan=0.0):
    i = int(start * SR)
    if i < 0: sig = sig[-i:]; i = 0
    j = min(N, i + len(sig))
    if i >= N: return
    s = sig[:j - i] * gain
    mix[i:j, 0] += s * (1 - pan) ; mix[i:j, 1] += s * (1 + pan)

def lowpass(x, a):
    y = np.zeros_like(x); acc = 0.0
    for k in range(len(x)): acc += a * (x[k] - acc); y[k] = acc
    return y

nbars = int(DUR / bar) + 1
# pads (detuned saws, filtered) -- precompute per chord
pad_cache = {}
for ci, ch in enumerate(prog):
    n = int(bar * SR) + int(0.6 * SR); tt = np.arange(n) / SR; s = np.zeros(n)
    for note in ch:
        f = midi(note)
        for det in (-0.12, 0.0, 0.12):
            ph = 2 * np.pi * f * (1 + det / 100) * tt
            s += (2 * ((ph / (2 * np.pi)) % 1) - 1) * 0.5 + np.sin(ph)
    s = lowpass(s, 0.035); s *= env(n, 0.6, 0.6); pad_cache[ci] = s / np.max(np.abs(s))

def pluck(f, dur=0.9):
    n = int(dur * SR); tt = np.arange(n) / SR
    s = (np.sin(2*np.pi*f*tt) + 0.35*np.sin(4*np.pi*f*tt) + 0.12*np.sin(6*np.pi*f*tt)) * np.exp(-tt * 5)
    return s * env(n, 0.005, 0.05)

def kick():
    n = int(0.35 * SR); tt = np.arange(n) / SR
    f = 50 + 90 * np.exp(-tt * 30); return np.sin(2*np.pi*np.cumsum(f)/SR) * np.exp(-tt * 9)

def hat():
    n = int(0.06 * SR); s = np.random.randn(n); s = s - lowpass(s, 0.3); return s * np.exp(-np.arange(n) / SR * 70)

def bassnote(f, dur):
    n = int(dur * SR); tt = np.arange(n) / SR
    return (np.sin(2*np.pi*f*tt) + 0.2*np.sin(4*np.pi*f*tt)) * env(n, 0.02, 0.15)

np.random.seed(1)
K, Hh = kick(), hat()
for b in range(nbars):
    st = b * bar; ci = b % 4; ch = prog[ci]
    intensity = min(1.0, 0.45 + b * 0.06)
    add(pad_cache[ci], st - 0.3, 0.22 * intensity)
    # arpeggio 8ths
    arp = [ch[1] + 12, ch[2] + 12, ch[3] + 12, ch[2] + 12]
    for k in range(8):
        add(pluck(midi(arp[k % 4])), st + k * beat / 2, 0.10 * intensity, pan=(-0.4 if k % 2 else 0.4))
    if b >= 2:
        for k in range(4): add(bassnote(midi(roots[ci]), beat * 0.95), st + k * beat, 0.30)
    if b >= 4:
        for k in range(4): add(K, st + k * beat, 0.55)
        for k in range(8): add(Hh, st + k * beat / 2 + (beat / 4 if False else 0), 0.06 if k % 2 else 0.035)

# simple reverb
ir_n = int(1.8 * SR); ir = np.random.randn(ir_n) * np.exp(-np.arange(ir_n) / SR * 3.2); ir /= np.sum(np.abs(ir)) / 6
from numpy.fft import rfft, irfft
L = N + ir_n
for c in range(2):
    wet = irfft(rfft(mix[:, c], L) * rfft(ir, L), L)[:N]
    mix[:, c] = mix[:, c] * 0.8 + wet * 0.25
# fades
fi = int(1.5 * SR); fo = int(4 * SR)
mix[:fi] *= np.linspace(0, 1, fi)[:, None]; mix[-fo:] *= np.linspace(1, 0, fo)[:, None]
mix /= np.max(np.abs(mix)) / 0.9
sf.write(sys.argv[1], mix.astype(np.float32), SR)
print("ok")
