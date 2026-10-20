# Finalisation UX — Factures fournisseurs

20 octobre 2026 · Branche `feat/supplier-invoice-reconciliation`

## Simplification finale — 20 octobre 2026

La saisie standard n'expose que le HT et la TVA du document fournisseur. Le TTC est calculé par le serveur et présenté en lecture seule ; une valeur TTC envoyée directement par le navigateur n'est pas utilisée. Les écarts HT, TVA et TTC sont visibles séparément. Un motif métier est demandé lorsqu'un montant diffère.

Les motifs courants sont **Différence de prix**, **Frais de livraison**, **Remise**, **Autres frais** et **Autre motif**. Pour une livraison, une remise ou d'autres frais, l'utilisateur confirme le montant HT calculé, le taux de TVA applicable (repris du taux ERP unique lorsqu'il est connu) et son traitement fiscal. La ventilation n'est préremplie automatiquement que si les totaux ERP et les écarts correspondent exactement au calcul de TVA existant. Dans le cas contraire, la section secondaire **Détails des taxes et frais** signale qu'une ventilation manuelle est nécessaire. Aucun taux, traitement d'exonération ni montant de taxe n'est déduit de façon arbitraire.

Le contrôle du bouton de soumission utilise les mêmes conditions que le message de préparation : commande, réception sélectionnée, quantités valides, droits, numéro/date, document, HT/TVA, cohérence fiscale, motif et confirmation du traitement. La désactivation générale pendant toutes les requêtes Livewire a été limitée à la soumission et au chargement du document ; une synchronisation de champ seule ne laisse plus le bouton apparemment bloqué alors que la fiche annonce qu'elle est prête. La soumission côté serveur continue à recalculer le TTC et à appliquer le calculateur financier existant.

Aucun service financier, calcul fiscal, permission, schéma ou migration n'a été modifié pour cette simplification. Le montant ERP, le stockage des versions, le paiement, l'audit et les contrôles serveur restent inchangés.

## Périmètre et audit

Le parcours précédent répartissait la création, le document et les montants entre le formulaire, la fiche et un composant de rapprochement. La fiche répétait les informations financières et documentaires ; le gestionnaire générique listait de nouveau le document original.

Technologies vérifiées : Laravel **12.69.2**, Livewire **3.8.9**, Volt **1.11.2** dans `composer.lock`, PHP Herd **8.4.25**, Tailwind **3** et Vite **7.3.6**. Les composants Blade, la palette ERP, le RBAC et le stockage privé existants sont conservés.

Les changements non commités de la mission précédente étaient présents au départ. Aucun changement de branche ni opération Git destructive. Les services financiers, le calculateur, le modèle de facture et la migration précédente ont été comparés par SHA-256 avant/après cette refonte : ils sont identiques. Le fichier de référence local est `storage/framework/testing/supplier-invoice-ux/engine-hashes.json`.

## Nouveau parcours

La création, la modification et la consultation utilisent le même espace de travail, organisé en cinq sections principales :

1. **Facture fournisseur** : commande, fournisseur repris automatiquement, référence, dates et réceptions sélectionnées. Notes dans un détail secondaire.
2. **Document du fournisseur** : un document actif, son nom et sa taille, son téléchargement protégé ou son import. Le fichier en attente peut être retiré avant enregistrement. Les anciennes versions et les autres justificatifs restent dans des détails distincts.
3. **Réceptions et calcul ERP** : article et réception, quantité disponible, quantité à facturer et total. Prix, remises, TVA et réservations détaillées sont repliables. La disposition devient une carte sur petit écran ; aucune table principale à douze colonnes.
4. **Vérification des montants** : référence ERP en lecture seule, saisie fournisseur HT/TVA, TTC calculé non modifiable, comparaison réactive des écarts HT/TVA/TTC. Les détails fiscaux sont repliés en cas simple et développés lorsqu'une ventilation ou une correction est nécessaire. Le motif apparaît pour les écarts ou arrondis à justifier.
5. **Vérification et décision** : actions correspondant au statut, état du paiement, dette acceptée, solde et historiques secondaires.

L'utilisateur peut saisir le document et les montants avant le premier enregistrement. **Enregistrer le brouillon** sauvegarde les informations préparées. **Soumettre à vérification** les enregistre et appelle la soumission, sans demander d'enregistrer un brouillon au préalable. La redirection après succès affiche le même espace de travail avec les données conservées et les actions du nouveau statut.

Il n'y a plus d'action distincte « Enregistrer les montants » ou « Conserver ce document » dans le parcours principal. Une version corrigée est enregistrée avec la facture, sans écraser le fichier précédent.

La coordination s'effectue dans une transaction de formulaire qui appelle les services existants. Si les montants ou la soumission échouent, les écritures de facture sont annulées et le fichier privé nouvellement copié est nettoyé, selon le mécanisme déjà utilisé par le formulaire. Les données saisies restent à l'écran. Des montants incomplets ne sont pas silencieusement ignorés : les erreurs signalent les informations à compléter.

## Statuts et lisibilité

| Statut | Présentation et actions |
|---|---|
| Brouillon | Enregistrer / Soumettre. Annulation existante dans « Autres actions », selon droit. |
| En vérification | Message d'attente de décision, montants verrouillés, Accepter ou ouvrir la contestation. |
| Contestée | Paiement bloqué, motif visible, correction puis nouvelle soumission. |
| Validée | Référence ERP, montant accepté, écart, déjà payé, restant dû ; paiement selon droits et solde. Historique des paiements conservé. |
| Annulée | Lecture seule et comportement existant. |

Une incohérence de calcul ou de ventilation affiche **Cohérence fiscale à corriger**, distincte de **Écart à justifier**. Une justification commerciale ne contourne jamais les contrôles fiscaux. La TVA à zéro fait apparaître le traitement fiscal à préciser, y compris lorsque le taux est saisi `0.0`.

Les panneaux fiscaux conservent leur état d'ouverture pendant la saisie réactive. Les messages de validation sont reliés aux champs ; les erreurs de quantité suivent la position des lignes réellement sélectionnées, et non leur position dans la liste complète. Les noms des documents utilisent `bdi`. Les espacements et alignements utilisent les propriétés CSS logiques pour le RTL.

## Réutilisation et absence de changement métier

- Services `SupplierInvoiceManagementService`, `SupplierPaymentManagementService` et `SupplierInvoiceReconciliationCalculator` réutilisés, **sans modification pendant cette phase**.
- `QuoteCalculator` conserve les calculs ERP et `SupplierInvoiceReconciliationCalculator` fournit aussi le contrôle fiscal de présentation.
- `AttachmentManagementService`, le téléchargement sécurisé, les composants de boutons et les contrôles Blade existants sont réutilisés.
- Le gestionnaire documentaire reçoit un filtre d'affichage optionnel, limité aux factures fournisseurs, pour exclure les originaux déjà affichés. Sa valeur par défaut et ses autorisations restent inchangées.
- La fiche devient un point d'entrée vers le formulaire commun. L'ancien composant de rapprochement conserve ses actions de compatibilité et réutilise la même présentation des montants.
- Un trait contient uniquement l'état de saisie, la préparation des paramètres et la présentation commune. Il ne valide pas une dette ni n'enregistre de paiement.
- CSS limité à `.si-workspace` et aux classes `.si-*`, avec la palette existante. Aucun nouveau JavaScript global.
- Aucune migration ajoutée ou modifiée par cette refonte. Aucune modification des statuts, permissions, rôles, rapports, stocks ou avoirs fournisseurs.
- Les montants ERP, montants payables historiques, calculs fiscaux, arrondis, réservations, décisions et paiements restent contrôlés par les services précédents.

## Fichiers de cette phase

### Présentation et coordination

- `app/Livewire/Concerns/PreparesSupplierInvoiceAmounts.php` — nouveau, état et présentation communs.
- `resources/views/livewire/admin/supplier-invoice-form.blade.php` — orchestration de la page unifiée.
- `resources/views/livewire/admin/supplier-invoice-details.blade.php` — entrée de consultation et compatibilité des actions existantes.
- `resources/views/livewire/admin/supplier-invoice-reconciliation.blade.php` — réutilisation du trait et de la comparaison commune.
- `resources/views/livewire/admin/partials/supplier-invoice-workspace.blade.php` — les cinq sections.
- `resources/views/livewire/admin/partials/supplier-invoice-document.blade.php` — document actif et versions.
- `resources/views/livewire/admin/partials/supplier-invoice-amounts.blade.php` — comparaison et détails fiscaux.
- `resources/views/livewire/admin/partials/supplier-invoice-decision.blade.php` — actions, dette et paiements.
- `resources/views/components/supplier-invoice-input.blade.php` — champs avec labels, identifiants et erreurs associés.
- `resources/views/livewire/attachments-manager.blade.php` — filtre optionnel des documents originaux.
- `resources/views/admin/supplier-invoices/form.blade.php` et `show.blade.php` — titre cohérent, retrait du gestionnaire documentaire répété.
- `resources/css/supplier-invoices.css` — styles responsive dédiés.
- `resources/css/app.css` — import des styles dédiés.
- `lang/fr.json`, `lang/en.json`, `lang/ar.json` — nouveaux libellés et traduction des contrôles documentaires réutilisés sur cette page. Aucun libellé antérieur supprimé.

### Vérification et documentation

- `tests/Feature/SupplierInvoiceWorkspaceTest.php` — nouveaux scénarios du parcours unifié.
- `tests/Feature/SupplierInvoiceUiTest.php` — attentes adaptées aux nouveaux titres.
- `tests/Feature/SupplierInvoiceReconciliationTest.php` — format monétaire, présentation commune et couverture des nouvelles traductions.
- `docs/SUPPLIER-INVOICE-UX-FINALISATION.md` — présent rapport.
- `docs/SUPPLIER-INVOICE-RECONCILIATION.md` — lien vers ce rapport ; le bilan de la phase financière précédente reste conservé.

Les autres fichiers déjà modifiés dans Git appartiennent à la phase financière précédente et sont conservés. Ils ne doivent pas être attribués à cette seule refonte UX.

## Tests réellement exécutés

Tous les tests utilisent `--bootstrap tests/isolated-bootstrap.php` : SQLite `:memory:`, `APP_ENV=testing`, `DB_URL` vide et cache de configuration indépendant. Aucune connexion ni migration sur MySQL réel.

```powershell
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' vendor/phpunit/phpunit/phpunit --bootstrap tests/isolated-bootstrap.php --filter 'SupplierInvoiceWorkspaceTest|SupplierInvoiceUiTest|SupplierInvoiceReconciliationTest'

& 'C:\Users\hp\.config\herd\bin\php84\php.exe' vendor/phpunit/phpunit/phpunit --bootstrap tests/isolated-bootstrap.php --filter 'Supplier|Attachment|Reports|Dashboard|Stock|PurchaseOrder|GoodsReceipt|OperationAudit|DocumentSequence'

npm.cmd run build
```

Première série ciblée après refonte : **47 tests, 922 assertions, réussie**. Les échecs initiaux concernaient les anciens titres et le format monétaire ; ils ont été adaptés à l'interface demandée. Une régression élargie a ensuite révélé la disparition de l'état et de l'historique des paiements ; leur affichage a été rétabli, sans changer le service de paiement. Quatre scénarios complémentaires ont été ajoutés pour les données incomplètes, les erreurs de ligne, les écrans périmés et les réservations concurrentes simulées séquentiellement.

Les résultats de cette première phase décrivent l'état antérieur à la simplification finale ci-dessous.

### Régression de ventilation des frais de livraison

Le composant de rapprochement simplifié ne possède pas les lignes éditables du formulaire principal. Sa ventilation automatique doit donc reprendre les totaux ERP de la facture persistée, et non recalculer des lignes absentes comme des totaux nuls. Le motif « Autre » est le seul qui exige une précision textuelle ; les autres motifs conservent un champ de précision facultatif.

Le scénario 4 000 DH HT / 600 DH TVA ERP comparé à 4 100 DH HT / 615 DH TVA fournisseur, avec frais de livraison taxés à 15 %, est couvert dans les deux parcours Livewire. Les tests vérifient les écarts, l'état du bouton, le changement de motif, la ventilation enregistrée et sa cohérence avec le calculateur.

Vérification effectuée sur SQLite isolé :

```powershell
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' vendor/phpunit/phpunit/phpunit --bootstrap tests/isolated-bootstrap.php --filter 'SupplierInvoiceWorkspaceTest|SupplierInvoiceReconciliationTest'
```

Résultat : **38 tests, 1 014 assertions, réussis**.

La compilation Vite a réussi après autorisation hors du bac à sable : le premier essai avait été bloqué par les restrictions de lecture des répertoires parents. Les fichiers générés restent dans `public/build`, selon les conventions du projet.

## Vérification visuelle : limitation explicite

**Aucune validation visuelle dans un navigateur n'est revendiquée.** Le contrôle d'interface a retourné un inventaire vide (`apps: []`, `browsers: []`) ; l'ouverture du navigateur intégré a échoué avec `Browser is not available: iab`.

Les tests vérifient le rendu HTML/Livewire côté serveur, les cinq sections, l'association des labels, l'absence de table principale, les états et traductions FR/EN/AR ainsi que l'attribut `dir="rtl"`. Ils ne prouvent ni le rendu à une largeur donnée, ni le contraste réel, ni le fonctionnement clavier/Alpine dans un navigateur, ni l'absence de débordement visuel. Aucune capture d'écran n'a été fabriquée.

Les contrôles InnoDB réellement simultanés restent également distincts, faute de base MySQL dédiée, conformément à la décision précédente de l'utilisateur. Les refus d'écrans périmés et de double réservation sont testés sur SQLite ; ils ne constituent pas une preuve de concurrence MySQL.

## Checklist courte avant validation fonctionnelle

- [ ] **Desktop** à 1440 et 1024 px : vérifier les cinq sections, les deux colonnes de comparaison, l'alignement des montants et l'absence de défilement horizontal principal.
- [ ] **Tablette/mobile** à 768, 390 et 320 px : cartes d'articles lisibles, champs de quantité utilisables, noms longs non coupés, boutons accessibles.
- [ ] **FR/EN/AR** : changer réellement la langue ; vérifier les textes, décimales, ordre RTL, documents mixtes arabe/latin et longues références.
- [ ] **Clavier** : parcourir champs, cases, boutons et détails avec Tab/Shift+Tab/Entrée/Espace ; vérifier le focus visible et la conservation de l'ouverture fiscale pendant la saisie.
- [ ] **Créer et soumettre sans étape préalable** : sélectionner les réceptions, modifier les quantités, joindre un PDF, saisir les montants et soumettre. Vérifier qu'une seule facture et un seul document sont créés.
- [ ] **Comparer et corriger** : montant identique, écarts HT/TVA, TTC automatiquement calculé, taux zéro, ventilation multiple et arrondi. Contrôler l'apparition du motif et des champs nécessaires ; vérifier l'affichage des erreurs et le chargement de fichier invalide ou trop volumineux.
- [ ] **Décisions et paiement** : contester, ajouter une correction, resoumettre et accepter ; vérifier les versions, le blocage du paiement contesté, puis un paiement partiel et complet fondé sur le montant accepté. Ouvrir aussi une facture historique.
- [ ] **Permissions/session** : vérifier un lecteur, un créateur sans droit de mise à jour et une session expirée. Confirmer le comportement normal d'expiration Livewire avant utilisation quotidienne.

Avant toute utilisation sur la base du projet : la migration financière précédente doit être traitée séparément, avec l'accord explicite de l'utilisateur. Cette mission n'a exécuté aucune migration MySQL, aucun commit, push, merge, rebase, reset ou effacement de données réelles.

## Vérifications de la simplification finale

Exécuté pendant cette finalisation avec `vendor/phpunit/phpunit/phpunit --bootstrap tests/isolated-bootstrap.php`. Le groupe de 61 tests a été rejoué après la dernière modification PHP :

- Factures fournisseur, rapprochement, formulaires, décisions et paiements : **61 tests réussis, 1 173 assertions**.
- Achats, réceptions, services de paiement, rapports, dashboard et stock : **80 tests réussis, 643 assertions**.
- Total des deux groupes sans recouvrement : **141 tests réussis, 1 816 assertions**.
- Pint : contrôle de formatage réussi pour le code PHP modifié.
- `git diff --check` : réussi.
- `npm run build` : Vite a construit les assets.
- La suite vérifie les traductions FR/EN/AR et l'attribut RTL. **Aucune validation visuelle en navigateur n'a été réalisée.**

La configuration des tests utilise SQLite en mémoire, confirmée par les assertions de connexion dans les tests du rapprochement et de l'espace de travail. Aucun test n'a utilisé la base MySQL de développement. L'état Git reste non commité sur `feat/supplier-invoice-reconciliation`.

### Cycle d'événements de l'upload PDF

La zone et l'input de téléversement restent dans le DOM après la sélection du fichier, masqués pendant que le document sélectionné est présenté. Le callback de fin Livewire émettant `livewire-upload-finish` depuis l'input, le conserver connecté permet à l'événement de remonter aux écouteurs Alpine et à `wire:loading`; celui-ci continue de désactiver l'action pendant le transfert.

Le test `test_pdf_upload_keeps_its_event_target_until_upload_finishes_and_submission_is_ready` vérifie le maintien de la cible d'événement, les quatre écouteurs, les directives de chargement, puis l'état actif du bouton et la soumission. **40 tests et 1 079 assertions ont réussi sur SQLite isolé.** Aucun cycle réel de transfert n'a été exécuté dans Chrome : l'aperçu navigateur disponible n'avait pas de session authentifiée. Vérification manuelle à effectuer : ouvrir une création de facture, sélectionner un PDF, constater « Chargement du document… » et le bouton désactivé pendant le transfert, puis après la fin vérifier la disparition du chargement, saisir les montants et constater le bouton actif.
