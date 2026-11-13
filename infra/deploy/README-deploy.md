# Déployer Wave sur une t4g.small (2 Go, ARM)

1. **AWS** : EC2 t4g.small, Ubuntu 24.04 arm64, disque gp3 25 Go, IP élastique, groupe de sécurité 22/80/443. DNS : un enregistrement A `DOMAINE` vers l'IP.
2. **Préparer l'hôte** (une fois, relançable) depuis le Mac :
   `scp -r infra/deploy ubuntu@IP:~ && ssh ubuntu@IP 'sudo bash ~/deploy/bootstrap-ubuntu.sh deploy /home/ubuntu/.ssh/authorized_keys'`
   (Docker, swap 2 Go, ufw, fail2ban, mises à jour auto, utilisateur `deploy`).
3. **Envoyer images et fichiers** depuis le Mac (images arm64 construites localement, pas de build sur le serveur) :
   `sh infra/deploy/ship.sh deploy@DOMAINE`
4. **Configurer** sur le serveur (`ssh deploy@DOMAINE`, `cd /srv/wave`) :
   `sh infra/deploy/gen-env.sh DOMAINE` puis renseigner `MAILER_DSN` (relais SMTP) dans `.env`.
5. **Clés JWT** : `sudo sh infra/deploy/gen-jwt.sh`
6. **Certificat** : `sudo certbot certonly --standalone -d DOMAINE -m EMAIL --agree-tos -n && sudo /etc/letsencrypt/renewal-hooks/deploy/wave.sh`
7. **Démarrer** :
   `alias dc='docker compose -f compose.yaml -f compose.small.yaml'`
   `dc run --rm php php bin/console doctrine:migrations:migrate -n && dc up -d`
8. **Renouvellement auto** (une fois le site en ligne) : `sudo certbot reconfigure --cert-name DOMAINE --webroot -w /var/www/certbot` puis `sudo certbot renew --dry-run`.
9. **Mise à jour** : étape 3, puis `dc run --rm php php bin/console doctrine:migrations:migrate -n && dc up -d`.

Vérifier : `curl -I https://DOMAINE` (HSTS présent), `dc ps` (tout « Up »), `free -h`.

**Avant d'ouvrir au public (W-76)**
- Secrets : `gen-env.sh` génère `ACTIVITY_IP_PEPPER` (journal d'activité) ; ne pas le changer après coup (les empreintes d'IP anciennes ne seraient plus comparables). Absent ou égal à `APP_SECRET` : le démarrage prod est refusé.
- Premier administrateur : s'inscrire dans l'application, puis `dc run --rm php php bin/console app:admin:grant EMAIL`.
- Journal : purge automatique à 03:55 UTC par `worker-async` (scheduler) ; rétention 90 j (admin 365 j, alertes traitées 180 j). Ajouter à la page de confidentialité : « un journal technique garde 90 jours l'adresse IP tronquée (/24) et une empreinte non réversible, le type d'action et le navigateur ».
- IP réelle : `TRUSTED_PROXIES` reste vide (nginx est le bord, il réécrit `X-Forwarded-*`). Contrôle : `sh infra/scripts/check-client-ip.sh https://DOMAINE` (doit afficher OK).
