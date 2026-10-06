# Correction navigation et sidebar

## Diagnostic

Le layout a été inspecté avec les composants sidebar/topbar, le JavaScript et `bootstrap/app.php`. Aucun `@persist` ni teleport n’englobe le contenu principal. La navigation a une seule racine Livewire. Aucun chargement indépendant d’Alpine ni interception supplémentaire des clics n’a été trouvé dans `app.js` ou `bootstrap.js`.

Les liens du menu utilisaient `wire:navigate`, donc un remplacement de page piloté par Livewire. L’état Alpine du drawer était installé sur le `<body>` remplacé par cette navigation. Les journaux contiennent également des erreurs locales de compilation de vues (`rename` : accès refusé), qui peuvent empêcher la réponse de destination. Sans console navigateur ni reproduction interactive, la cause exacte du blocage signalé ne peut pas être affirmée.

## Correction

- Liens sidebar, logo, profil et breadcrumbs rendus comme liens HTML natifs. La navigation principale passe désormais par une requête de page complète ; elle ne dépend plus du remplacement asynchrone Livewire. Les fonctionnalités Livewire des pages et Echo/Reverb restent présentes.
- État Alpine mobile déplacé du `<body>` à la racine `layout.navigation`, qui possède les références hamburger/fermeture. Le contenu principal reste un frère du composant navigation. Clic sur un lien sidebar : fermeture du drawer puis navigation native.
- `request()->routeIs(...)` recalcule `aria-current`, la classe active et l’ouverture du groupe à chaque réponse serveur.
- Groupes en boutons visuels, indicateur +/− dans un carré de 24 px, sous-liens distincts, lien actif bleu. Libellé Utilisateurs corrigé.
- Groupes Ventes, Achats, Finance, Stock, Administration ; groupes sans lien autorisé masqués. Clients reste dans Ventes pour conserver son accès. Les permissions et routes existantes sont conservées.

## Fichiers concernés

- `resources/views/layouts/app.blade.php`
- `resources/views/livewire/layout/navigation.blade.php`
- `resources/views/components/sidebar.blade.php`
- `resources/views/components/page-context.blade.php`
- `resources/css/app.css`
- `tests/Feature/UiNavigationTest.php`
- Ce rapport.

Aucun service, calcul, permission, route, configuration ou fichier JavaScript modifié pour cette correction.

## Validation

Le test ajouté vérifie les réponses HTTP pour Audit, Dashboard, Clients, Commandes clients, Factures clients, Créances, Dépenses, Caisse, Stock et Rapports, puis revient à Audit. Il contrôle le lien actif, le groupe ouvert, la présence du contenu principal et l’absence de `wire:navigate` dans la sidebar. Il ne simule pas un clic navigateur.

- Suite complète : **409 tests réussis, 2 333 assertions**, 182,93 secondes.
- Pint ciblé : succès.
- `git diff --check` : succès.
- `php artisan view:clear` : succès.
- `npm run build` : succès, 63 modules, CSS 55,79 kB / JS 126,09 kB.
- État Git global conservé : 164 entrées, incluant les modifications antérieures LOT 21 et UI.

## Limite

L’inventaire du navigateur renvoie `apps: []`, `browsers: []`. Les clics, back/forward et le comportement mobile n’ont donc pas pu être validés manuellement. Ils utilisent désormais le comportement natif du navigateur ; cette modification ne constitue pas une preuve de validation interactive. Aucun commit, merge ou push.
