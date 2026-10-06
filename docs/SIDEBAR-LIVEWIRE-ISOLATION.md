# Isolation de la sidebar et navigation native

## Constat du code

Avant cette correction, les liens de `resources/views/components/sidebar.blade.php` portaient `@click="sidebarOpen = false"` (logo, Dashboard et sous-liens). La sidebar était rendue dans la racine Livewire/Volt `layout.navigation`. Aucun `wire:click`, état PHP `openGroup` ou méthode `toggleGroup` n’a été trouvé pour les groupes ; ils utilisent des éléments natifs `details/summary`.

Le seul `wire:click` du composant navigation est `logout`, sur le bouton de déconnexion. La cloche est un composant Livewire distinct (`notification-bell`), avec `markRead` et un écouteur `notification-received`. Le JavaScript Echo peut aussi déclencher cet événement après connexion ou réception d’une notification. Ces constats ne permettent pas d’identifier la requête observée par l’utilisateur : le payload est nécessaire pour distinguer composant, appels et mises à jour.

Il serait incorrect d’affirmer que l’affectation Alpine locale `sidebarOpen = false` déclenchait à elle seule une requête serveur : elle ne modifie aucune propriété PHP identifiée dans le code.

## Correction appliquée

- `<x-sidebar />` déplacé de `livewire/layout/navigation.blade.php` dans `layouts/app.blade.php`, à côté du composant Livewire de topbar.
- État Alpine purement local sur le conteneur Blade `erp-ui-shell` ; aucune liaison `$wire` ou `entangle`.
- Tous les liens de sidebar sont des `<a href>` natifs sans gestionnaire de clic, sans `wire:navigate` et sans directive Livewire.
- Aucun gestionnaire de clic sur les parents des sous-liens ; groupes natifs `details/summary`, initialisés ouverts selon la route serveur.
- Pas de sidebar dans un formulaire, un bouton ou une racine Livewire.
- Logout et notifications restent dans leurs composants Livewire. Aucun service, route, permission, JavaScript ou style modifié dans cette passe.
- Sur mobile, le lien ne ferme plus explicitement le drawer avant navigation ; la nouvelle page initialise naturellement le drawer fermé.

## Fichiers

- `resources/views/layouts/app.blade.php`
- `resources/views/livewire/layout/navigation.blade.php`
- `resources/views/components/sidebar.blade.php`
- `tests/Feature/UiNavigationTest.php`
- `tests/Feature/StockManagementTest.php` : la vérification du lien sidebar porte désormais sur la page HTTP, car la topbar Livewire ne contient plus la sidebar. La vérification de permission est conservée.
- Ce rapport.

## Validation

Le test de HTML rendu contrôle les routes demandées, le lien actif et le groupe ouvert, l’absence de racine Livewire/formulaire/bouton au-dessus de la sidebar et l’absence de directive interactive sur chacun de ses liens. Ce test ne remplace pas le contrôle réseau d’un clic navigateur.

- Suite complète finale : **409 tests réussis, 3 005 assertions**, 201,63 secondes.
- `php artisan view:clear` puis `view:cache` : PASS.
- Pint ciblé et vérification `--test` : PASS.
- `npm run build` : PASS, 63 modules.
- `git diff --check` : PASS.
- État Git global : 165 entrées, avec les modifications antérieures conservées.

Premier lancement complet : 408 réussis et un test de navigation Stock à adapter à la nouvelle séparation. Les assertions d’isolation de la sidebar passent dans ce lancement. Suite finale relancée après adaptation.

## Diagnostic réseau restant

Le navigateur n’est pas accessible : inventaire `apps: []`, `browsers: []`. Le test « un clic = GET document » n’a pas été observé dans cette session. Le nom du composant, la méthode et les propriétés envoyées au premier clic ne sont donc pas connus. Une demande a été faite pour les seuls champs `snapshot.memo.name`, `updates` et `calls`, sans cookies ni jetons. Aucun succès de premier clic n’est revendiqué sans cette validation.

Aucun changement de design/densité, commit, merge ou push.
