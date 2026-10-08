# LOT 22 — sauvegardes et sécurité

## Architecture

`BackupManager` valide le disque local privé et la clé, prend un verrou filesystem par disque, crée un workspace privé, réalise le dump et collecte `storage/app/private` et `storage/app/public`. Les caches, logs et répertoires temporaires sont exclus ; symlinks, chemins traversants et fichiers spéciaux sont refusés. SQLite utilise `VACUUM INTO`. MySQL/MariaDB exige `mysqldump`/`mariadb-dump` et utilise Symfony Process avec arguments séparés, sans mot de passe dans les arguments ni sortie du processus affichée.

`BackupManifestService` décrit UUID, date UTC, versions PHP/Laravel/DB, migrations, commit Git disponible, fichiers, tailles et SHA-256. `BackupArchiveService` construit un ZIP interne ; `BackupEncryptionService` le chiffre en flux. `BackupVerificationService` authentifie et contrôle intégralement le ZIP et chaque empreinte avant publication atomique du `.part` vers `.erpbackup`. Les workspaces sont nettoyés, y compris en cas d'échec.

`RestoreManager` vérifie de nouveau après confirmation, restaure exclusivement vers une DB et un storage isolés, avec PDO indépendant. Il neutralise sessions, jobs, batches, failed jobs, tokens de reset et remember tokens avant de copier les fichiers. Les tables métier, RBAC, audit et notifications sont conservées. Les contrôles finaux vérifient DB, migrations, absence de données éphémères et empreintes des fichiers. Une restauration partielle reste isolée, avec rapport `FAILED` ou `INCOMPLETE`, sans bascule de production.

## Commandes

| Commande | Fonction |
| --- | --- |
| `php artisan backup:create` | Dump, collecte, chiffrement, vérification, publication puis rétention |
| `php artisan backup:list` | Headers seulement, non authentifiés |
| `php artisan backup:list --verify` | Liste avec vérification intégrale |
| `php artisan backup:verify NOM.erpbackup` | Authentification et empreintes, sans restauration |
| `php artisan backup:restore NOM.erpbackup --target-database=… --target-storage=… --confirm=UUID` | Restauration isolée avec confirmation supplémentaire interactive |

`--no-interaction` supprime seulement le prompt supplémentaire ; les trois options et l'UUID exact restent obligatoires. `backup:prune` et `backup:health` ne sont pas implémentées : aucune purge autonome ne contourne la condition create + verify. La surveillance utilise `backup:list --verify`, les dates UTC et les journaux.

## Format et chiffrement

Format v1 : magie `ERPBACKUP\n`, longueur big endian et header JSON public borné (version, cipher, key_id, backup_id, created_at), header Sodium, puis frames de longueur big endian et ciphertext. Cipher : `secretstream-xchacha20-poly1305`, blocs de 64 KiB et frame FINAL vide obligatoire, sans données après. Le préfixe complet et la longueur de frame sont authentifiés. Le header seul ne prouve aucune authenticité.

`BACKUP_ENCRYPTION_KEY` exige `base64:` suivi du Base64 canonique de 32 octets aléatoires, distincts de `APP_KEY`. Un seul couple clé/key_id est chargé par instance. Les clés historiques doivent être conservées séparément. Limites : 100 GiB de contenu, 100 000 fichiers, manifeste 16 MiB. Le ZIP interne et le dump sont temporairement en clair sur disque privé ; le disque et ses ACL doivent donc être protégés.

## Rétention

Par défaut 7 jours distincts, 4 semaines ISO distinctes, 3 mois distincts **parmi les périodes présentes**, en UTC. La sélection conserve l'archive la plus récente de chaque période, puis l'union des trois ensembles. Il ne s'agit pas de trois durées glissantes. Même avec quotas zéro, le backup courant et la dernière archive valide restent conservés.

La rétention est appelée uniquement après publication vérifiée, sous `.backup.lock`. Elle réauthentifie l'archive courante et les candidates ; les archives corrompues, de clé inconnue ou non vérifiables restent conservées. Le verrou `.restore.lock`, pris avant préparation et confirmation interactive, bloque toute suppression pendant restore. Les `.part` sont toujours conservés. Un sidecar `NOM.erpbackup.protected` protège l'archive par sa simple présence, sans lire son contenu ni suivre un lien. L'opérateur propriétaire du disque peut créer ce fichier vide avec permissions privées et le retirer après décision explicite. Ne jamais placer ces sidecars dans le disque public.

Un échec de rétention ne retire pas le backup réussi et est journalisé. Contrôler l'espace libre et les erreurs ; les archives non vérifiables peuvent s'accumuler. Les verrous locaux ne constituent pas un mécanisme de coordination distribué multi-hôte.

## Planification et journaux

`backup:create` quotidien à `BACKUP_SCHEDULE_TIME=02:00`, fuseau de l'application, `withoutOverlapping`. Les jobs échéances clients daily et alertes stock hourly restent présents. Aucun restore planifié. Installer le déclencheur scheduler et superviser ses échecs.

Canal fichier quotidien `backup_operations`, `storage/logs/backup-operations.log`, rotation 90 jours, permission demandée 0600. Hors DB restaurée et hors collecte des sauvegardes. Chaque opération possède operation_id, backup_id (nullable avant identification), operation, started_at, finished_at, result, source, target, operator (`console`), key_id, warnings, error nettoyée. Aucun contenu dump, erreur PDO ou sortie process ; les secrets configurés sont également masqués. Les journaux doivent être centralisés/protégés par l'exploitant ; ils ne constituent pas un journal inviolable.

## Sécurité ciblée et stockage

Confirmation du mot de passe : cinq échecs par utilisateur et IP sur 60 secondes ; remise à zéro après succès. Forgot password : même message pour adresse existante, inconnue ou throttled par le broker. Reset : même erreur de token pour compte inconnu ou token invalide. Aucune MFA ou obligation nouvelle de vérification email. L'égalité des messages ne garantit pas l'égalité du temps de réponse SMTP ; surveiller les abus et limiter les requêtes au proxy en production.

Disque attachments : `serve=false`, privé ; backups : `serve=false`, privé, `throw=true`. Disque local : `serve=true`, privé, route Laravel nécessitant une signature relative. Aucun code applicatif trouvé émettant des URLs signées de téléchargement d'attachments. Les URLs temporaires d'upload Livewire concernent les aperçus. Le contrôleur attachments impose auth et autorisations métier. Le lien Windows `public/storage` pointe vers `storage/app/public` seulement. Aucun bypass réel identifié ; LOT 21 inchangé. Le contenu public reste volontairement accessible sans autorisation et ne doit jamais recevoir d'attachments sensibles.

## Validation et limites

Tests : configuration, workspace, Process/secret, chiffrement/troncature/tampering, manifeste/ZIP, commandes, rétention, scheduler, journal, auth, cibles isolées, imports simulés, quarantaine et round-trip SQLite avec métier/RBAC/audit/notifications. Un test de création de symlink OS peut être skipped sous Windows ; les symlinks malveillants dans ZIP sont testés.

Le round-trip MySQL/MariaDB réel reste à exécuter selon le runbook : aucun binaire client/dump présent dans ce PATH Windows, aucune base réelle créée. Snapshots DB et fichiers ne sont pas atomiques ensemble : coordonner les écritures pour sauvegardes cohérentes. Les ACL Windows doivent être validées indépendamment de chmod. Réplication hors site, supervision, gestion des clés, choix du backup autoritaire et bascule production sont des opérations d'exploitation.

Voir [runbook](BACKUP-RESTORE-RUNBOOK.md), [checklist](PRODUCTION-SECURITY-CHECKLIST.md) et le rapport de validation final ci-dessous, complété après les contrôles.

## Audit dépendances — 7 octobre 2026

Composer audit complet et `--no-dev` : aucun avis ni paquet abandonné. npm `audit --omit=dev` : zéro vulnérabilité déclarée. Tous les signalements npm restants appartiennent à l'arbre dev ; aucun ne concerne ici axios/echo/pusher, qui peuvent être incorporés au navigateur malgré leur classement dev.

Mise à jour compatible effectuée : `source-map-js` 1.2.1 → 1.2.2 dans package-lock ; build final identique. `npm audit fix --ignore-scripts` exécuté sans `--force`, sans résoudre les neuf signalements restants. Le rapport compte les paquets affectés, pas neuf failles indépendantes.

| Paquet dev affecté | Sévérité npm | Origine / recommandation |
| --- | --- | --- |
| shell-quote | critical | GHSA-pqg4-j6r4-53mv ; correctif annoncé ≥1.11.0. concurrently 9.2.4 impose 1.9.0 : attendre une version compatible corrigée ou valider un override dans une tâche dédiée. |
| concurrently | critical | Impact transitif shell-quote ; ne pas exécuter de commandes/arguments non fiables dans les outils dev. |
| braces | high | GHSA-vfj7-8cjw-p6xm ; npm propose migration Tailwind 4 majeure, à valider séparément. |
| chokidar | high | Transitif braces ; même arbre Tailwind 3. |
| micromatch | high | Transitif braces ; même arbre Tailwind 3. |
| fast-glob | high | Transitif micromatch ; même arbre Tailwind 3. |
| tailwindcss | high | Transitifs glob/parser ; migration 3 → 4 requiert tests de configuration/CSS/UI. |
| postcss-selector-parser | moderate | GHSA-rj75-hqrm-r3gf ; correctif annoncé ≥7.1.6, branche actuelle 6.x : pas de remplacement majeur aveugle. |
| postcss-nested | moderate | Transitif selector-parser ; traiter avec l'arbre Tailwind. |

Résultat npm complet : **9 paquets signalés (2 critical, 5 high, 2 moderate)** ; exit code 1 attendu tant qu'ils restent ouverts. Aucun `npm audit fix --force` exécuté. CI isolée et entrées contrôlées recommandées en attendant, puis réaudit après correction. Les mises à jour majeures dépassent les corrections sûres autorisées dans ce LOT.

## Fichiers de la phase 4

Créés :

- `app/Services/Backup/BackupRetentionService.php`
- `tests/Feature/BackupRetentionTest.php`
- `tests/Feature/BackupSecurityFinalizationTest.php`
- `tests/Feature/Auth/PasswordSecurityTest.php`
- `docs/LOT22.md`
- `docs/BACKUP-RESTORE-RUNBOOK.md`
- `docs/PRODUCTION-SECURITY-CHECKLIST.md`

Modifiés (les fichiers des phases précédentes restent non suivis tant que le LOT n'est pas commité) :

- `.env.example`
- `config/backup.php`
- `config/logging.php`
- `package-lock.json`
- `routes/console.php`
- `app/Services/Backup/BackupManager.php`
- `app/Services/Backup/BackupOperationLogger.php`
- `app/Services/Backup/RestoreManager.php`
- `app/Console/Commands/BackupListCommand.php`
- `app/Console/Commands/BackupVerifyCommand.php`
- `app/Console/Commands/BackupRestoreCommand.php`
- `resources/views/livewire/pages/auth/confirm-password.blade.php`
- `resources/views/livewire/pages/auth/forgot-password.blade.php`
- `resources/views/livewire/pages/auth/reset-password.blade.php`
- `tests/Feature/BackupCommandTest.php`
- `tests/Feature/RestoreValidationTest.php`
- `tests/Feature/RestoreRoundtripTest.php`

`config/filesystems.php` reste modifié depuis la phase 1, sans nouveau changement en phase 4. Aucun fichier métier, UI métier, authentification structurelle ou LOT 21 modifié.

## Rapport final de validation

| Contrôle final | Résultat |
| --- | --- |
| `php artisan view:clear` | PASS |
| `php artisan view:cache` | PASS |
| `php artisan test --filter=Backup` | 92 passed, 1 skipped, 492 assertions |
| `php artisan test --filter=Restore` | 66 passed, 312 assertions |
| `php artisan test` | 554 passed, 1 skipped, 3 743 assertions ; 250,29 s |
| `npm run build` | PASS, 63 modules ; mêmes assets CSS/JS |
| `git diff --check` | PASS |
| Composer audit complet et runtime | 0 vulnérabilité, aucun paquet abandonné |
| npm audit runtime (`--omit=dev`) | 0 vulnérabilité |
| npm audit complet | 9 paquets dev signalés, exit 1 ; voir tableau audit |

PHP exécuté via `C:/Users/hp/.config/herd/bin/php84/php.exe` (8.4.25) car absent du PATH. Composer exécuté avec ce PHP et le composer.phar Herd. Tests réellement exécutés après le déplacement du verrou restore ; le nouveau test vérifie la rétention pendant le prompt, l'annulation sans cible écrite et la libération du verrou. Aucun accès ou changement de DB MySQL réelle ; les migrations de tests sont limitées aux bases SQLite de test, jamais migrate:fresh/db:wipe.

Git : branche `feat/lot22-backup-security-finalization`, HEAD `1acf496` conservés ; fichiers LOT 22 modifiés/non suivis, aucun commit, aucun push. Le skip concerne la création de symlink OS interdite sur ce Windows, pas les tests de symlink ZIP.

Verdict de mise en production : **LOT 22 NOT READY**. L'implémentation et les validations MVP locales sont terminées, mais le round-trip MySQL/MariaDB réel, les prérequis/ACL d'exploitation et le traitement ou l'acceptation explicite des avis dev restent nécessaires avant feu vert production. RPO/RTO restent des objectifs d'exploitation. La procédure `mini_erp_restore_test` est préparée dans le runbook ; STOP avant création réelle et avant commit.
