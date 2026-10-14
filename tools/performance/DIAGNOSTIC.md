# Diagnostic de performance Mini ERP — 13 octobre 2026

Le poste principal est le **premier chargement PHP des vues Blade compilées nouvellement écrites**. Le problème est reproductible avec un cache de vues isolé vide, puis disparaît largement au second passage. Les calculs financiers, MySQL et les permissions ne consomment pas les 2–3 secondes observées.

Aucun fichier applicatif, aucune permission, aucun calcul financier ni test fonctionnel n'a été modifié. `.env` est inchangé. Aucun commit, push ou merge. Les modifications de `composer.json` et `composer.lock` existaient avant cette analyse.

## A. Cause principale

### Où le temps est dépensé

- `vendor/laravel/framework/src/Illuminate/View/Engines/CompilerEngine.php`, `get()`, vers la ligne 75 : évaluation du fichier PHP compilé après vérification et compilation éventuelle.
- `vendor/livewire/livewire/src/Mechanisms/ExtendBlade/ExtendedCompilerEngine.php`, `evaluatePath()`, vers la ligne 40 : `include $__path` dans le contexte du composant Livewire.
- Pour les vues hors contexte Livewire : `PhpEngine::evaluatePath()` → `Filesystem::getRequire()` → `require $__path`.
- Vues les plus touchées sur Reports : `resources/views/components/report-period.blade.php`, `resources/views/livewire/admin/reports-manager.blade.php`, pagination Tailwind, puis layout/sidebar/navigation.

### Mesure reproductible

Dans le test final Reports, Debugbar activé, cache isolé vide :

| Poste | Durée |
|---|---:|
| Requête complète, démarrage inclus | 3 320 ms |
| Même cache, deuxième passage | 524 ms |
| Compilation Blade cumulée, premier passage | 55 ms |
| Rendu du composant report-period | 1 342 ms |
| Rendu Blade reports-manager | 2 002 ms |
| Pagination Tailwind, premier tableau | 388 ms |
| Sidebar | 440 ms |
| Layout complet | 832 ms |

Ces rendus sont **imbriqués** : ne pas additionner leurs durées. `reports-manager` comprend `report-period` et la pagination ; le layout comprend sidebar et navigation.

Une seconde expérience ajoute un marqueur avant la première instruction des fichiers PHP du **cache isolé uniquement**. Le délai entre la demande de chargement du fichier compilé et sa première instruction atteint 1 505 ms pour `report-period`, 821 ms pour `reports-manager` et 432 ms pour la pagination. Tous les marqueurs cumulés prennent environ 5 581 ms à froid contre 6,5 ms au second passage. Cette expérience réécrit les fichiers générés et perturbe leur chargement : son total de 8,89 s n'est donc pas une mesure représentative de la page normale. Elle localise néanmoins le coût **avant l'exécution du contenu des vues**, et non dans leurs calculs métier.

Les contrôles d'expiration du cache prennent environ 1 ms cumulée dans une autre reproduction ; la compilation elle-même environ 58 ms. La transformation Blade → PHP ne suffit donc pas à expliquer le ralentissement.

### Pourquoi c'est lent

Le coût apparaît lorsque PHP charge pour la première fois des fichiers compilés fraîchement créés. La réutilisation des mêmes fichiers supprime plusieurs secondes. Cela identifie le poste d'exécution, mais **pas encore la cause système ultime**. Un filtrage antivirus/EDR ou un autre coût d'ouverture/analyse de fichiers sous Windows est une hypothèse compatible ; aucun profil système ne permet ici d'attribuer formellement ce temps à Defender, au disque ou au parseur PHP. OPcache CLI est désactivé ; l'état OPcache du serveur Herd n'a pas été mesuré.

## B. Causes secondaires et chemin d'exécution

### Permissions : duplication confirmée, impact secondaire

`app/Models/User.php:151`, `isSuperAdministrator()`, appelle à chaque fois :

```php
$this->roles()->where('slug', 'super-admin')->exists();
```

`roles()` retourne un nouveau constructeur de requête. `exists()` exécute SQL et ne charge pas la relation en mémoire. Précharger `$user->roles` seul ne changerait donc pas cette méthode.

`hasPermission()` vers la ligne 174 appelle cette vérification pour chaque permission enregistrée. `AppServiceProvider` définit les Gates ERP avec `hasPermission()`. Son `Gate::before` refuse les comptes inactifs ou avec changement de mot de passe obligatoire ; il ne mémorise pas le statut super-admin.

`resources/views/components/sidebar.blade.php:61` exécute `Gate::allows()` pour **24 entrées**. Les Gates `users.administer` et `company.administer` font plusieurs vérifications super-admin internes. S'ajoutent le breadcrumb, la route, les composants et les liens de notifications. Sur Reports : **40 Gates, 42 requêtes super-admin** ; sur les commandes : **38 requêtes super-admin**. Le nombre de Gates et le nombre de requêtes ne sont donc pas identiques.

À chaud, sans Debugbar : ensemble des Gates Reports ≈ **27 ms**, sidebar complète ≈ **18 ms**. Avec Debugbar : Gates ≈ **63 ms**, sidebar ≈ **43 ms**. Ces temps incluent les requêtes et leur traitement PHP ; ils sont compris dans les rendus concernés.

### Reports

1. `routes/web.php:33` : `Route::view`, middleware `web`, `auth`, `auth.session`, `verified`, `can:reports.view`.
2. Groupe `web` : locale, compte actif, mot de passe changé. Pas de calcul financier dans ces middleware. Le routage de la capture initiale prend environ 2,3 ms.
3. `admin/reports/index.blade.php` monte `admin.reports-manager` dans `x-app-layout`. Le contenu du slot est rendu avant le layout final ; la simplicité de l'index n'exclut donc pas un coût important de rendu enfant.
4. `FiltersReportPeriod::mount()` → `preset('month')` → `applyPeriod()` → `ReportService::period()` : plusieurs autorisations et une validation de période, puis remise à zéro des quatre paginations.
5. `reports-manager::with()` : une seule section active. Pour sales : `currency()`, `salesSummary()`, table sales, table credits, `displayTable()` pour chaque table, puis `monthlySummary()`.
6. `Volt::Component::render()` appelle `with()` une fois dans les passages observés. Les compteurs ne montrent pas de répétition du résumé ou de la synthèse mensuelle pendant un même GET. Aucune computed property coûteuse sur ce chemin.
7. `ReportService::salesSummary()` : six agrégats/count SQL ; `monthlySummary()` : trois requêtes groupées. Les calculs majeurs sont déjà réalisés en SQL, avec arithmétique décimale pour les résultats. Pas de chargement de toutes les factures pour les additionner en PHP.
8. `table()` : `paginate(12)`, count puis select si le total est non nul. Deux paginations distinctes : page et creditsPage. Avec zéro avoir sur la période mesurée, le select de données est évité.
9. Les lignes sales/credits utilisent les noms clients stockés sur les documents. Pas d'accès à une relation client par ligne. Les autres branches chargent explicitement les relations de paiements, dépenses, stocks, mouvements et dernière relance ; elles n'ont pas été chronométrées individuellement, car le GET initial ouvre sales.
10. Les casts date/decimal et `BigDecimal` du formatage sont peu coûteux ici. Le formatage mesuré du tableau sales est environ **0,4 ms** ; celui des avoirs est presque nul car le tableau est vide. Ne pas généraliser ce dernier chiffre à un tableau rempli.

### Commandes clients

`routes/web.php:264` → `admin/sales-orders/index.blade.php` → `sales-orders-manager`.

`mount()` et `with()` autorisent `sales.view`. La liste exécute `paginate(10)` et précharge `customer`, `sourceQuote` et `creator`. `isEditable()` ne fait que vérifier statut et archive. `@can('sales.update')` est appelé par ligne modifiable ; `@can('sales.create')` est appelé une fois. Le rendu complet du composant, requêtes comprises, prend environ **20 ms sans Debugbar à chaud**.

Le sélecteur charge tous les clients, sans limite : point à surveiller pour une grosse base, mais aucune preuve qu'il explique les secondes sur la base actuelle. Le préchargement de `customer` semble inutile pour la vue actuelle, qui affiche `customer_name` ; gain potentiel modeste.

### Layout et autres postes

- `layouts/app.blade.php` rend une sidebar, la navigation Volt et la cloche de notifications.
- `page-context.blade.php` vérifie la permission du lien courant.
- La cloche lit cinq notifications et leur nombre non lu. `NotificationLinkResolver::links()` utilise des requêtes groupées, et vérifie deux Gates, même quand un groupe d'identifiants est vide.
- `auth()->user()` réutilise l'utilisateur du guard ; chaque appel ne signifie pas une nouvelle requête utilisateur.
- Les événements de modèles inspectés concernent les écritures (`creating`/`updating`/`deleting`, audit des mutations), pas un travail coûteux déclenché par lecture sur ces pages. Pas d'accessor, de scope global ni d'observateur de lecture identifié comme cause des secondes.
- Traductions, Carbon, money(), résolution du container : aucune mesure ne justifie une optimisation spécifique. Les polices externes et JavaScript affectent éventuellement l'affichage navigateur, mais n'expliquent pas les durées serveur Debugbar.

## C. Preuves et limites

### Captures serveur déjà présentes

| Route | Capture lente | Capture suivante à chaud | SQL lent / chaud | Requêtes |
|---|---:|---:|---:|---:|
| /reports | 3 027 ms | 682 ms | 80 / 92 ms | 64 / 64 |
| /sales/orders | 2 228 ms | 556 ms | 88 / 93 ms | 53 / 53 |

Sources : `storage/debugbar/01M4V20KDHD86D9XGN38N454K3.json`, `01M4V2XKJW94DXEM0EDFV0HRX1.json`, `01M4V1YSZQP6RB42YCMEWA4RWC.json`, `01M4V2SHBFN0GZWYJMV4ZK07W4.json`. Leur résumé sans données métier est dans `results/historical.json`.

### Comparaison finale DEBUGBAR_ENABLED, quatre processus par configuration

La variable est définie avant le démarrage Laravel dans chaque processus. `.env` n'est pas modifié. Cache existant à chaud, utilisateur local 1, réponses 200 :

| Route | true : médiane (min–max) | false : médiane (min–max) | Différence des médianes |
|---|---:|---:|---:|
| /reports | 526 ms (511–538) | 367 ms (359–372) | 158 ms |
| /sales/orders | 494 ms (475–511) | 347 ms (324–362) | 147 ms |

Debugbar ajoute donc un coût significatif, mais il n'explique pas seul les secondes à froid.

### Cache vide / deuxième passage, comparaison finale

| Route | Debugbar | Premier passage | Deuxième passage | Compilation cumulée à froid |
|---|---|---:|---:|---:|
| /reports | true | 3 320 ms | 524 ms | 55 ms |
| /reports | false | 3 047 ms | 415 ms | 61 ms |
| /sales/orders | true | 1 723 ms | 503 ms | 35 ms |
| /sales/orders | false | 1 622 ms | 388 ms | 55 ms |

Le coût à froid varie : une précédente reproduction des commandes atteignait 2 429 ms. Les écarts froid/chaud ne sont pas assimilables au seul surcoût de Debugbar.

### Reports : médianes par opération, à chaud

| Opération | Debugbar false | Debugbar true |
|---|---:|---:|
| currency() | 1,75 ms | 3,08 ms |
| salesSummary() | 10,29 ms | 15,85 ms |
| Table principale : autorisation, requête, pagination, hydratation | 2,99 ms | 5,61 ms |
| Table principale : boucle de formatage | 0,40 ms | 0,40 ms |
| Table secondaire credits : requête/pagination | 1,71 ms | 3,69 ms |
| Table secondaire : formatage, aucune ligne | 0,003 ms | 0,004 ms |
| monthlySummary() | 4,99 ms | 8,03 ms |
| Livewire mount | 14,98 ms | 17,87 ms |
| Livewire render, with() et Blade inclus | 31,02 ms | 48,98 ms |
| Livewire dehydrate/snapshot | 3,04 ms | 3,11 ms |
| Tous les Gates | 27,22 ms | 63,47 ms |
| Sidebar complète | 17,75 ms | 42,55 ms |

Ces postes se recouvrent ; les autorisations des services font partie de leur durée. Le formatage chronométré correspond à l'itération réelle de `displayTable()`, hors construction des en-têtes et du tableau de retour. Les résultats bruts sont conservés dans `results/`, les médianes dans `results/summary.json`.

Les sondes comptent **62 requêtes Reports / 51 commandes**, contre **64 / 53** dans le navigateur : la session de diagnostic est en mémoire et ne fait pas les deux accès SQL de la session persistante. La requête de lecture utilisateur est comptée. Les doublons sont définis comme répétitions excédentaires du même SQL et des mêmes paramètres : **43 / 38**, dont **42 / 38** appels super-admin au total. Cette définition diffère de l'indicateur « duplicated queries » affiché dans la demande (46 / 40).

Mesures réalisées avec **PHP CLI 8.4.25**, même code et base locale, OPcache CLI désactivé. Les totaux incluent autoload, bootstrap et préparation de sonde ; le kernel seul représente environ 120 ms pour Reports et 98 ms pour les commandes sans Debugbar. Il ne s'agit **pas de TTFB Chrome ni d'une mesure PHP-FPM Herd**. Les captures Debugbar d'origine constituent la preuve du comportement web ; les sondes reproduisent et décomposent ce comportement. Aucun hydrate/update Livewire POST n'a été simulé.

## D. Corrections proposées — aucune appliquée

### Impact élevé / faible risque

1. Préparer et conserver le cache des vues avant le trafic utilisateur, et éviter les `view:clear` / `optimize:clear` systématiques pendant la navigation. Laravel documente `php artisan view:cache` pour précompiler les vues : https://laravel.com/docs/12.x/views#optimizing-views. Vérifier ensuite les vues/fragments Volt qui restent générés à la demande et réchauffer les routes autorisées. Cette étape vise le poste dominant mesuré ; `view:cache` seul ne garantit pas que tous les fichiers générés de Volt soient prêts.
2. Désactiver Debugbar quand la collecte n'est pas nécessaire ; le gain à chaud mesuré est environ 150 ms. Le garder activable pour le diagnostic. Aucun changement de permission ou de calcul.
3. Examiner le chargement de nouveaux fichiers PHP avec un profil système local et contrôler OPcache du PHP utilisé par Herd. Cela permet de confirmer ou d'écarter antivirus/EDR et attente fichier. **Aucune exclusion antivirus ni modification système n'a été effectuée ou validée.**

### Impact moyen

4. Introduire une résolution d'autorisations **limitée à la requête**, mémorisant le statut super-admin et les permissions/rôles pour l'utilisateur courant. Conserver les Gates et tous les contrôles : permission connue, compte actif, mot de passe obligatoire, restrictions spécifiques des Gates d'administration. Utiliser un service `scoped`, sans cache statique ni partage entre requêtes/utilisateurs ; prévoir l'invalidation si les rôles changent pendant la même requête. Une révocation doit être relue dès la requête suivante, y compris Livewire. Charger la relation seule ne suffit pas : les méthodes actuelles utilisent toujours `roles()` et `exists()`.
5. Dans la sidebar, réutiliser la décision de chaque ability unique pendant ce rendu. Cela réduit les vérifications répétées de `sales.view`, `payments.view`, etc., sans masquer l'autorisation des routes ou des actions. Le gain attendu est de quelques dizaines de millisecondes, pas deux secondes.

### Facultatif

6. Grouper les agrégats compatibles de `salesSummary()` et précharger moins de relations inutilisées dans les commandes ; uniquement avec vérification des résultats financiers et des permissions. Les gains attendus sont faibles sur cette base.
7. Remplacer le sélecteur de tous les clients par une recherche paginée si sa cardinalité augmente. Ne pas modifier la pagination des rapports sans besoin UX : `simplePaginate()` supprimerait le total et changerait le comportement.
8. Si Debugbar doit rester activé, comparer séparément ses collecteurs de vues, backtraces SQL et Gates. Aucun collecteur précis n'a été isolé dans cette étude ; lui attribuer tout le surcoût serait prématuré.

## E. Estimation attendue

- Reports : **3,03 s observées à froid → environ 0,5–0,8 s avec cache chaud et Debugbar**, déjà corroboré par une capture web à 682 ms et les reproductions CLI à 524 ms.
- Commandes : **2,23 s → environ 0,5–0,7 s avec cache chaud et Debugbar**, corroboré par les captures web à 556–573 ms et la reproduction à 503 ms.
- Cache chaud, Debugbar désactivé : **367 ms Reports / 347 ms commandes en médiane CLI**. Une cible web de 0,35–0,6 s est plausible, mais doit être vérifiée sur Herd ; ce n'est pas une promesse de TTFB.
- La mémorisation des autorisations réduira fortement les doublons, avec un gain supplémentaire probablement de quelques dizaines de millisecondes sur cette base. Aucun chiffre « après optimisation des permissions » n'a été mesuré, car le code métier n'a pas été modifié.

## Instrumentation livrée

`tools/performance/probe.php` s'exécute uniquement en CLI et refuse les environnements autres que local. L'identité utilisateur existe uniquement dans le processus ; aucune connexion persistante n'est créée. Toutes les autorisations normales restent exécutées. Les sessions de mesure sont en mémoire et les sorties contiennent des timings/compteurs, sans SQL ni paramètres métier. Le processus n'appelle pas `terminate()` ; il mesure la génération de la réponse, pas les travaux terminables après envoi.

Exemples PowerShell :

```powershell
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' tools/performance/probe.php /reports true
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' tools/performance/probe.php /reports false
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' tools/performance/probe.php /sales/orders false
```

Pour comparer cache vide / chaud, passer un identifiant nouveau puis réutiliser exactement le même :

```powershell
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' tools/performance/probe.php /reports false 1 diagnostic-nouveau
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' tools/performance/probe.php /reports false 1 diagnostic-nouveau
```

Les caches expérimentaux sont sous `storage/framework/performance-views/` ; le cache normal n'est ni effacé ni remplacé. L'argument supplémentaire `loadtrace` ajoute les marqueurs de première instruction uniquement au cache isolé et perturbe les timings à froid ; le réserver à la localisation du chargement. Aucune sonde ne s'active pendant les requêtes web ordinaires. Vérification syntaxique PHP réussie ; les réponses de comparaison sont toutes 200. Aucun test fonctionnel n'a été changé.
