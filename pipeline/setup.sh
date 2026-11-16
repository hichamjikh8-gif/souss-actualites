#!/bin/bash
# Instala todo lo necesario (una vez por sesión)
set -e
pip install --break-system-packages -q kokoro-onnx soundfile numpy pillow
mkdir -p /home/claude/tts && cd /home/claude/tts
for f in kokoro-v1.0.onnx voices-v1.0.bin; do
  [ -f $f ] || curl -sSL -o $f https://github.com/thewh1teagle/kokoro-onnx/releases/download/model-files-v1.0/$f
done
which ffmpeg >/dev/null && echo "SETUP OK"
