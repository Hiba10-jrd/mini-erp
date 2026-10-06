# Refonte UI/UX Mini ERP

Branche : `feat/ui-ux-redesign`. Le travail LOT 21 présent dans le workspace a été conservé. Aucun commit, merge ou push. Aucun package installé pour cette refonte.

## Interface livrée

- Sidebar navy fixe de 256 px, groupes repliables Ventes, Achats, Finance, Stock et Administration. Les liens et les groupes restent soumis aux permissions existantes. Clients et fournisseurs restent accessibles dans leurs groupes respectifs.
- Topbar avec contexte de page, cloche et profil. Logo officiel utilisé sans modifier le fichier fourni. Drawer sous 1024 px, fermeture avec Escape, retour du focus et navigation clavier dans le drawer.
- Palette commune, cartes sobres, boutons, champs, badges et tableaux avec défilement horizontal. Lien d’évitement vers le contenu et focus visibles.
- Dashboard : hiérarchie période/situation actuelle, accents KPI, filtres compacts et sections d’activité conservées.
- Audit : filtres source/utilisateur/action/entité/dates, appliquer/réinitialiser, compteurs SQL selon les filtres, timeline, libellés français, détails avant/après et métadonnées. Lecture seule et autorisation existante conservées.
- Notifications : cloche, cinq dernières notifications, distinction des non lues, liste et filtres. La logique Echo/Reverb et les actions existantes sont conservées.
- Sous-titres des listes principales et breadcrumbs des détails ; tableaux des modules alignés sur le style commun.

## Fichiers créés

- `resources/views/components/sidebar.blade.php`
- `resources/views/components/page-context.blade.php`
- `resources/views/components/status-badge.blade.php`
- `resources/views/components/section-card.blade.php`
- `resources/views/components/empty-state.blade.php`
- `tests/Feature/UiNavigationTest.php`
- `docs/UI-UX-REPORT.md`

## Fichiers modifiés par la refonte

- `resources/css/app.css`
- Layouts : `resources/views/layouts/app.blade.php`, `guest.blade.php`, `resources/views/livewire/layout/navigation.blade.php`.
- Composants : `application-logo`, `primary-button`, `secondary-button`, `danger-button`, `kpi-card`, `report-period`.
- Dashboard : `resources/views/dashboard.blade.php`, `resources/views/livewire/dashboard-summary.blade.php`.
- Pages index sous `resources/views/admin/` : cash, credit-notes, customers, delivery-notes, expenses, goods-receipts, inventories, invoices, payments, products, purchase-orders, quotes, receivables, reports, sales-orders, stock, supplier-invoices, supplier-payments et suppliers.
- Audit et notifications déjà créés pendant LOT 21 : `resources/views/livewire/admin/audit-manager.blade.php`, `resources/views/livewire/notification-bell.blade.php`, `resources/views/livewire/notifications-manager.blade.php`.
- Présentation des tableaux sous `resources/views/livewire/admin/` : gestionnaires, formulaires et détails des modules concernés ; wrappers de défilement horizontal et formatage Pint ciblé.

Le `git status` global contient aussi les changements antérieurs LOT 21. Les modifications de modèles, services, configurations, migrations, routes, dépendances et JavaScript affichées par Git viennent de cet état antérieur.

## Préservation du métier

Une comparaison SHA-256 avant/après sur tous les fichiers de `app`, `config`, `routes`, `database` et `resources/js` donne **zéro fichier changé pendant la refonte**. Aucun calcul financier, workflow, permission ou service métier modifié. Les compteurs ajoutés dans la vue Audit sont des agrégations de lecture.

## Validation

- `npm run build` : succès, Vite 7.3.6, 63 modules ; JS 126,09 kB. La taille CSS varie selon les vues compilées présentes, également incluses dans la configuration Tailwind existante.
- Pint ciblé puis `--test` : succès.
- `php artisan view:clear` : succès.
- Tests ciblés : **52 réussis, 206 assertions** (`UiNavigationTest`, `OperationAuditTest`, `InternalNotificationTest`, `ReportsAccessTest`, `UserManagement*`, `RolePermissionManagementTest`).
- Tests de navigation et relances après ajout du raccourci créances à l’accueil : **19 réussis, 106 assertions**.
- Suite complète finale : **408 réussis, 2 257 assertions**, 220,01 secondes.
- `git diff --check` : succès.
- `git status --short` : vérifié, 164 entrées globales (refonte et état LOT 21 préexistant), modifications conservées et non commitées.

## Limites de validation

Les adaptations responsive sont implémentées pour mobile/tablette et desktop. La vérification visuelle manuelle à 375, 768, 1366 et 1920 px n’a pas pu être exécutée : aucun navigateur n’est accessible dans la session, et la tentative d’ouverture du navigateur intégré a retourné « Browser is not available: iab ». Aucune capture ou validation visuelle n’est revendiquée.

L’état préalable LOT 21 signalait aussi une base MySQL locale indisponible ; cette refonte n’a pas modifié la base ni appliqué les migrations à cette base. Les tests utilisent leur environnement de test.
