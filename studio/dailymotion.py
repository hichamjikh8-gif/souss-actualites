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
                return r.json()["access_token"]
            print("jeton refusé par", url, r.status_code, r.text[:200])
        except Exception as e:
            print("jeton impossible via", url, e)
    return None


def publish(d):
    key, secret = os.environ.get("DM_API_KEY", "").strip(), os.environ.get("DM_API_SECRET", "").strip()
    if not key or not secret:
        return {"ok": False, "error": "Clés Dailymotion absentes dans GitHub."}
    vids = glob.glob(os.path.join(d, "*.mp4"))
    res_f = os.path.join(d, "result.json")
    if not vids or not os.path.exists(res_f):
        return {"ok": False, "error": "Vidéo introuvable."}
    res = json.load(open(res_f, encoding="utf-8"))
    title = (res.get("title") or "").strip()[:255]
    if not title:
        return {"ok": False, "error": "Titre manquant."}

    tok = token(key, secret)
    if not tok:
        return {"ok": False, "error": "Dailymotion refuse les clés. Vérifiez-les."}
    h = {"Authorization": "Bearer " + tok}
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
