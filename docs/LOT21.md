# LOT 21 — Documents, notifications et audit

## Installation et exploitation

Les dépendances sont verrouillées : Reverb 1.12, Laravel Echo et pusher-js. Aucun Redis n'est nécessaire. Appliquer les trois nouvelles migrations avec `php artisan migrate` après sauvegarde habituelle ; aucune reprise destructive des données historiques n'est prévue.

Les nouvelles pièces utilisent le disque `attachments`, privé (`storage/app/private/attachments`), sans route filesystem automatique. PDF/JPEG/PNG/WebP, maximum 5 Mo, noms générés côté serveur. Les suppressions sont logiques ; aucun fichier n'est purgé. Les justificatifs `Expense.receipt_path` restent sur le disque local et disposent d'un téléchargement autorisé.

Configurer les variables `REVERB_*` et `VITE_REVERB_*` de `.env.example`. L'installation native a généré les identifiants locaux dans `.env`, qui reste exclu de Git. Ne jamais publier le secret Reverb dans les variables Vite. Définir `REVERB_ALLOWED_ORIGINS` avec les noms d'hôtes autorisés séparés par des virgules, sans wildcard. L'adresse publique `REVERB_HOST/PORT/SCHEME` peut différer du listener `REVERB_SERVER_HOST/PORT`. En production, utiliser TLS et un proxy WebSocket adapté. Recompiler le frontend après changement des variables Vite.

Maintenir trois processus :

```sh
php artisan queue:work database --tries=3 --timeout=60
php artisan reverb:start
```

Exécuter `php artisan schedule:run` chaque minute avec cron ou le Planificateur Windows. En développement, `php artisan schedule:work` est possible. Les échéances sont quotidiennes, le stock horaire, en UTC. Les jobs uniques et la contrainte SQL protègent des doublons ; ne pas effacer arbitrairement les verrous.

La notification DB est persistée avant le job broadcast. Le job diffuse le même identifiant ; son retry ne crée pas de ligne supplémentaire. Sans Reverb, le refresh charge les notifications DB. Contrôler `failed_jobs` et les logs en cas de diffusion indisponible. Une interruption entre le commit et l'enqueue peut laisser une notification persistée sans diffusion ; la base reste disponible.

Les alertes finance ciblent `payments.view`, celles du stock `stock.access`, plus Super Administrateurs actifs. Les comptes inactifs ou devant changer leur mot de passe sont exclus. Déduplication : facture/échéance/type, relance/type, produit/dépôt/type/jour par destinataire.

## Audit

Les domaines non couverts portent explicitement `RecordsOperations`. Les transactions métier existantes incluent leurs traces ; les changements de rôles/permissions et les attachments sont instrumentés explicitement. Ne pas utiliser des mises à jour SQL directes pour les domaines audités sans trace explicite.

`operation_histories` complète, sans répliquer, les historiques commandes, réceptions, factures fournisseurs, stock et relances. `/admin/audit` affiche des sections paginées. Les historiques passés ne sont pas reconstruits. Les secrets sont exclus, y compris dans les JSON imbriqués. Le journal est immuable dans les modèles et l'UI ; il ne protège pas d'un administrateur SQL privilégié.

## Vérifications

Les tests utilisent SQLite en mémoire et des événements/queues simulés : aucun serveur Reverb nécessaire. Un contrôle navigateur avec les processus actifs reste nécessaire pour valider l'environnement WebSocket de déploiement. Aucun processus permanent n'est démarré automatiquement par ce lot.

Aucune purge de fichiers, notifications ou audit n'est incluse. Prévoir une politique de conservation distincte. Un arrêt brutal pendant un upload peut nécessiter une vérification des fichiers orphelins ; les exceptions normales déclenchent le nettoyage.
