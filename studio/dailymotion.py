"""Envoie la vidéo terminée sur la chaîne Dailymotion de Souss Actualités.

Appelé par le workflow après la fabrication. Ne bloque jamais le reste :
sans clés (secrets DM_API_KEY / DM_API_SECRET), pour une autre marque, ou en
cas d'erreur, le script écrit un message et s'arrête proprement (code 0).
L'article WordPress reçoit ensuite la vidéo grâce au mu-plugin
sa-dailymotion-auto-embed.php (même titre que l'article).
"""
import glob, json, os, sys
import requests

CHANNEL = "x6p981e"
API = "https://partner.api.dailymotion.com"


def token(key, secret):
    # Deux portes d'authentification documentées par Dailymotion (v2 puis historique) :
    # on essaie la récente et on retombe sur l'ancienne si elle refuse.
    for url, scope in (("https://oauth2.dailymotion.com/v2/token", "video.manage"),
                       (API + "/oauth/v1/token", "manage_videos")):
        try:
            r = requests.post(url, timeout=30, data={"grant_type": "client_credentials", "client_id": key,
                                                     "client_secret": secret, "scope": scope})
            if r.ok and r.json().get("access_token"):
                return r.json()["access_token"]
            print("Dailymotion: jeton refusé par", url, r.status_code)
        except Exception as e:
            print("Dailymotion: jeton impossible via", url, e)
    return None


def main(job_dir):
    key, secret = os.environ.get("DM_API_KEY", ""), os.environ.get("DM_API_SECRET", "")
    if not key or not secret:
        print("Dailymotion: clés absentes, envoi ignoré.")
        return
    job = json.load(open(os.path.join(job_dir, "job.json"), encoding="utf-8"))
    if job.get("marque", "souss") != "souss" or job.get("dailymotion") is False:
        print("Dailymotion: pas pour cette vidéo.")
        return
    vids = glob.glob(os.path.join(job_dir, "out", "*.mp4"))
    res_f = os.path.join(job_dir, "out", "result.json")
    if not vids or not os.path.exists(res_f):
        print("Dailymotion: pas de vidéo terminée.")
        return
    res = json.load(open(res_f, encoding="utf-8"))
    title = (res.get("title") or "").strip()[:255]
    if not title:
        print("Dailymotion: titre manquant.")
        return

    tok = token(key, secret)
    if not tok:
        return
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
        vid = r.json()["id"]
        print("Dailymotion: vidéo publiée https://www.dailymotion.com/video/" + vid)
        json.dump({"id": vid}, open(os.path.join(job_dir, "out", "dailymotion.json"), "w"))
    else:
        print("Dailymotion: création refusée", r.status_code, r.text[:300])


if __name__ == "__main__":
    try:
        main(sys.argv[1])
    except Exception as e:
        print("Dailymotion: erreur, envoi abandonné:", e)
