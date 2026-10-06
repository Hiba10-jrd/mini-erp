# Réparation structurelle du layout

## Balise exacte

`resources/views/layouts/app.blade.php:17` contenait :

```html
<body class="font-sans antialiased erp-shell">= 1024 }" @resize.window="..." @keydown.escape.window="...">
```

Le précédent remplacement de la balise cherchait le premier caractère `>` sans tenir compte des attributs entre guillemets. Il a rencontré celui de `window.innerWidth >= 1024` et laissé la fin des attributs après le nouveau `<body>`. Le navigateur affichait cette fin comme un nœud texte. Cette erreur de correction est maintenant supprimée par une modification explicite de la ligne, sans découpage de chaîne.

L’état Alpine reste dans la racine unique du composant navigation, avec des attributs multilignes lisibles. Le contenu principal est en dehors de ce composant et aucun bloc persisté ne l’englobe.

## Interception des clics

Le backdrop mobile était un élément fixe avec transition d’opacité, sans garde CSS desktop et sans désactivation explicite des événements pointeur. Une opacité nulle ne désactive pas les clics ; pendant une fermeture, un tel élément peut intercepter un clic. L’interception exacte observée n’a pas pu être reproduite sans navigateur ; elle ne peut donc pas être présentée comme démontrée.

Le backdrop est désormais :

- caché et non interactif dans le HTML initial ;
- montré uniquement lorsque `sidebarOpen && !desktop` ;
- interactif uniquement dans cet état ;
- masqué et non interactif avec une règle CSS prioritaire à partir de 1024 px ;
- fermé sans transition, pour éviter une couche de fermeture qui subsiste.

Les liens natifs existants restent en place. Aucun `prevent`, timeout ou `location.href` ajouté. Les z-index existants sont conservés : navigation 40, backdrop 55, sidebar 60.

## Fichiers corrigés

- `resources/views/layouts/app.blade.php`
- `resources/views/livewire/layout/navigation.blade.php`
- `resources/views/components/sidebar.blade.php`
- `resources/css/app.css`
- `tests/Feature/UiNavigationTest.php`

Aucun service, permission, route ou calcul modifié. Aucun changement de densité ou nouveau design dans cette réparation.

## Validation

- `php artisan view:clear` : PASS.
- `php artisan view:cache` : PASS.
- Pint ciblé : PASS.
- Tests de HTML rendu : contrôle des nœuds texte directs du body, de l’expression Alpine complète, du backdrop initialement caché et non interactif, puis des liens actifs et groupes ouverts sur les destinations demandées.
- Suite complète après nettoyage du cache : **409 tests réussis, 2 388 assertions**, 187,84 secondes.
- Premier lancement ciblé : 4 réussis, 1 échec de compilation lié à un verrou Windows `rename : accès refusé`. La suite complète finale passe après nettoyage.
- `git diff --check` : PASS.
- `npm run build` : PASS (63 modules).
- Compilation finale `view:clear` puis `view:cache` : PASS.
- Vérification finale Pint `--test` : PASS.

## Validation interactive restante

L’inventaire du navigateur retourne `apps: []`, `browsers: []`. La console Alpine, les clics uniques, back/forward et les interactions mobile ne sont pas vérifiés manuellement. La densité reste inchangée conformément à la priorité donnée à la validation du bug navigation. Aucun commit, merge ou push.
