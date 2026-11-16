#!/bin/bash
# Uso: make_video.sh /ruta/workdir   (workdir contiene script.txt, imgs/, config.json)
set -e
P=$(dirname "$0"); WD="$1"
python3 "$P/tts.py" "$WD" >/dev/null 2>&1
DUR=$(python3 -c "import json;print(json.load(open('$WD/segs.json'))[-1]['end']+5.5)")
python3 "$P/music.py" "$WD/music.wav" "$DUR"
python3 "$P/render.py" "$WD/config.json"
