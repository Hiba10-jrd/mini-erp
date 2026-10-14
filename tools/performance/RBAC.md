# Optimisation RBAC par requête

## Architecture

`RequestRbacMemo` est enregistré avec `scoped()`. Le middleware global
`ScopeRbacMemo` initialise une mémorisation vide avant chaque requête HTTP
(GET, POST et Livewire) et la détruit dans `finally`, même en cas d'exception.
Les appels hors de cette portée continuent à interroger la base.

Seules les lectures élémentaires sont mémorisées : présence d'un rôle,
présence du rôle `super-admin`, et possession d'une permission connue.
Les refus sont également mémorisés. La clé contient le modèle utilisateur,
le nom de connexion, la base, l'identifiant utilisateur, le type de lecture
et le rôle/permission. Ces faits SQL sont indépendants du guard.

Le catalogue des permissions est contrôlé avant la mémorisation. Aucun
résultat final de Gate/policy n'est mémorisé. `Gate::before` continue à
vérifier le compte actif et le changement de mot de passe obligatoire.
Les restrictions d'administration et tous les appels Gate/@can/policies
restent exécutés, y compris dans la sidebar, page-context, notification-bell
et NotificationLinkResolver. Ces quatre fichiers ne sont pas modifiés.

`UserManagementService` invalide les lectures de l'utilisateur dont les rôles
ont été synchronisés. `RolePermissionManagementService` invalide seulement
les permissions effectivement ajoutées/retirées, pour les utilisateurs déjà
mémorisés et affectés au rôle. Les entrées des autres utilisateurs et des
permissions inchangées sont conservées.

Une mutation SQL/pivot directe hors de ces services ne déclenche pas cette
invalidation pendant la requête courante. Elle est néanmoins relue à la
requête suivante. Toute future voie de mutation RBAC devra appeler le service
d'invalidation. Une modification externe concurrente est également visible
à la requête suivante : les lectures mémorisées représentent les faits déjà
lus pendant la requête courante.

## Tests

`tests/Feature/RequestRbacMemoTest.php` couvre la répétition des lectures,
les refus, la séparation des utilisateurs/permissions, les permissions
inconnues (y compris super-admin), les blocages compte/mot de passe,
les révocations HTTP et Livewire, l'invalidation ciblée par les deux services
et le nettoyage après exception. Les tests existants sont conservés.

## Méthode de mesure

`rbac-benchmark.php` est CLI-only et refuse les environnements autres que
`local`. Il appelle le véritable kernel HTTP Laravel avec l'utilisateur
local 1, sans connexion persistante ni modification des rôles/données métier.
Les Gates et middlewares d'autorisation restent actifs. La session est en
mémoire uniquement dans ce processus de diagnostic. L'optimisation RBAC
elle-même n'utilise aucune session.

Debugbar est activé dans les deux modes. Les sorties ne contiennent que
timings et compteurs, sans SQL, paramètres métier ni contenu des pages.
Le temps total comprend bootstrap et configuration de la sonde jusqu'à la
réponse du kernel ; ce n'est pas un TTFB Chrome/Herd web. La lecture préalable
de l'utilisateur et les accès à une session SQL persistante ne sont pas
comptés. Cela explique notamment les 53 requêtes de référence de la sonde
sur cash, contre les 56 rapportées dans Chrome.

Les doublons sont les répétitions excédentaires d'un couple SQL/paramètres
identique. `super_admin_queries` compte les `exists` avec `super-admin`.

`results/rbac-before.json` contient la référence avant changement du code.
`results/rbac-after.json` contient une première série après changement,
perturbée pendant les tests : ne pas utiliser ses temps pour conclure.

`measure-rbac.ps1` réalise une comparaison isolée et alternée : échauffement
exclu, puis cinq passages par route et par mode. Le mode `baseline` désactive
uniquement la portée de mémorisation dans le processus CLI, ce qui reproduit
les lectures RBAC sans mémorisation ; aucun contrôle d'autorisation n'est
désactivé. Ce contrôle complète la référence originale, sans restauration du
code ni modification du fonctionnement web.

Les échantillons sont dans `results/rbac-paired.json`, les médianes dans
`results/rbac-summary.json`.

```powershell
./tools/performance/measure-rbac.ps1
```

Aucune modification des vues Blade/Volt, de ReportService, des calculs
financiers, de Reverb, d'OPcache, du `.env` ou de Windows. Aucune opération
commit/push/merge.

## Résultats

Mesures finales isolées : PHP Herd CLI 8.4.25, utilisateur 1, Debugbar actif,
cinq passages mesurés par mode après échauffement, ordre alterné. Les
comptages du mode de contrôle correspondent à la référence originale.
Toutes les réponses sont HTTP 200. Les valeurs de temps sont des médianes.

| Route | Requêtes avant → après | Doublons avant → après | Super-admin avant → après | SQL ms avant → après | Total ms avant → après |
|---|---:|---:|---:|---:|---:|
| /finance/cash | 53 → 16 | 37 → 0 | 37 → 1 | 31,66 → 11,00 | 516,23 → 451,33 |
| /reports | 61 → 19 | 43 → 1 | 42 → 1 | 39,49 → 20,30 | 555,04 → 499,81 |
| /sales/orders | 50 → 12 | 38 → 0 | 38 → 1 | 31,14 → 8,31 | 525,24 → 480,05 |

La réduction des requêtes est forte ; le gain de temps total reste de
quelques dizaines de millisecondes. Ces chiffres ne mesurent pas le cold
start Blade/Volt ni le TTFB web, et dépendent de la charge et des données.

Validation : suite complète de 621 tests, 20 219 assertions, aucun échec,
un test ignoré (symlinks Windows). La première exécution s'est arrêtée sur
la limite CLI de 128 Mo ; la relance complète a passé avec
`php -d memory_limit=512M vendor/bin/phpunit` (132 Mo utilisés), sans
modifier le php.ini. Les tests RBAC renforcés ont ensuite passé séparément :
10 tests, 70 assertions. Le journal complet est dans `results/rbac-tests.txt`.
