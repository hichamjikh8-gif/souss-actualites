"""Construit studio_wp.html : même page, mais le script est encodé pour que WordPress ne puisse pas l'altérer."""
import base64, re, pathlib
here = pathlib.Path(__file__).parent
h = (here / "studio.html").read_text(encoding="utf-8")
m = re.search(r"<script>(.*?)</script>", h, re.S)
b64 = base64.b64encode(m.group(1).encode("utf-8")).decode()
loader = ('<script>(function(){var b=atob("' + b64 + '");var u=new Uint8Array(b.length);'
          'for(var i=0;i<b.length;i++)u[i]=b.charCodeAt(i);(0,eval)(new TextDecoder().decode(u));})();</script>')
(here / "studio_wp.html").write_text(h[:m.start()] + loader + h[m.end():], encoding="utf-8")
print("ok", len(b64))
