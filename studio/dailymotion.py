"""Publie sur la chaîne Dailymotion de Souss Actualités une vidéo déjà revue.

Lancé uniquement quand Hicham clique « Publier sur Dailymotion » dans le
Studio (workflow dailymotion.yml) : rien ne part sur Dailymotion sans lui.
L'article WordPress reçoit ensuite la vidéo grâce au mu-plugin
sa-dailymotion-auto-embed.php (même titre que l'article).

Usage : python dailymotion.py <dossier contenant la .mp4 et result.json> <fichier résultat .json>
Le fichier résultat vaut toujours {"ok": true, "id": ...} ou {"ok": false, "error": ...}.
"""
import glob, json, os, sys
import requests

CHANNEL = "x6p981e"
API = "https://partner.api.dailymotion.com"


def token(key, secret):
    # Dailymotion documente deux portes d'authentification (v2 puis historique) :
    # on essaie la récente et on retombe sur l'ancienne si elle refuse.
    for url, scope in (("https://oauth2.dailymotion.com/v2/token", "video.manage"),
                       (API + "/oauth/v1/token", "manage_videos")):
        try:
            r = requests.post(url, timeout=30, data={"grant_type": "client_credentials", "client_id": key,
                                                     "client_secret": secret, "scope": scope})
            if r.ok and r.json().get("access_token"):
                return r.json()["access_token"], ("v2" if "v2" in url else "v1")
            print("jeton refusé par", url, r.status_code, r.text[:200])
        except Exception as e:
            print("jeton impossible via", url, e)
    return None, None


def upload_v2(h, mp4, title, desc):
    s = requests.post("https://api.dailymotion.com/v2/files/upload_sessions", headers=h, timeout=30)
    print("session:", s.status_code)
    s.raise_for_status()
    with open(mp4, "rb") as f:
        sent = requests.post(s.json()["upload_url"], headers=h, files={"file": f}, timeout=600).json()
    r = requests.post(f"https://api.dailymotion.com/v2/profiles/{CHANNEL}/videos", timeout=60,
                      headers=dict(h, **{"Content-Type": "application/json", "accept": "application/json"}),
                      json={"title": title, "description": desc, "category": "news", "visibility": "public",
                            "is_for_kids": False, "source": {"file_url": sent["url"]}})
    print("création:", r.status_code, r.text[:300])
    if r.status_code in (200, 201):
        b = r.json() if r.text else {}
        vid = b.get("video_id") or b.get("id") or r.headers.get("Location", "").rstrip("/").split("/")[-1]
        if vid:
            return {"ok": True, "id": vid}
    return {"ok": False, "error": "Dailymotion a refusé la vidéo (" + str(r.status_code) + ")."}


def publish(d):
    key, secret = os.environ.get("DM_API_KEY", "").strip(), os.environ.get("DM_API_SECRET", "").strip()
    if not key or not secret:
        return {"ok": False, "error": "Clés Dailymotion absentes dans GitHub."}
    vids = glob.glob(os.path.join(d, "*.mp4"))
    res_f = os.path.join(d, "result.json")
    if not vids:
        return {"ok": False, "error": "Vidéo introuvable."}
    if os.path.exists(res_f):
        res = json.load(open(res_f, encoding="utf-8"))
    else:
        # Vidéo retirée de la liste du Studio : on reprend le titre de la commande d'origine.
        job_f = os.path.join(d, "job.json")
        res = json.load(open(job_f, encoding="utf-8")) if os.path.exists(job_f) else {}
    title = (res.get("title") or "").strip()[:255]
    if not title:
        return {"ok": False, "error": "Titre manquant."}

    # Diagnostic sans rien révéler : longueur et type de caractères des clés.
    import re
    for n, v in (("clé", key), ("secret", secret)):
        print(n, "longueur", len(v), "hex" if re.fullmatch(r"[0-9a-fA-F]+", v) else "autre format")
    tok, kind = token(key, secret)
    if not tok:
        return {"ok": False, "error": "Dailymotion refuse les clés. Vérifiez-les."}
    h = {"Authorization": "Bearer " + tok}
    print("jeton obtenu via", kind)
    if kind == "v2":
        return upload_v2(h, vids[0], title, (res.get("description") or "")[:3000])
    up = requests.get(API + "/rest/file/upload", headers=h, timeout=30).json()
    with open(vids[0], "rb") as f:
        sent = requests.post(up["upload_url"], headers=h, files={"file": f}, timeout=600).json()
    r = requests.post(API + f"/rest/user/{CHANNEL}/videos", headers=h, timeout=60, data={
        "url": sent["url"],
        "title": title,
        "description": (res.get("description") or "")[:3000],
        "channel": "news",
        "published": "true",
        "private": "false",
        "is_created_for_kids": "false",
    })
    if r.ok and r.json().get("id"):
        return {"ok": True, "id": r.json()["id"]}
    return {"ok": False, "error": "Dailymotion a refusé la vidéo (" + str(r.status_code) + ")."}


if __name__ == "__main__":
    try:
        out = publish(sys.argv[1])
    except Exception as e:
        out = {"ok": False, "error": "Erreur pendant l'envoi : " + str(e)[:200]}
    print(out)
    json.dump(out, open(sys.argv[2], "w", encoding="utf-8"), ensure_ascii=False)
