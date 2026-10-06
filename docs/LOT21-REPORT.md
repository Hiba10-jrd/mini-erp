# Rapport d'implémentation LOT 21

Branche : `feat/lot21-documents-notifications-audit`. Aucun commit, merge ou push.

## Reprise

Déjà présents à la reprise : packages, configuration native Reverb, migrations, services attachments/notifications/audit, jobs, routes et premières vues. Aucun de ces éléments n'a été réinstallé ou recréé.

Complété : détection MIME par le contenu réel, rattachement documents des paiements, résolution groupée des liens, filtres URL stock/créances, cloche, audit des paramètres commerciaux et rôles, identification des historiques dans l'UI, retry broadcast dans le job existant, tests et documentation.

## Fonctionnalités

- Huit parents attachments : Invoice, CreditNote, Payment, SupplierInvoice, SupplierPayment, Expense, GoodsReceipt, PurchaseOrder.
- Disque privé dédié, 5 Mo, PDF/JPEG/PNG/WebP, nom serveur, nom original nettoyé, permissions parent, téléchargement forcé, cleanup à l'échec, soft delete seulement sur parent modifiable.
- Ancien receipt_path préservé, aucun fichier historique migré ou supprimé.
- Notifications natives database ; UUID conservé lors de la diffusion, commit avant job broadcast ; canal privé par utilisateur, déduplication SQL par destinataire, aucun polling permanent.
- Finance : payments.view ; stock : stock.access ; Super Administrateurs inclus, comptes inactifs/bloqués exclus.
- Événements : invoice.overdue, invoice.due-soon, stock.low, stock.out, reminder.created.
- Queue database ; échéances quotidiennes et stock horaire en UTC ; jobs uniques et scheduler withoutOverlapping.
- Audit commun immuable pour domaines non couverts, anciennes/nouvelles valeurs nettoyées, changements de rôles et permissions ; historiques existants conservés dans leurs sections, jamais recopiés.

## Packages

Installés pendant le LOT 21, conservés lors de la reprise : laravel/reverb 1.12.0, laravel-echo 2.5.0, pusher-js 8.6.0. Les paquets React/Redis protocol présents transitivement dans Reverb n'imposent aucun serveur Redis au MVP.

## Migrations

- 2026_10_05_210001_create_attachments_table
- 2026_10_05_210002_create_notifications_table
- 2026_10_05_210003_create_operation_histories_table

Testées dans SQLite en mémoire. Non appliquées à MySQL : migrate:status a retourné connexion refusée sur 127.0.0.1:3306. Démarrer le service, vérifier migrate:status puis appliquer les trois migrations. Aucune donnée existante n'a été supprimée.

## Routes

- GET /attachments/{attachment}/download — attachments.download
- GET /documents/{parentType}/{parentId} — attachments.index
- GET /finance/expenses/{expense}/receipt — attachments.expense-legacy
- GET /notifications — notifications.index
- GET /admin/audit — admin.audit.index
- POST /broadcasting/auth — session authentifiée et canal personnel

## Validation

- Pint ciblé : passé, puis contrôle --test passé.
- npm run build : passé (Vite 7.3.6, 63 modules).
- view:clear et route:list pour les trois groupes : passés.
- Tests ciblés LOT 21 et régressions : 134 tests, 505 assertions, tous passés ; trois cas complémentaires de broadcast/rollback ajoutés ensuite sont couverts par la suite complète.
- Suite complete : 404 tests passes, 2 237 assertions, 222,24 secondes ; aucun echec.
- git diff --check : passé.
- État Git : changements LOT 21 modifiés/non suivis, sans commit.

## Limites d'exploitation

MySQL local indisponible : application et migrations à vérifier une fois le service démarré. Worker, Reverb et scheduler à maintenir selon docs/LOT21.md. Aucun vrai serveur Reverb n'est requis ni utilisé dans PHPUnit ; contrôle WebSocket navigateur à réaliser dans l'environnement de déploiement. Notifications DB disponibles par refresh en cas de panne broadcast. Aucun backfill de l'audit ni purge automatique. Une interruption brutale entre commit/enqueue peut manquer la diffusion, sans perdre la notification DB.

## Fichiers créés

- `app/Http/Controllers/AttachmentDownloadController.php`
- `app/Jobs/BroadcastStoredNotification.php`
- `app/Jobs/CheckCustomerInvoiceDeadlines.php`
- `app/Jobs/CheckStockAlerts.php`
- `app/Models/Attachment.php`
- `app/Models/Concerns/RecordsOperations.php`
- `app/Models/InternalDatabaseNotification.php`
- `app/Models/OperationHistory.php`
- `app/Notifications/InternalNotification.php`
- `app/Services/AttachmentAuthorizationService.php`
- `app/Services/AttachmentManagementService.php`
- `app/Services/AuditQueryService.php`
- `app/Services/AuditTrailService.php`
- `app/Services/InternalNotificationDispatcher.php`
- `app/Services/NotificationLinkResolver.php`
- `app/Services/NotificationRecipientResolver.php`
- `app/Services/StockReportingQuery.php`
- `config/broadcasting.php`
- `config/reverb.php`
- `database/migrations/2026_10_05_210001_create_attachments_table.php`
- `database/migrations/2026_10_05_210002_create_notifications_table.php`
- `database/migrations/2026_10_05_210003_create_operation_histories_table.php`
- `docs/LOT21.md`
- `docs/LOT21-REPORT.md`
- `resources/views/admin/attachments/show.blade.php`
- `resources/views/admin/audit/index.blade.php`
- `resources/views/admin/notifications/index.blade.php`
- `resources/views/livewire/admin/audit-manager.blade.php`
- `resources/views/livewire/attachments-manager.blade.php`
- `resources/views/livewire/notification-bell.blade.php`
- `resources/views/livewire/notifications-manager.blade.php`
- `routes/channels.php`
- `tests/Feature/AttachmentManagementTest.php`
- `tests/Feature/InternalNotificationTest.php`
- `tests/Feature/OperationAuditTest.php`

## Fichiers modifiés

- `.env.example`
- `app/Models/CashRegister.php`
- `app/Models/CashTransaction.php`
- `app/Models/CommercialSetting.php`
- `app/Models/Company.php`
- `app/Models/CreditNote.php`
- `app/Models/CreditNoteItem.php`
- `app/Models/Customer.php`
- `app/Models/CustomerContact.php`
- `app/Models/Expense.php`
- `app/Models/ExpenseCategory.php`
- `app/Models/GoodsReceipt.php`
- `app/Models/Invoice.php`
- `app/Models/InvoiceItem.php`
- `app/Models/Payment.php`
- `app/Models/PaymentAllocation.php`
- `app/Models/PaymentMethod.php`
- `app/Models/PaymentTerm.php`
- `app/Models/Product.php`
- `app/Models/PurchaseOrder.php`
- `app/Models/Quote.php`
- `app/Models/QuoteItem.php`
- `app/Models/Role.php`
- `app/Models/StockInventory.php`
- `app/Models/StockInventoryLine.php`
- `app/Models/Supplier.php`
- `app/Models/SupplierContact.php`
- `app/Models/SupplierInvoice.php`
- `app/Models/SupplierPayment.php`
- `app/Models/SupplierPaymentAllocation.php`
- `app/Models/TaxRate.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/CustomerReminderManagementService.php`
- `app/Services/ReportService.php`
- `app/Services/RolePermissionManagementService.php`
- `app/Services/UserManagementService.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `config/filesystems.php`
- `package-lock.json`
- `package.json`
- `resources/js/bootstrap.js`
- `resources/views/admin/credit-notes/show.blade.php`
- `resources/views/admin/goods-receipts/show.blade.php`
- `resources/views/admin/invoices/show.blade.php`
- `resources/views/admin/purchase-orders/show.blade.php`
- `resources/views/admin/supplier-invoices/show.blade.php`
- `resources/views/admin/supplier-payments/show.blade.php`
- `resources/views/livewire/admin/expense-form.blade.php`
- `resources/views/livewire/admin/expenses-manager.blade.php`
- `resources/views/livewire/admin/payments-manager.blade.php`
- `resources/views/livewire/admin/receivables-manager.blade.php`
- `resources/views/livewire/admin/stock-balances.blade.php`
- `resources/views/livewire/layout/navigation.blade.php`
- `routes/console.php`
- `routes/web.php`

## git status --short

```text
 M .env.example
 M app/Models/CashRegister.php
 M app/Models/CashTransaction.php
 M app/Models/CommercialSetting.php
 M app/Models/Company.php
 M app/Models/CreditNote.php
 M app/Models/CreditNoteItem.php
 M app/Models/Customer.php
 M app/Models/CustomerContact.php
 M app/Models/Expense.php
 M app/Models/ExpenseCategory.php
 M app/Models/GoodsReceipt.php
 M app/Models/Invoice.php
 M app/Models/InvoiceItem.php
 M app/Models/Payment.php
 M app/Models/PaymentAllocation.php
 M app/Models/PaymentMethod.php
 M app/Models/PaymentTerm.php
 M app/Models/Product.php
 M app/Models/PurchaseOrder.php
 M app/Models/Quote.php
 M app/Models/QuoteItem.php
 M app/Models/Role.php
 M app/Models/StockInventory.php
 M app/Models/StockInventoryLine.php
 M app/Models/Supplier.php
 M app/Models/SupplierContact.php
 M app/Models/SupplierInvoice.php
 M app/Models/SupplierPayment.php
 M app/Models/SupplierPaymentAllocation.php
 M app/Models/TaxRate.php
 M app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M app/Services/CustomerReminderManagementService.php
 M app/Services/ReportService.php
 M app/Services/RolePermissionManagementService.php
 M app/Services/UserManagementService.php
 M bootstrap/app.php
 M composer.json
 M composer.lock
 M config/filesystems.php
 M package-lock.json
 M package.json
 M resources/js/bootstrap.js
 M resources/views/admin/credit-notes/show.blade.php
 M resources/views/admin/goods-receipts/show.blade.php
 M resources/views/admin/invoices/show.blade.php
 M resources/views/admin/purchase-orders/show.blade.php
 M resources/views/admin/supplier-invoices/show.blade.php
 M resources/views/admin/supplier-payments/show.blade.php
 M resources/views/livewire/admin/expense-form.blade.php
 M resources/views/livewire/admin/expenses-manager.blade.php
 M resources/views/livewire/admin/payments-manager.blade.php
 M resources/views/livewire/admin/receivables-manager.blade.php
 M resources/views/livewire/admin/stock-balances.blade.php
 M resources/views/livewire/layout/navigation.blade.php
 M routes/console.php
 M routes/web.php
?? app/Http/Controllers/AttachmentDownloadController.php
?? app/Jobs/
?? app/Models/Attachment.php
?? app/Models/Concerns/
?? app/Models/InternalDatabaseNotification.php
?? app/Models/OperationHistory.php
?? app/Notifications/
?? app/Services/AttachmentAuthorizationService.php
?? app/Services/AttachmentManagementService.php
?? app/Services/AuditQueryService.php
?? app/Services/AuditTrailService.php
?? app/Services/InternalNotificationDispatcher.php
?? app/Services/NotificationLinkResolver.php
?? app/Services/NotificationRecipientResolver.php
?? app/Services/StockReportingQuery.php
?? config/broadcasting.php
?? config/reverb.php
?? database/migrations/2026_10_05_210001_create_attachments_table.php
?? database/migrations/2026_10_05_210002_create_notifications_table.php
?? database/migrations/2026_10_05_210003_create_operation_histories_table.php
?? docs/
?? resources/views/admin/attachments/
?? resources/views/admin/audit/
?? resources/views/admin/notifications/
?? resources/views/livewire/admin/audit-manager.blade.php
?? resources/views/livewire/attachments-manager.blade.php
?? resources/views/livewire/notification-bell.blade.php
?? resources/views/livewire/notifications-manager.blade.php
?? routes/channels.php
?? tests/Feature/AttachmentManagementTest.php
?? tests/Feature/InternalNotificationTest.php
?? tests/Feature/OperationAuditTest.php
```

Note dependances : npm a signale cinq vulnerabilites de severite elevee dans son arbre de dependances. Aucune mise a jour globale hors LOT 21 appliquee.
