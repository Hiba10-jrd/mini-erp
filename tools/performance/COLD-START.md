# Diagnostic du cold start Blade / Volt / PHP sous Herd Windows

## Conclusion mesurée

Deux problèmes distincts sont confirmés :

1. **Les clés de cache de `view:cache` diffèrent des clés réellement utilisées
   par les vues sur ce checkout Windows.** La commande standard compile les
   `getRealPath()` normalisés en antislashs. Le `FileViewFinder` renvoie des
   chemins comportant aussi des slashs. `Compiler::getCompiledPath()` hache la
   chaîne reçue sans normaliser ces séparateurs. Le même fichier physique a
   donc deux caches PHP différents. Après `view:cache`, les fichiers attendus
   à l'exécution restent absents et sont recréés lors de la requête.
2. **Le premier accès aux PHP nouvellement écrits est très lent.** Une expérience
   web, sans Laravel ni SQL ni exécution de template, mesure 7,44–7,71 secondes
   pour lire une copie de PHP compilé de 98 913 octets avec `file_get_contents`.
   La compilation PHP qui suit ne prend que 9,86–14,75 ms. Ceci localise une
   attente d'accès aux fichiers ; cela ne prouve pas à lui seul quel pilote,
   antivirus/EDR ou mécanisme Windows la provoque.

La seconde observation explique pourquoi une compilation Blade rapide peut
être suivie de plusieurs secondes de latence. Modifier le service financier,
les requêtes métier ou le RBAC ne traite pas cette attente.

## Portée et limites

Les mesures de pages utilisent PHP Herd CLI 8.4.25 et le vrai kernel HTTP
Laravel, Debugbar OFF, l'utilisateur local existant 1 et une session en mémoire
uniquement pour la sonde. Toutes les permissions et middlewares restent actifs.
Le warm-up applicatif n'utilise, lui, aucun utilisateur, aucune session et ne
rend aucune page. Les comptages SQL reflètent le checkout actuel, où le service
RBAC précédemment ajouté n'est plus présent : il n'a pas été restauré.

Les durées de pages ne sont **pas** des TTFB Chrome/Herd web authentifiés. Aucun
cookie/session de navigateur n'a été extrait ou fabriqué. Les expériences
OPcache et lecture/compilation de fichiers utilisent réellement Herd web,
PHP 8.4.25 `cgi-fcgi`, via une sonde temporaire loopback + jeton + expiration.
Son résultat ne représente pas une requête applicative authentifiée.

Les traces ajoutent un marqueur uniquement dans des caches isolés. Leur écriture
supplémentaire perturbe les accès à froid : utiliser ces traces pour localiser
les postes, pas pour annoncer un TTFB ou un gain exact. Les séries standard
`view:cache`/`optimize` présentent aussi des temps SQL anormalement élevés :
leurs totaux doivent être lus comme des observations sous charge, pas comme une
preuve que ces commandes créent à elles seules 20 secondes de latence.

## Cache vide puis deuxième et troisième passage

Temps **kernel**, millisecondes, après les purges de fichiers de la matrice
originale. Les 19/14/11/17 nouveaux fichiers ne sont créés qu'au premier passage.

| Route | Premier | Deuxième | Troisième | Blade premier | Nouveaux PHP |
|---|---:|---:|---:|---:|---:|
| /reports | 3206,91 | 150,67 | 171,04 | 85,68 | 19 |
| /sales/orders | 1478,72 | 119,76 | 132,27 | 40,61 | 14 |
| /finance/cash | 5529,46 | 120,42 | 119,85 | 47,04 | 11 |
| /administration/suppliers | 3477,57 | 131,73 | 116,80 | 59,89 | 17 |

Le temps total CLI inclut aussi bootstrap et préparation de la sonde. Toutes
les mesures détaillées, SQL inclus, sont dans `results/cold-matrix.json`.

La matrice originale a exécuté `optimize:clear --except=cache`, sans purger le
cache en base. Le script conservé utilise désormais exclusivement
`config:clear`, `route:clear`, `event:clear`, `view:clear`, puis restaure l'état
initial des caches config/routes/events dans `finally`. `optimize` a été testé
avant restauration. Aucun `cache:clear` n'est nécessaire pour reproduire.

## Ce que couvre réellement view:cache

L'audit `results/view-coverage.json` montre le défaut de clés sur le layout,
sidebar, page-context, report-period, navigation, notification-bell, managers
Volt et pagination Tailwind. La commande standard connaît ces sources mais ne
prépare pas les fichiers correspondant aux chaînes de chemin du runtime.

Exemple Reports, même source physique :

- runtime : `resources\views/admin/reports/index.blade.php` → `bbdb1a07cd6c5582ed695b9f7f0d77c2.php` ;
- commande standard : `resources\views\admin\reports\index.blade.php` → `7c97a023bda193e3d463319a917768bb.php`.

Après `view:cache` ou `optimize`, les requêtes créent toujours respectivement
19, 14, 11 et 17 fichiers PHP. Une fois les chemins runtime précompilés par
`app:warmup`, les premiers passages ne créent plus aucun fichier et n'appellent
plus le compilateur Blade sur les quatre pages testées.

## Rôle de Volt et fichiers responsables

Les composants concernés sont des composants Volt à classes anonymes. Dans
la version installée, `ComponentFactory::make()` requiert le fichier source
pour obtenir la classe, puis invalide ce source via `opcache_invalidate` si
OPcache est disponible. Ces composants ne créent pas une classe PHP persistante
via `CompiledComponentFile` à chaque requête. Leurs templates restent compilés
par Blade. Les fragments `@volt`, s'ils sont ajoutés plus tard, ont un autre
cycle de génération ; aucun n'a été trouvé dans les vues actuelles.

Dans les traces corrigées, la résolution des trois classes Volt d'une page
totalise environ 5,6–6,9 ms à froid. Elle n'explique pas les secondes perdues.
Les gros postes mesurés avant la première instruction du PHP compilé incluent :

- Reports : report-period ≈ 1,35 s, navigation ≈ 0,86 s, reports-manager ≈ 0,76 s ;
- Commandes : sales-orders-manager ≈ 1,05 s, navigation ≈ 0,82 s ;
- Caisse : cash-manager ≈ 4,92 s ;
- Fournisseurs : suppliers-manager ≈ 6,20 s.

Les charges PHP incluent ouverture/lecture et compilation PHP jusqu'au marqueur.
La lecture web contrôlée isole ensuite cette attente de la compilation PHP.
Les postes non instrumentés du kernel ne sont pas arbitrairement attribués à
Blade, OPcache ou Defender : ils restent du temps Laravel/I/O non décomposé.
Le SQL des traces corrigées reste autour de 27–41 ms au premier passage.

## OPcache et file_update_protection

OPcache web reste actif, cache non plein, sans redémarrage pour saturation.
Les informations complètes sont conservées dans les fichiers `results/web-*.json`.

Expérience contrôlée, mêmes octets, trois fichiers distincts, aucune exécution
de template :

| Cas | Protection | Première lecture ms | Compilation ms | En cache | Recompilation ms |
|---|---:|---:|---:|---|---:|
| frais | 2 | 7705,62 | 9,86 | non | 6,01 |
| antérieur au début de requête | 2 | 7478,62 | 13,12 | oui | 0,0076 |
| frais, réglage limité à la sonde | 0 | 7444,96 | 14,75 | oui | 0,0050 |

La valeur 2 empêche l'admission immédiate d'un fichier trop récent, sans
imposer un sleep de deux secondes. L'expérience a remis la valeur initiale
dans `finally`, sans modifier de php.ini ni le réglage des requêtes normales.
Changer la valeur à 0 ne supprime pas la lecture lente : ce n'est donc pas la
correction minimale recommandée. Le premier test vieillissait le fichier par
rapport à l'heure courante ; le test contrôlé utilise un timestamp antérieur
au début de requête, pour éliminer ce biais lors des longues compilations.

Le cache CLI ne partage pas celui de Herd web ici : un script compilé dans un
processus CLI avec `-d opcache.enable_cli=1` est absent côté web, et les caches
ont des heures de démarrage différentes. Ne pas présenter un warm-up CLI comme
un remplissage d'OPcache web.

## Solution livrée et limites

Premiers passages après préparation, temps kernel (les trois passages sont
dans les JSON, sans aucun fichier créé ni compilation Blade) :

| Route | Premier kernel ms | Deuxième ms | Troisième ms | Premier total CLI ms |
|---|---:|---:|---:|---:|
| /reports, contrôle isolé final | 140,51 | 140,96 | 132,68 | 423,42 |
| /sales/orders | 237,58 | 160,00 | 112,54 | 519,38 |
| /finance/cash | 114,40 | 136,31 | 121,38 | 389,43 |
| /administration/suppliers | 124,00 | 104,32 | 97,90 | 384,46 |

Le premier essai global Reports a conservé 5855,62 ms de kernel malgré zéro
fichier généré (44,24 ms SQL). Il n'est pas masqué : il est conservé dans
`results/after-warmup.json`. Le contrôle final a supprimé seulement les 19
fichiers runtime identifiés de Reports, puis les a préparés par la commande
avant les trois passages. Ce warm-up ciblé a pris 4026 ms, contre environ
5 minutes pour créer/lire tous les fichiers depuis un cache entièrement vide.
La variante `--php` a pris 4268 ms, puis 150,06 / 136,18 / 143,94 ms kernel :
aucun gain supplémentaire n'est démontré par cette variante. Ces essais ne
sont pas une garantie de latence constante, ni une mesure web des pages.

Le test web final compile sans exécution les 15 scripts de l'audit de couverture.
Ils sont tous en OPcache après le premier passage ; 37,01 ms serveur à la
première compilation, puis 9,24 / 10,65 ms. Les misses passent de 1363 à 1378
puis restent à 1378 ; les hits augmentent de 45 à chaque recompilation à chaud.
Le total des scripts passe de 1146 à 1150 (certains chemins étaient déjà connus
du cache partagé). Les TTFB réels de cette **sonde**, 44 / 18 / 38 ms, ne sont
pas des TTFB des routes applicatives. Voir `results/web-opcache-metrics.json`.

`app:warmup` prépare les vues avec le `FileViewFinder`, donc avec les mêmes
chemins que le runtime. Il respecte les surcharges de namespaces, ne purge pas
les vues existantes, ne rend pas de page et n'exécute aucun `mount`, `with`, Gate
ou calcul financier. Il lit les PHP générés pour préparer les accès aux fichiers.
La commande est idempotente. Sur cette machine, préparer/lire tous les 295
chemins a pris environ 313–343 secondes : ce coût doit rester hors du trafic.

```powershell
php artisan app:warmup
# Optionnel : compilation PHP sans exécution, OPcache CLI éphémère uniquement.
php -d opcache.enable_cli=1 artisan app:warmup --php
# Production seulement, après configuration de l'environnement du déploiement.
php artisan app:warmup --deployment
```

L'option `--php` ne remplit pas l'OPcache web. `--deployment` prépare seulement
config/routes/events, sans `cache:clear`, et refuse les environnements autres
que production. Ne pas lancer `view:cache` après `app:warmup`, car la première
commande effacerait les fichiers runtime préparés.

En développement : conserver les vues, éviter les purges systématiques, lancer
le warm-up après une purge volontaire et garder Debugbar OFF pour mesurer.
En production : préparer les caches de fichiers dans le déploiement avant de
servir le trafic ; traiter séparément le warm-up du vrai worker PHP. La
précompilation réduit les générations à la demande, mais ne garantit pas
l'élimination de toute première lecture sous Windows, notamment Reports.

## Mesure sûre du composant Windows responsable

Ne pas désactiver Defender et ne pas ajouter d'exclusion pour ce diagnostic.
Un enregistrement de performance peut corréler les lectures lentes avec les
scans, sans modifier la protection. Dans une PowerShell administrateur, depuis
le projet, enregistrer, reproduire UNE lecture froide, puis arrêter
l'enregistrement selon l'invite du cmdlet :

```powershell
New-MpPerformanceRecording -RecordTo "$PWD\tools\performance\results\defender-cold.etl"
Get-MpPerformanceReport -Path "$PWD\tools\performance\results\defender-cold.etl" -TopFiles 20 -TopProcesses 10 -TopScans 20
```

Alternative : Process Monitor, filtrer `php.exe` / `php-cgi.exe`, le chemin
`storage\framework\views`, les opérations CreateFile/ReadFile ; examiner la
colonne Duration et les stacks. Corréler avec les timestamps des fichiers et
les fenêtres des sondes. Ces captures ne sont pas lancées automatiquement.

Sources : [analyseur Microsoft](https://learn.microsoft.com/en-us/defender-endpoint/performance-analyzer-reference),
[Process Monitor](https://learn.microsoft.com/en-us/sysinternals/downloads/procmon),
[configuration OPcache](https://www.php.net/manual/en/opcache.configuration.php).

## Vérifications et nettoyage

Les tests ciblés de la commande vérifient les 15 chemins principaux, l'absence
de SQL/authentification et l'idempotence ; la préparation locale ne crée pas
de caches config/routes/events de déploiement. Le test supplémentaire vérifie
le refus de `--php` quand OPcache CLI n'est pas actif.
Les deux tests de préparation ont passé : 331 assertions, sans SQL. Le test
de refus PHP a passé séparément : une assertion. Les branches CLI actives ont
aussi été exécutées dans le contrôle final Reports. Aucun test existant n'est
modifié.

Les sondes web, le jeton, l'expérience CLI-sharing et les caches isolés créés
par les traces sont temporaires et sont retirés à la fin. `public/index.php`
est restauré exactement. Les sondes CLI reproductibles, ce rapport, les
résultats JSON, la commande/service de warm-up et leurs tests sont conservés.
Les dossiers performance/debugbar préexistants ne sont pas supprimés.
