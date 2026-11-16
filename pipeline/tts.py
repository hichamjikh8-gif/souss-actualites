import soundfile as sf, numpy as np, json, sys, os
os.chdir(sys.argv[1])
from kokoro_onnx import Kokoro
k=Kokoro(os.environ.get("KOKORO_DIR","/home/claude/tts")+"/kokoro-v1.0.onnx",os.environ.get("KOKORO_DIR","/home/claude/tts")+"/voices-v1.0.bin")
print([v for v in k.get_voices() if v.startswith('f')])
lines=[l.strip() for l in open("script.txt") if l.strip()]

sr=24000; out=[]; segs=[]; t=0.0
for l in lines:
    spoken,shown=(l.split('|')+[l])[:2]
    a,sr=k.create(spoken,voice="ff_siwis",speed=1.08,lang="fr-fr")
    pause=np.zeros(int(sr*0.35),dtype=a.dtype)
    segs.append({"text":shown,"start":t,"end":t+len(a)/sr}); t+=len(a)/sr+0.35
    out+= [a,pause]
sf.write("voice.wav",np.concatenate(out),sr)
json.dump(segs,open("segs.json","w"),ensure_ascii=False,indent=1)
print("total",t)
