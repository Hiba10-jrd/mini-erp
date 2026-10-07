# Checklist de sécurité production

## Déploiement et secrets

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…` ; HTTPS forcé et proxy de confiance correctement configuré.
- [ ] Racine HTTP = `/public` seulement ; interdiction de servir `.env`, dépôt, vendor, logs, backups, workspace et storage privé.
- [ ] `.env` et clés hors Git, permissions privées, secrets fournis par le coffre ; aucune valeur réelle dans les tickets/CLI/logs.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax` ; domaine cookie limité au besoin.
- [ ] `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` choisis selon topologie. Database convient à une instance ; Redis partagé pour multi-instance, avec auth/TLS selon réseau. Cache de rate limiting partagé si plusieurs hôtes. Ne pas utiliser array/sync par accident en production.
- [ ] Workers supervisés, redémarrés après déploiement, retries/timeouts ajustés ; clones de restauration sans workers.
- [ ] Mail SMTP production sécurisé, credentials privés, FROM/DNS configurés ; ne pas conserver `MAIL_MAILER=log` pour de vrais resets.
- [ ] DB applicative à privilèges minimaux ; compte de migrations séparé, sans privilèges globaux au runtime. Privilèges du compte de dump limités au moteur et au schéma nécessaires.

## Reverb et stockage

- [ ] Reverb en HTTPS/WSS via proxy, port interne fermé au public, `REVERB_ALLOWED_ORIGINS` limité aux domaines autorisés, sans `*` ; secret jamais en VITE_ ou client.
- [ ] Endpoints de broadcast authentifiés/autorisés ; débit et ressources surveillés.
- [ ] Attachments disque privé `serve=false` ; backups privé `serve=false`, `throw=true`. Local privé `serve=true` exige URLs signées ; ne pas générer de lien générique vers attachments sensibles.
- [ ] `public/storage` pointe uniquement vers `storage/app/public` ; seuls les fichiers volontairement publics y sont stockés.
- [ ] Permissions Unix/ACL Windows testées sous compte de service ; répertoires privés 0700 et fichiers 0600 demandés. Pas de symlink vers fichiers privés. Dump/workspaces en clair sur volume protégé.

## Sauvegarde et exploitation

- [ ] `BACKUP_ENABLED=true`, disque accessible et privé ; `BACKUP_ENCRYPTION_KEY` 32 octets aléatoires au format documenté, différente de APP_KEY ; key_id connu et clés historiques conservées hors machine.
- [ ] `BACKUP_RESTORE_DB_*` compte dédié limité à une cible existante vide isolée ; aucun droit DROP/global/GRANT OPTION/roles/triggers/events.
- [ ] Scheduler déclenché toutes les minutes ; `BACKUP_SCHEDULE_TIME` valide ; exécution unique sur hôte désigné. Les verrous filesystem locaux ne coordonnent pas plusieurs machines.
- [ ] Backup quotidien vérifié ; alertes sur absence/échec et âge > 24 h, suivi espace libre et archives non vérifiables. Ne pas confondre header listé avec archive validée.
- [ ] Rétention 7 daily / 4 weekly / 3 monthly confirmée ; protection sidecar testée, copie chiffrée hors site, aucun effacement manuel de `.part` sans investigation.
- [ ] Journaux backup/restore sous `storage/logs`, hors DB, permission privée, centralisation et accès restreint ; pas de secrets ni debug. Retention de logs 90 jours adaptée aux exigences de l'exploitation.
- [ ] Tests de restore SQLite validés ; **round-trip MySQL/MariaDB réel sur `mini_erp_restore_test` encore requis**, avec durée mesurée pour RTO cible 2 h et validation RPO 24 h.
- [ ] Audits Composer/npm réexécutés à chaque livraison ; dev dependencies exclues du serveur production (`composer install --no-dev`, assets compilés en CI). Les assets issus de paquets dev peuvent contenir du code navigateur : revoir aussi axios/echo/pusher dans les audits, pas seulement omit=dev.
- [ ] Dépendances dev vulnérables restantes traitées dans un changement dédié, builds exécutés sur entrées contrôlées dans CI isolée ; aucune migration Tailwind majeure sans tests UI.
- [ ] Validation finale sans commit/push automatique ; bascule après restore contrôlée par l'exploitant.
