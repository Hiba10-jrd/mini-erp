# Rapprochement des factures fournisseurs

Date : 20 octobre 2026. Branche : `feat/supplier-invoice-reconciliation`.

La refonte de présentation ultérieure et son bilan sont décrits dans [SUPPLIER-INVOICE-UX-FINALISATION.md](SUPPLIER-INVOICE-UX-FINALISATION.md). Le présent rapport conserve les décisions et résultats de la phase financière initiale.

## Fonctionnement livré

La fiche facture regroupe la référence ERP en lecture seule, les documents originaux et corrigés, les montants fournisseur, les écarts, leur justification et les décisions. Les lignes ERP sont accessibles dans un détail repliable.

La saisie simple porte sur le HT et la TVA figurant sur le document. Le TTC est calculé à partir de ces deux montants et n'est pas modifiable. Le taux connu est repris de la commande ; une différence est expliquée par un motif métier. Une ventilation complémentaire, présentée sous « Détails des taxes et frais », couvre plusieurs taux, remises, transport et frais accessoires. Une ventilation simple de frais n'est préremplie que si les données ERP et la TVA déclarée permettent un calcul exact ; sinon les détails nécessaires sont demandés. Aucun OCR ni extraction IA.

Les montants cohérents peuvent être enregistrés en brouillon sans autoriser le paiement. La soumission et l'acceptation sont deux actions distinctes. Un écart sur le HT, la TVA ou le TTC exige un motif avant soumission, même lorsque des différences se compensent dans le TTC. Aucun écart n'est accepté automatiquement.

## Montants et compatibilité

- Les colonnes existantes `subtotal_ht`, `discount_total`, `tax_total`, `total_ttc` et les lignes restent la référence ERP.
- Une relation unique `supplier_invoice_reconciliations` contient les montants numériques du document, sa ventilation fiscale, le motif et l'identifiant du document sélectionné.
- Les composantes sont enregistrées dans une colonne JSON, plutôt que dans une table supplémentaire : elles appartiennent à une seule saisie financière et ne sont pas exploitées indépendamment.
- Les versions antérieures sont conservées dans les métadonnées de l'historique existant, avec auteur et date.
- `payableAmount()` centralise la dette : TTC fournisseur pour une facture rapprochée validée, ancien `total_ttc` pour une facture historique validée, zéro pour une facture non validée.
- `payableSql()` fournit la même définition aux rapports et indicateurs financiers, qui filtrent les factures validées.
- Aucun montant historique validé ni paiement existant n'est réécrit.

Exemple : ERP 120,00 ; fournisseur accepté 132,00 ; paiement 50,00 ; restant 82,00. Le montant ERP reste 120,00.

## Statuts et réservations

| Départ | Action | Arrivée |
|---|---|---|
| `draft` | Soumettre | `under_review` |
| `under_review` | Accepter | `validated` |
| `under_review` | Contester avec motif | `disputed` |
| `disputed` | Corriger avec un nouveau document et soumettre | `under_review` |
| `draft` | Annuler avec le droit existant | `cancelled` |

`draft`, `under_review` et `disputed` réservent les quantités. `validated` les compte comme facturées. Seul `cancelled` libère la réservation. Une contestation ne modifie ni les commandes, ni les réceptions, ni le stock.

Les données en vérification sont verrouillées. La validation recontrôle les quantités, les sources, la référence ERP, la ventilation et le document. Les factures validées restent immuables, même partiellement payées.

## Fiscalité et frais

Calculs décimaux `BigDecimal`, montants à deux décimales et convention `HALF_UP` du projet. Chaque composante fournit une base, un taux et une TVA déclarée. Une remise est soustraite ; achats, transport et frais sont additionnés. Les totaux déclarés doivent correspondre à la somme des composantes.

Une base sans TVA nécessite une explication du traitement fiscal. Un écart de TVA d'au plus un centime par composante est permis uniquement avec une justification d'arrondi ; le motif global reste obligatoire. Un dépassement, une ventilation absente ou un TTC incohérent bloque la soumission et l'acceptation.

Ces contrôles vérifient la cohérence de la saisie ; ils ne constituent pas une détermination automatique du régime fiscal ni du droit à déduction.

## Documents et audit

Réutilisation exclusive de `AttachmentManagementService`, du stockage privé et du téléchargement sécurisé existants. Formats de facture : PDF, JPG/JPEG et PNG, maximum 5 Mo.

Une correction ajoute un fichier : elle ne remplace pas l'original. Une facture contestée doit sélectionner un nouveau document avant sa nouvelle soumission. Les documents de la facture ne peuvent plus être supprimés via le service documentaire après enregistrement d'un rapprochement ou soumission. L'ajout d'un document officiel est interdit pendant la vérification et après validation ; les pièces complémentaires conservent leur fonctionnement existant.

Les événements `reconciliation_saved`, `submitted_for_review`, `disputed` et `validated` conservent les montants, références documentaires, motifs et versions financières utiles. Les décisions sont visibles dans l'historique de la fiche et dans le mécanisme d'audit existant.

## Permissions et concurrence

Consultation : `purchases.view`. Création : `purchases.create`. Modification, soumission, contestation et acceptation : `purchases.update`. Annulation : `purchases.delete`. Paiement : `payments.create`. Aucune attribution de permission ni modification de rôle.

Les méthodes de sauvegarde, soumission, contestation et validation sont distinctes pour permettre une séparation ultérieure des permissions.

Les transactions sérialisent les opérations financières d'un fournisseur en verrouillant ce fournisseur avant les commandes et factures, comme le service de paiement. Les factures d'un paiement sont verrouillées dans l'ordre de leurs identifiants. Le solde est recontrôlé sous verrou avant toute affectation. `lock_version` refuse les modifications et décisions provenant d'un écran périmé. La séquence de facture n'est consommée qu'après les contrôles.

Les tests SQLite vérifient les refus d'opérations périmées, la double validation séquentielle et les dépassements de solde. **Ils ne prouvent pas le comportement concurrent d'InnoDB.** L'utilisateur a demandé de terminer avec SQLite faute de base MySQL de test dédiée. Les scénarios réellement simultanés restent à exécuter avant mise en service : deux validations, validation/contestation, deux paiements dépassant ensemble le solde et correction/soumission sur deux connexions.

## Migration préparée

`database/migrations/2026_10_20_120000_add_supplier_invoice_reconciliation.php` : ajout de `lock_version`, création de la table de rapprochement avec référence unique de facture et référence documentaire protégée.

Migration appliquée uniquement par les tests SQLite en mémoire. Aucune migration exécutée sur la base MySQL du projet. Aucun backfill des montants historiques. Aucun modèle, lien ni table d'avoir fournisseur ajouté.

Ne pas exécuter le rollback de cette migration après utilisation sans stratégie de conservation des rapprochements : supprimer cette table ferait perdre les nouvelles dettes validées. Un retour de version doit préserver les données financières et documentaires.

## Fichiers

### Modèles et services

- `app/Models/SupplierInvoice.php`
- `app/Models/SupplierInvoiceItem.php`
- `app/Models/SupplierInvoiceReconciliation.php` — nouveau
- `app/Services/SupplierInvoiceManagementService.php`
- `app/Services/SupplierInvoiceReconciliationCalculator.php` — nouveau
- `app/Services/SupplierPaymentManagementService.php`
- `app/Services/ReportService.php`
- `app/Services/AttachmentAuthorizationService.php`
- `app/Services/AttachmentManagementService.php`

### Interface et langues

Sous `resources/views/livewire/admin/` :

- `supplier-invoice-reconciliation.blade.php` — nouveau
- `supplier-invoice-details.blade.php`
- `supplier-invoice-form.blade.php`
- `supplier-invoices-manager.blade.php`
- `supplier-payment-details.blade.php`
- `supplier-payment-form.blade.php`
- `supplier-details.blade.php`
- `reports-manager.blade.php`
- `purchase-order-details.blade.php`
- `goods-receipt-details.blade.php`

`lang/fr.json`, `lang/en.json`, `lang/ar.json` : libellés ajoutés, traductions antérieures conservées. Classes logiques `text-start`, `text-end`, `ms-*` et noms documentaires isolés avec `bdi` pour le RTL.

### Tests et documentation

- `tests/Feature/SupplierInvoiceReconciliationTest.php` — nouveau
- `tests/Feature/SupplierInvoiceManagementTest.php`
- `tests/Feature/SupplierInvoiceUiTest.php`
- `tests/Support/SubmitsSupplierInvoices.php` — nouveau
- `tests/Support/SupplierInvoiceSourceFixtures.php` — nouveau
- `tests/isolated-bootstrap.php` — nouveau
- La migration ci-dessus et le présent document.

## Exécution et résultats

Les commandes utilisent PHP Herd 8.4.25 avec `--bootstrap tests/isolated-bootstrap.php`. Ce bootstrap force `APP_ENV=testing`, SQLite `:memory:`, un `DB_URL` vide et un chemin de cache de configuration distinct. Il ignore ainsi les paramètres MySQL et un éventuel cache de configuration réel.

```powershell
& 'C:\Users\hp\.config\herd\bin\php84\php.exe' vendor/phpunit/phpunit/phpunit --bootstrap tests/isolated-bootstrap.php --filter 'Supplier|Attachment|Reports|Dashboard|Stock|PurchaseOrder|GoodsReceipt|OperationAudit|DocumentSequence'
```

Résultats finaux :

| Vérification | Résultat |
|---|---|
| Non-régression élargie : fournisseurs, documents, rapports, tableaux de bord, stocks, commandes, réceptions, audit et séquences | 221 tests, 1 960 assertions, tous réussis |
| Dernière exécution ciblée `SupplierInvoiceReconciliationTest\|SupplierInvoiceUiTest`, comprenant le contrôle du TTC incohérent et les montants du formulaire de paiement/rapport | 35 tests, 477 assertions, tous réussis |
| Formatage PHP `vendor/bin/pint --dirty` | Réussi |
| Contrôle des différences `git diff --check` | Réussi |

Les deux séries métier se recouvrent ; leurs nombres ne doivent pas être additionnés. La dernière série inclut un test supplémentaire ajouté après le lancement de la série élargie. Les essais intermédiaires ont identifié puis corrigé une référence de classe manquante, deux défauts de fixtures, une comparaison trop stricte de dates et une attente d'interface devenue obsolète.

Les résultats JUnit sont disponibles dans `storage/logs/supplier-reconciliation-regression.xml`, `storage/logs/supplier-reconciliation-final.xml` et `storage/logs/supplier-reconciliation-translations.xml` (artefacts locaux ignorés par Git). Aucun test de la suite complète hors des périmètres indiqués n'est revendiqué.

Le contrôle global `TranslationFilesTest` a été exécuté séparément : 9 tests, 16 607 assertions, 1 échec sur des libellés déjà manquants avant la mission dans les modules documents et ventes. La lecture du contenu commité a confirmé cette dette de traduction. Aucun nouveau littéral du rapprochement ne manque dans les trois fichiers de langue ; un test dédié couvre cette interface en FR/EN/AR.

## Avant mise en service

1. Relire les changements et les règles de traitement fiscal avec le responsable métier.
2. Tester la migration et la concurrence réelle sur une base MySQL dédiée, ainsi que le rendu visuel FR/EN/AR et RTL.
3. Prévoir une sauvegarde cohérente de la base et des fichiers privés avant déploiement.
4. Obtenir l'accord explicite de l'utilisateur avant toute migration sur la base du projet.
5. Déployer code et migration ensemble, puis vérifier une facture historique et une nouvelle facture avec paiement partiel.

Aucun commit, push, merge, reset destructif, effacement de données réelles ou exécution de migration MySQL réelle pendant cette mission.

État Git final vérifié : 21 fichiers suivis modifiés et 9 nouveaux fichiers, tous limités au périmètre décrit. Le commit documentaire antérieur `eed58de` est conservé. La branche est restée `feat/supplier-invoice-reconciliation`.
