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
