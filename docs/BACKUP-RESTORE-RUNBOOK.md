# Runbook backup / restore

## Objectifs et prérequis

RPO visé : **24 h**, sous réserve de succès quotidien et surveillance. RTO cible : **2 h**, objectif à mesurer sur volume réel, non garanti par les tests. Tester régulièrement une restauration isolée et conserver une copie chiffrée hors machine avec les clés dans un coffre indépendant.

PHP avec Sodium, ZipArchive et PDO du driver ; pour MySQL/MariaDB, binaries dump et client correspondants dans PATH. Compte applicatif/dump limité aux besoins (SELECT et métadonnées, pas administration globale). Pendant les snapshots, suspendre ou coordonner les écritures DB/fichiers et assurer la cohérence fonctionnelle. Prévoir espace pour dump, ZIP en clair, archive chiffrée et workspace de vérification.

Configurer les variables de `.env.example` dans le coffre/secret store, jamais dans Git. Générer 32 octets aléatoires via un gestionnaire de secrets/CSPRNG, encoder au format `base64:…`, attribuer un key_id, sans réutiliser APP_KEY. Tester les ACL du disque backups et de `storage/logs`. Les dumps et imports échouent proprement si le binaire manque ; ne pas contourner cette protection avec un import PDO.

## Windows (PowerShell)

Depuis le dépôt, ajuster le chemin PHP au poste :

```powershell
$phpBackup = 'C:/Users/hp/.config/herd/bin/php84/php.exe'
& $phpBackup artisan backup:create
& $phpBackup artisan backup:list --verify
& $phpBackup artisan backup:verify 'NOM.erpbackup'
& $phpBackup artisan backup:restore 'NOM.erpbackup' --target-database='C:/RestoreTests/lot22/database.sqlite' --target-storage='C:/RestoreTests/lot22/files' --confirm='UUID_DU_BACKUP'
```

Adapter le driver de cible au driver sauvegardé : l'exemple SQLite exige un backup SQLite. La cible SQLite doit être un nouveau fichier et storage vide/inexistant, hors dépôt public, storage actif et disques applicatifs. N'exécuter ces commandes qu'après choix explicite des cibles. Installer un déclencheur du Planificateur de tâches toutes les minutes, compte de service dédié, répertoire du projet, action PHP + `artisan schedule:run`, sans stocker de mot de passe dans les arguments. Superviser le code retour et les journaux. Les chmod Unix ne remplacent pas les ACL NTFS ; restreindre l'accès au compte de service et aux exploitants autorisés.

Protection manuelle, après identification de l'archive et contrôle du chemin local privé :

```powershell
[IO.File]::Open('C:/CHEMIN_PRIVE/mini-erp/NOM.erpbackup.protected', 'CreateNew').Dispose()
```

Utiliser un chemin exact contrôlé et restreindre les ACL du sidecar. `CreateNew` refuse de remplacer un fichier existant.

## Linux

```bash
php artisan backup:create
php artisan backup:list --verify
php artisan backup:verify NOM.erpbackup
php artisan backup:restore NOM.erpbackup --target-database=/srv/restore-tests/lot22/database.sqlite --target-storage=/srv/restore-tests/lot22/files --confirm=UUID_DU_BACKUP
```

Scheduler sous compte de service, avec chemins adaptés et sortie surveillée :

```cron
* * * * * cd /srv/mini-erp && /usr/bin/php artisan schedule:run >> /var/log/mini-erp-scheduler.log 2>&1
```

Répertoires privés 0700, fichiers 0600 ; webroot exclusivement `/srv/mini-erp/public`, aucune publication de storage privé/backups/logs/.env. Protéger une archive avec un fichier vide `.protected` créé sans écraser un fichier existant ; vérifier propriétaire/permissions. Ne pas effacer les fichiers de verrou pendant une opération.

## Procédure MySQL/MariaDB réelle préparée — STOP avant création

**Cette procédure n'a pas été exécutée. Aucun CREATE DATABASE/USER/GRANT n'est lancé par LOT 22.** Faire valider par l'exploitant une instance de test indépendante et les instructions DBA suivantes avant toute création réelle.

1. Installer les clients compatibles et confirmer `mysqldump`/`mariadb-dump` et `mysql`/`mariadb` dans PATH. Sur Windows : `Get-Command mysqldump,mariadb-dump,mysql,mariadb -ErrorAction SilentlyContinue` ; Linux : `command -v mysqldump mariadb-dump mysql mariadb`.
2. Prévoir une base existante **vide** `mini_erp_restore_test` sur l'instance de test et un compte dédié, sans privilèges globaux, rôle, GRANT OPTION, DROP, TRIGGER, EVENT ou routine. Ne pas utiliser le compte applicatif. Le compte doit avoir SELECT, INSERT, UPDATE, DELETE, CREATE ; selon dump, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES limités à cette seule base. Le DBA choisit les privilèges réellement nécessaires.
3. Dans les anciens modes de grants MySQL/MariaDB, `_` est un wildcard : le scope doit être exactement échappé `mini\_erp\_restore\_test` ; MySQL avec `partial_revokes=ON` traite les underscores littéralement. Examiner SHOW GRANTS, y compris les privilèges hérités. Le guard refuse les scopes génériques et les droits excessifs. La création et le nettoyage futur de la base restent des actions DBA distinctes, jamais accordées au compte de restore.
4. Configurer `BACKUP_RESTORE_DB_DRIVER=mysql` (ou mariadb), HOST, PORT, USERNAME, PASSWORD via secret store ; vider/reconstruire config cache après configuration. Aucun fallback vers DB_USERNAME/DB_PASSWORD. Le mot de passe passe au client via fichier defaults privé temporaire, puis supprimé. Ne pas le passer en CLI.
5. Créer un backup MySQL/MariaDB cohérent de la source autorisée et le vérifier. Noter son UUID. Préparer storage isolé vide. **STOP ici sur ce poste tant que la base réelle n'a pas été autorisée/créée par l'exploitant.**
6. Une fois la cible préparée et approuvée, exécuter :

```bash
php artisan backup:restore NOM.erpbackup --target-database=mini_erp_restore_test --target-storage=/srv/restore-tests/lot22/files --confirm=UUID_DU_BACKUP
```

Sous PowerShell, même commande avec `& $phpBackup` et un chemin storage Windows absolu. La commande ne crée/drop aucune base ; elle refuse une cible active, non vide ou trop privilégiée. Les seuls `DROP TABLE IF EXISTS` natifs du dump sont retirés pour l'import dans la cible vide, sans modifier les valeurs métier. Aucun changement automatique de connexion active.

## Contrôles après restauration

- Exiger rapport SUCCESS, verification VALID, quarantine NEUTRALIZED ; en cas INCOMPLETE/FAILED, garder la cible isolée pour inspection, ne pas basculer.
- Contrôler migrations et versions ; comparer factures/lignes, paiements/allocations, utilisateurs/RBAC, audit et notifications à la source attendue ; contrôler SHA-256 des fichiers.
- Vérifier sessions/jobs/job_batches/failed_jobs/password_reset_tokens vides, remember_token NULL. Les événements et notifications externes restent désactivés sur le clone ; ne pas démarrer ses workers ni Reverb.
- Vérifier la source inchangée et la connexion de production conservée. Mesurer durée/volume et signer le compte rendu de test MySQL avant production.
- Ne pas réimporter une archive suspecte dans un environnement avec accès réseau et secrets de production. Le contenu SQL exige une provenance de backup approuvée ; le chiffrement prouve la possession de la clé, pas l'innocuité de tout SQL.

## Rotation et récupération catastrophe

Conserver le couple key_id/clé historique dans un coffre hors machine, avec historique d'accès et procédure de récupération. Pour rotation : générer une clé indépendante, nouveau key_id, déployer via secret store, réactualiser config cache, créer et vérifier le premier backup. Une instance ne charge qu'une clé : vérifier/restaurer un ancien backup dans un contexte CLI isolé configuré avec sa clé et son key_id. Les archives des autres clés restent conservées par rétention ; ne jamais perdre leur clé tant qu'elles doivent rester récupérables. Protéger un exemplaire important avec sidecar et copie hors site.

En catastrophe : provisionner une machine saine et le même code/migrations, retrouver archive et clé correspondante, vérifier intégralement, restaurer isolément, appliquer les contrôles ci-dessus. La bascule DB/storage et le redémarrage des services sont une décision d'exploitation séparée. Renouveler les secrets compromis, revoir les accès et l'origine de la panne. Conserver le rapport et mesurer RPO/RTO réels.
