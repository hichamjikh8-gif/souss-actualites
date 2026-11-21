"""Trouve ou crée le lecteur (Player ID) Dailymotion de Souss Actualités.

Depuis 2025, Dailymotion refuse (403) les vidéos intégrées sans Player ID.
Écrit le résultat dans le fichier passé en argument.
"""
import json, os, sys
import requests

CHANNEL = "x6p981e"
V2 = "https://api.dailymotion.com/v2"


def main(out_path):
    key, secret = os.environ["DM_API_KEY"].strip(), os.environ["DM_API_SECRET"].strip()
    r = requests.post("https://oauth2.dailymotion.com/v2/token", timeout=30, data={
        "grant_type": "client_credentials", "client_id": key, "client_secret": secret,
        "scope": "player.manage video.manage"})
    print("jeton:", r.status_code, r.text[:200] if not r.ok else "")
    r.raise_for_status()
    h = {"Authorization": "Bearer " + r.json()["access_token"], "accept": "application/json"}
    fields = "?fields=player_id,label,endpoints.iframe_url,endpoints.lib_url"
    lst = requests.get(f"{V2}/profiles/{CHANNEL}/players" + fields, headers=h, timeout=30)
    print("liste:", lst.status_code, lst.text[:500])
    players = []
    if lst.ok:
        b = lst.json()
        players = b.get("list") or b.get("data") or b.get("players") or (b if isinstance(b, list) else [])
    if not players:
        c = requests.post(f"{V2}/profiles/{CHANNEL}/players" + fields, headers=dict(h, **{"Content-Type": "application/json"}),
                          json={"label": "Souss Actualités"}, timeout=30)
        print("création:", c.status_code, c.text[:500])
        c.raise_for_status()
        players = [c.json()]
    p = players[0]
    pid = p.get("player_id") or p.get("id")
    json.dump({"ok": bool(pid), "player_id": pid, "raw": p}, open(out_path, "w"), ensure_ascii=False)
    print("Player ID:", pid)


if __name__ == "__main__":
    try:
        main(sys.argv[1])
    except Exception as e:
        json.dump({"ok": False, "error": str(e)[:300]}, open(sys.argv[1], "w"))
        print("erreur:", e)
