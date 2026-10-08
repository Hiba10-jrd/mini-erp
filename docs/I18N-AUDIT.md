# Audit Exhaustif d'Internationalisation (i18n & RTL) — Mini ERP

> **Statut de l'audit** : PHASE 0 terminée (Analyse ligne par ligne, lecture seule).
> **Objectif** : État des lieux complet avant modification — Détection de toutes les chaînes visibles, textes stockés en base, clés servant de logique, points physiques RTL et questions ouvertes.

---

## 1. Synthèse Globale des Métriques

- **Vues Blade analysées** : 154
- **Fichiers backend PHP (`app/**`) analysés** : 141
- **Fichiers de tests analysés** : 70
- **Total des appels `__()` / `trans()` détectés** : **2276** (1980 dans les vues, 296 dans le backend)
- **Nombre de clés de traduction distinctes existantes** : **1135**
- **Chaînes textuelles en dur résiduelles dans les vues** : ≈ 904 nœuds / fragments
- **Attributs HTML en dur identifiés** :
  - `placeholder` en dur : 31 (dont codes techniques comme `DEV-2026-00001` et libellés réels)
  - `aria-label` en dur : 10
  - `title` HTML en dur : 8
  - `alt` en dur : 5
  - `wire:confirm` en dur : 1
  - Props de composants Blade sans `:` (ex. `title="..."`) : 8
- **Occurrences de devises « DH » en dur** : 12 occurrences réparties sur 4 fichiers

---

## 2. Inventaire Détaillé Fichier par Fichier

### A. Vues Blade (`resources/views/**`)

| Fichier | Appels `__()` | Attributs en dur (`placeholder`, `aria`, `title`, `alt`, `confirm`) | Props composants & `@props` en dur | Nœuds texte en dur | Chaînes Volt Backend (feedback, flash, val) | Classes RTL physiques |
|---|:---:|---|---|:---:|---|---|
| `resources/views/admin/attachments/show.blade.php` | 0 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/audit/index.blade.php` | 0 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/cash/index.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/commercial/index.blade.php` | 2 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/admin/company/index.blade.php` | 2 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/admin/credit-notes/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/credit-notes/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/credit-notes/show.blade.php` | 2 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/customers/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/customers/show.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/delivery-notes/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/delivery-notes/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/delivery-notes/show.blade.php` | 2 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/admin/expenses/form.blade.php` | 1 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/admin/expenses/index.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/goods-receipts/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/goods-receipts/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/goods-receipts/show.blade.php` | 2 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/inventories/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/inventories/show.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/invoices/form.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/invoices/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/invoices/show.blade.php` | 2 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/notifications/index.blade.php` | 0 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/payments/form.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/payments/index.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/products/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/products/show.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/purchase-orders/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/purchase-orders/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/purchase-orders/show.blade.php` | 2 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/quotes/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/quotes/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/quotes/show.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/receivables/index.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/reports/index.blade.php` | 0 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/roles/index.blade.php` | 2 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/admin/sales-orders/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/sales-orders/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/sales-orders/show.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/stock/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/supplier-invoices/form.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/supplier-invoices/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/supplier-invoices/show.blade.php` | 2 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/supplier-payments/form.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/supplier-payments/index.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/supplier-payments/show.blade.php` | 1 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/admin/suppliers/index.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/suppliers/show.blade.php` | 2 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/admin/users/index.blade.php` | 2 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/action-message.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/components/application-logo.blade.php` | 0 | alt(1) | 0 | 0 | 0 | 0 |
| `resources/views/components/auth-session-status.blade.php` | 0 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/components/danger-button.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/dropdown-link.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/dropdown.blade.php` | 0 | 0 | defaults(3) | 0 | 0 | 0 |
| `resources/views/components/empty-state.blade.php` | 0 | 0 | defaults(2) | 0 | 0 | 0 |
| `resources/views/components/input-error.blade.php` | 0 | 0 | 0 | 2 | 0 | 0 |
| `resources/views/components/input-label.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/kpi-card.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/modal.blade.php` | 0 | 0 | defaults(1) | 1 | 0 | 0 |
| `resources/views/components/nav-link.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/page-context.blade.php` | 0 | aria(1) | 0 | 0 | 0 | 0 |
| `resources/views/components/primary-button.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/report-period.blade.php` | 0 | 0 | defaults(1) | 4 | 0 | 2 classes |
| `resources/views/components/responsive-nav-link.blade.php` | 0 | 0 | 0 | 0 | 0 | 2 classes |
| `resources/views/components/secondary-button.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/section-card.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/components/sidebar.blade.php` | 0 | aria(3) | 0 | 8 | 0 | 1 classes |
| `resources/views/components/status-badge.blade.php` | 0 | 0 | defaults(1) | 0 | 0 | 0 |
| `resources/views/components/text-input.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/dashboard.blade.php` | 1 | 0 | 0 | 7 | 0 | 0 |
| `resources/views/layouts/app.blade.php` | 0 | 0 | 0 | 4 | 0 | 0 |
| `resources/views/layouts/guest.blade.php` | 0 | 0 | 0 | 0 | 0 | 0 |
| `resources/views/livewire/admin/audit-manager.blade.php` | 0 | plh(2), title(6) | props(6) | 49 | 0 | 0 |
| `resources/views/livewire/admin/cash-manager.blade.php` | 57 | plh(4) | 0 | 26 | 0 | 4 classes |
| `resources/views/livewire/admin/catalog-settings-manager.blade.php` | 34 | 0 | 0 | 9 | 0 | 0 |
| `resources/views/livewire/admin/company-settings.blade.php` | 26 | plh(1) | 0 | 16 | 0 | 1 classes |
| `resources/views/livewire/admin/credit-note-details.blade.php` | 29 | 0 | 0 | 6 | 0 | 18 classes |
| `resources/views/livewire/admin/credit-note-form.blade.php` | 19 | 0 | 0 | 7 | 0 | 15 classes |
| `resources/views/livewire/admin/credit-notes-manager.blade.php` | 18 | 0 | 0 | 1 | 0 | 13 classes |
| `resources/views/livewire/admin/currency-settings.blade.php` | 8 | plh(2) | 0 | 3 | 0 | 1 classes |
| `resources/views/livewire/admin/customer-contacts-manager.blade.php` | 29 | 0 | 0 | 3 | val(1) | 13 classes |
| `resources/views/livewire/admin/customer-details.blade.php` | 25 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/livewire/admin/customers-manager.blade.php` | 55 | 0 | 0 | 8 | 0 | 13 classes |
| `resources/views/livewire/admin/delivery-note-details.blade.php` | 22 | 0 | 0 | 3 | 0 | 4 classes |
| `resources/views/livewire/admin/delivery-note-form.blade.php` | 15 | 0 | 0 | 9 | 0 | 1 classes |
| `resources/views/livewire/admin/delivery-notes-manager.blade.php` | 21 | plh(1) | 0 | 3 | 0 | 4 classes |
| `resources/views/livewire/admin/document-sequences-manager.blade.php` | 22 | plh(1) | 0 | 13 | 0 | 10 classes |
| `resources/views/livewire/admin/expense-form.blade.php` | 38 | plh(4) | 0 | 16 | 0 | 7 classes |
| `resources/views/livewire/admin/expenses-manager.blade.php` | 23 | plh(1) | 0 | 13 | 0 | 16 classes |
| `resources/views/livewire/admin/goods-receipt-details.blade.php` | 36 | 0 | 0 | 8 | 0 | 4 classes |
| `resources/views/livewire/admin/goods-receipt-form.blade.php` | 19 | 0 | 0 | 7 | 0 | 8 classes |
| `resources/views/livewire/admin/goods-receipts-manager.blade.php` | 26 | plh(1) | 0 | 1 | 0 | 4 classes |
| `resources/views/livewire/admin/inventories-manager.blade.php` | 33 | plh(1) | 0 | 7 | 0 | 4 classes |
| `resources/views/livewire/admin/inventory-details.blade.php` | 35 | 0 | 0 | 14 | 0 | 9 classes |
| `resources/views/livewire/admin/invoice-details.blade.php` | 38 | 0 | 0 | 13 | 0 | 18 classes |
| `resources/views/livewire/admin/invoice-form.blade.php` | 28 | 0 | 0 | 5 | 0 | 1 classes |
| `resources/views/livewire/admin/invoices-manager.blade.php` | 23 | plh(1) | 0 | 6 | 0 | 6 classes |
| `resources/views/livewire/admin/payment-form.blade.php` | 37 | 0 | 0 | 31 | 0 | 0 |
| `resources/views/livewire/admin/payment-methods-manager.blade.php` | 17 | plh(1) | 0 | 3 | 0 | 7 classes |
| `resources/views/livewire/admin/payment-terms-manager.blade.php` | 22 | plh(1) | 0 | 4 | 0 | 10 classes |
| `resources/views/livewire/admin/payments-manager.blade.php` | 10 | plh(1) | 0 | 11 | 0 | 5 classes |
| `resources/views/livewire/admin/product-details.blade.php` | 31 | 0 | 0 | 5 | 0 | 6 classes |
| `resources/views/livewire/admin/products-manager.blade.php` | 69 | 0 | 0 | 23 | val(1) | 18 classes |
| `resources/views/livewire/admin/purchase-order-details.blade.php` | 63 | 0 | 0 | 16 | 0 | 18 classes |
| `resources/views/livewire/admin/purchase-order-form.blade.php` | 30 | 0 | 0 | 15 | 0 | 5 classes |
| `resources/views/livewire/admin/purchase-orders-manager.blade.php` | 25 | plh(1) | 0 | 4 | 0 | 6 classes |
| `resources/views/livewire/admin/quote-details.blade.php` | 41 | 0 | 0 | 16 | 0 | 14 classes |
| `resources/views/livewire/admin/quote-form.blade.php` | 30 | 0 | 0 | 16 | 0 | 1 classes |
| `resources/views/livewire/admin/quotes-manager.blade.php` | 33 | plh(1) | 0 | 5 | 0 | 6 classes |
| `resources/views/livewire/admin/receivables-manager.blade.php` | 46 | 0 | 0 | 18 | 0 | 3 classes |
| `resources/views/livewire/admin/reports-manager.blade.php` | 0 | aria(1) | 0 | 21 | 0 | 3 classes |
| `resources/views/livewire/admin/roles-permissions-manager.blade.php` | 11 | 0 | 0 | 11 | val(1) | 3 classes |
| `resources/views/livewire/admin/sales-order-details.blade.php` | 48 | 0 | 0 | 18 | 0 | 20 classes |
| `resources/views/livewire/admin/sales-order-form.blade.php` | 29 | 0 | 0 | 15 | 0 | 1 classes |
| `resources/views/livewire/admin/sales-orders-manager.blade.php` | 33 | plh(1) | 0 | 4 | 0 | 6 classes |
| `resources/views/livewire/admin/stock-balances.blade.php` | 24 | plh(1) | 0 | 9 | 0 | 8 classes |
| `resources/views/livewire/admin/stock-movements-history.blade.php` | 37 | 0 | 0 | 5 | 0 | 8 classes |
| `resources/views/livewire/admin/stock-operations-manager.blade.php` | 24 | 0 | 0 | 12 | 0 | 0 |
| `resources/views/livewire/admin/supplier-contacts-manager.blade.php` | 27 | 0 | 0 | 12 | 0 | 13 classes |
| `resources/views/livewire/admin/supplier-details.blade.php` | 31 | 0 | 0 | 9 | 0 | 1 classes |
| `resources/views/livewire/admin/supplier-invoice-details.blade.php` | 58 | 0 | 0 | 22 | 0 | 14 classes |
| `resources/views/livewire/admin/supplier-invoice-form.blade.php` | 34 | 0 | 0 | 10 | 0 | 19 classes |
| `resources/views/livewire/admin/supplier-invoices-manager.blade.php` | 26 | plh(1) | 0 | 4 | 0 | 6 classes |
| `resources/views/livewire/admin/supplier-payment-details.blade.php` | 21 | 0 | 0 | 9 | 0 | 6 classes |
| `resources/views/livewire/admin/supplier-payment-form.blade.php` | 40 | 0 | 0 | 33 | 0 | 0 |
| `resources/views/livewire/admin/supplier-payments-manager.blade.php` | 15 | plh(1) | 0 | 6 | 0 | 8 classes |
| `resources/views/livewire/admin/suppliers-manager.blade.php` | 44 | 0 | 0 | 17 | 0 | 13 classes |
| `resources/views/livewire/admin/tax-rates-manager.blade.php` | 20 | plh(2) | 0 | 4 | 0 | 10 classes |
| `resources/views/livewire/admin/users-manager.blade.php` | 49 | plh(1) | 0 | 20 | 0 | 19 classes |
| `resources/views/livewire/admin/warehouses-manager.blade.php` | 26 | 0 | 0 | 8 | 0 | 4 classes |
| `resources/views/livewire/attachments-manager.blade.php` | 0 | confirm(1) | 0 | 16 | 0 | 0 |
| `resources/views/livewire/dashboard-summary.blade.php` | 0 | 0 | 0 | 22 | 0 | 4 classes |
| `resources/views/livewire/layout/navigation.blade.php` | 2 | aria(3) | 0 | 1 | 0 | 1 classes |
| `resources/views/livewire/notification-bell.blade.php` | 0 | aria(1), title(1) | props(1) | 6 | 0 | 1 classes |
| `resources/views/livewire/notifications-manager.blade.php` | 0 | aria(1), title(1) | props(1) | 8 | 0 | 0 |
| `resources/views/livewire/pages/auth/change-initial-password.blade.php` | 7 | 0 | 0 | 3 | 0 | 0 |
| `resources/views/livewire/pages/auth/confirm-password.blade.php` | 5 | 0 | 0 | 1 | val(2) | 0 |
| `resources/views/livewire/pages/auth/forgot-password.blade.php` | 3 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/livewire/pages/auth/login.blade.php` | 5 | 0 | 0 | 3 | 0 | 0 |
| `resources/views/livewire/pages/auth/register.blade.php` | 6 | 0 | 0 | 4 | 0 | 0 |
| `resources/views/livewire/pages/auth/reset-password.blade.php` | 4 | 0 | 0 | 3 | 0 | 0 |
| `resources/views/livewire/pages/auth/verify-email.blade.php` | 4 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/livewire/profile/delete-user-form.blade.php` | 9 | 0 | 0 | 3 | 0 | 0 |
| `resources/views/livewire/profile/update-password-form.blade.php` | 7 | 0 | 0 | 3 | 0 | 0 |
| `resources/views/livewire/profile/update-profile-information-form.blade.php` | 9 | 0 | 0 | 3 | 0 | 0 |
| `resources/views/livewire/welcome/navigation.blade.php` | 0 | 0 | 0 | 4 | 0 | 0 |
| `resources/views/pdf/credit-note.blade.php` | 19 | alt(1) | 0 | 12 | 0 | 1 classes |
| `resources/views/pdf/delivery-note.blade.php` | 16 | 0 | 0 | 9 | 0 | 1 classes |
| `resources/views/pdf/invoice.blade.php` | 32 | alt(1) | 0 | 19 | 0 | 2 classes |
| `resources/views/pdf/quote.blade.php` | 29 | 0 | 0 | 14 | 0 | 1 classes |
| `resources/views/profile.blade.php` | 1 | 0 | 0 | 1 | 0 | 0 |
| `resources/views/welcome.blade.php` | 0 | alt(2) | 0 | 25 | 0 | 2 classes |

### B. Backend (`app/**`)

| Fichier | Appels `__()` | `ValidationException` | Messages Flash | Exceptions levées | Notifications / Data |
|---|:---:|:---:|---|---|---|
| `app/Enums/UserAccountStatus.php` | 3 | 0 | 0 | 0 | 0 |
| `app/Http/Controllers/CreditNotePdfController.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Http/Controllers/DeliveryNotePdfController.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Http/Controllers/InvoicePdfController.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Http/Controllers/QuotePdfController.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Http/Middleware/EnsureUserAccountIsActive.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Jobs/CheckCustomerInvoiceDeadlines.php` | 0 | 0 | 0 | 0 | 1 |
| `app/Livewire/Forms/LoginForm.php` | 2 | 2 | 0 | 0 | 0 |
| `app/Models/CreditNote.php` | 5 | 0 | 0 | 0 | 0 |
| `app/Models/CreditNoteItem.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Models/CustomerReminder.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Models/DeliveryNote.php` | 3 | 0 | 0 | 0 | 0 |
| `app/Models/DeliveryNoteItem.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Models/GoodsReceipt.php` | 3 | 0 | 0 | 0 | 0 |
| `app/Models/GoodsReceiptHistory.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Models/GoodsReceiptItem.php` | 3 | 0 | 0 | 0 | 0 |
| `app/Models/Invoice.php` | 5 | 0 | 0 | 0 | 0 |
| `app/Models/InvoiceItem.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Models/OperationHistory.php` | 0 | 0 | 0 | 2 | 0 |
| `app/Models/PurchaseOrder.php` | 3 | 0 | 0 | 0 | 0 |
| `app/Models/PurchaseOrderHistory.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Models/PurchaseOrderItem.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Models/Quote.php` | 6 | 0 | 0 | 0 | 0 |
| `app/Models/QuoteItem.php` | 1 | 0 | 0 | 0 | 0 |
| `app/Models/SalesOrder.php` | 4 | 0 | 0 | 0 | 0 |
| `app/Models/SalesOrderHistory.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Models/SalesOrderItem.php` | 6 | 0 | 0 | 0 | 0 |
| `app/Models/StockInventory.php` | 0 | 0 | 0 | 2 | 0 |
| `app/Models/StockInventoryLine.php` | 0 | 0 | 0 | 2 | 0 |
| `app/Models/StockMovement.php` | 0 | 0 | 0 | 2 | 0 |
| `app/Models/SupplierInvoice.php` | 3 | 0 | 0 | 0 | 0 |
| `app/Models/SupplierInvoiceHistory.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Models/SupplierInvoiceItem.php` | 2 | 0 | 0 | 0 | 0 |
| `app/Services/AttachmentManagementService.php` | 2 | 2 | 0 | 1 | 0 |
| `app/Services/Backup/BackupArchiveService.php` | 0 | 0 | 0 | 16 | 0 |
| `app/Services/Backup/BackupEncryptionService.php` | 0 | 0 | 0 | 31 | 0 |
| `app/Services/Backup/BackupManager.php` | 0 | 0 | 0 | 11 | 0 |
| `app/Services/Backup/BackupManifestService.php` | 0 | 0 | 0 | 17 | 0 |
| `app/Services/Backup/BackupRetentionService.php` | 0 | 0 | 0 | 4 | 0 |
| `app/Services/Backup/BackupVerificationService.php` | 0 | 0 | 0 | 13 | 0 |
| `app/Services/Backup/BackupWorkspace.php` | 0 | 0 | 0 | 7 | 0 |
| `app/Services/Backup/DatabaseBackupService.php` | 0 | 0 | 0 | 7 | 0 |
| `app/Services/Backup/DatabaseRestoreService.php` | 0 | 0 | 0 | 31 | 0 |
| `app/Services/Backup/FileRestoreService.php` | 0 | 0 | 0 | 13 | 0 |
| `app/Services/Backup/MySqlRestoreGuard.php` | 0 | 0 | 0 | 6 | 0 |
| `app/Services/Backup/RestoreManager.php` | 0 | 0 | 0 | 5 | 0 |
| `app/Services/Backup/RestoreTargetGuard.php` | 0 | 0 | 0 | 13 | 0 |
| `app/Services/CashManagementService.php` | 8 | 7 | 0 | 0 | 0 |
| `app/Services/CompanyManagementService.php` | 0 | 0 | 0 | 1 | 0 |
| `app/Services/CreditNoteManagementService.php` | 9 | 10 | 0 | 0 | 0 |
| `app/Services/CustomerContactManagementService.php` | 1 | 1 | 0 | 0 | 0 |
| `app/Services/CustomerManagementService.php` | 1 | 1 | 0 | 0 | 0 |
| `app/Services/CustomerReminderManagementService.php` | 2 | 2 | 0 | 0 | 2 |
| `app/Services/DeliveryNoteManagementService.php` | 23 | 20 | 0 | 0 | 0 |
| `app/Services/DocumentSequenceManagementService.php` | 9 | 2 | 0 | 0 | 0 |
| `app/Services/ExpenseCategoryManagementService.php` | 3 | 2 | 0 | 0 | 0 |
| `app/Services/ExpenseManagementService.php` | 10 | 9 | 0 | 0 | 0 |
| `app/Services/GoodsReceiptManagementService.php` | 21 | 13 | 0 | 0 | 0 |
| `app/Services/InventoryManagementService.php` | 8 | 7 | 0 | 0 | 0 |
| `app/Services/InvoiceManagementService.php` | 12 | 13 | 0 | 0 | 0 |
| `app/Services/PasswordManagementService.php` | 3 | 3 | 0 | 0 | 0 |
| `app/Services/PaymentManagementService.php` | 11 | 11 | 0 | 0 | 0 |
| `app/Services/ProductManagementService.php` | 0 | 0 | 0 | 1 | 0 |
| `app/Services/PurchaseOrderManagementService.php` | 12 | 9 | 0 | 0 | 0 |
| `app/Services/QuoteCalculator.php` | 5 | 0 | 0 | 0 | 0 |
| `app/Services/QuoteManagementService.php` | 11 | 12 | 0 | 0 | 0 |
| `app/Services/RolePermissionManagementService.php` | 5 | 5 | 0 | 0 | 0 |
| `app/Services/SalesOrderManagementService.php` | 14 | 9 | 0 | 0 | 0 |
| `app/Services/StockManagementService.php` | 13 | 13 | 0 | 0 | 0 |
| `app/Services/SupplierContactManagementService.php` | 1 | 1 | 0 | 0 | 0 |
| `app/Services/SupplierInvoiceManagementService.php` | 19 | 13 | 0 | 0 | 0 |
| `app/Services/SupplierManagementService.php` | 1 | 1 | 0 | 0 | 0 |
| `app/Services/SupplierPaymentManagementService.php` | 14 | 14 | 0 | 0 | 0 |
| `app/Services/TaxRateManagementService.php` | 1 | 1 | 0 | 0 | 0 |
| `app/Services/UserManagementService.php` | 6 | 6 | 0 | 0 | 0 |

---

## 3. Textes Stockés en Base de Données (Langue de l'Auteur)

L'analyse ligne par ligne confirme les points suivants :

### 3.1. Notifications Internes
- **Fichiers sources concernés** :
  1. `app/Jobs/CheckStockAlerts.php` (l. 30) :
     - `title` : `'Rupture de stock'` / `'Stock faible'`
     - `message` : `$row->name.' — '.$row->warehouse_name`
     - Solution Phase 3 : ajouter `title_key` (`'Rupture de stock'` / `'Stock faible'`), `message_key` (`':product — :warehouse'`) et `params` (`['product' => $row->name, 'warehouse' => $row->warehouse_name]`).
  2. `app/Jobs/CheckCustomerInvoiceDeadlines.php` (l. 34-35) :
     - `title` : `'Facture en retard'` / `'Échéance proche'`
     - `message` : `'Facture '.$invoice->number.' : '.$invoice->due_date->toDateString()`
     - Solution Phase 3 : ajouter `title_key`, `message_key` (`'Facture :number : :date'`) et `params` (`['number' => $invoice->number, 'date' => $invoice->due_date->toDateString()]`).
  3. `app/Services/CustomerReminderManagementService.php` (l. 57-58) :
     - `title` : `'Relance enregistrée'`
     - `message` : `'Une relance a été enregistrée pour '.$lockedInvoice->number.'.'`
     - Solution Phase 3 : ajouter `title_key`, `message_key` (`'Une relance a été enregistrée pour :number.'`) et `params` (`['number' => $lockedInvoice->number]`).
- **Affichage** (`resources/views/livewire/notification-bell.blade.php`, `resources/views/livewire/notifications-manager.blade.php`) :
  - Utiliser `isset($data['title_key']) ? __($data['title_key'], $data['params'] ?? []) : ($data['title'] ?? __('Notification'))`.
  - Ancien historique conservé intact sans migration rétroactive.

### 3.2. Historiques Métier Immuables (`*_histories`)
- **Fichiers sources concernés** :
  - `app/Services/SalesOrderManagementService.php` (modèle `SalesOrderHistory`)
  - `app/Services/PurchaseOrderManagementService.php` (modèle `PurchaseOrderHistory`)
  - `app/Services/GoodsReceiptManagementService.php` (modèle `GoodsReceiptHistory`)
  - `app/Services/SupplierInvoiceManagementService.php` (modèle `SupplierInvoiceHistory`)
- **Constat** :
  - Chaque modèle possède un boot immuable (`static::updating` et `static::deleting` lèvent une `LogicException`).
  - Les colonnes `description` existantes contiennent la chaîne traduite au moment de l'écriture en base.
- **Solution Phase 3** :
  - Injecter `description_key` et `description_params` dans la colonne `metadata` (JSON) lors de chaque appel `recordHistory(...)`.
  - À l'affichage dans les vues d'historique : tester la présence de `metadata['description_key']` pour traduire `__($key, $params)` avec repli sur `description` stockée.

### 3.3. Rôles et Permissions (`RbacSeeder`)
- `database/seeders/RbacSeeder.php` sème 6 rôles avec libellés français :
  - `'super-admin' => 'Super Administrateur'`, `'admin' => 'Administrateur'`, `'commercial' => 'Commercial'`, `'magasinier' => 'Magasinier'`, `'comptable' => 'Comptable'`, `'consultation' => 'Consultation'`.
- `config('erp.permissions')` liste les 20 permissions techniques (`customers.view`, `sales.create`, etc.).
- Solution : créer `lang/{fr,en,ar}/roles.php` (indexés par slug) et `lang/{fr,en,ar}/permissions.php` (indexés par identifiant technique).
  - Rôles rendus via `__('roles.'.$role->slug)` avec fallback sur `$role->name`.
  - Permissions rendues avec libellé clair `__('permissions.'.$name)` + groupe `__('permissions.modules.'.$module)` tout en conservant le code technique en sous-texte discret.

---

## 4. Tableaux dont les Clés Françaises Servent à la Logique

L'analyse confirme formellement qu'aucune de ces clés ne doit être traduite à la source :

1. **`app/Services/ReportService.php`** :
   - `salesSummary()` : renvoie `'CA net HT'`, `'Facturation brute TTC'`, `'Avoirs émis TTC'`, `'Facturation nette TTC'`, `'TVA nette'`, `'Encaissements'`, `'Factures émises'`.
   - `purchaseSummary()` : renvoie `'Achats HT'`, `'TVA achats'`, `'Achats facturés TTC'`, `'Paiements fournisseurs'`, `'Fournisseurs à payer actuels'`.
   - `receivablesSummary()` : renvoie `'Créances clients'`, `'Montant en retard'`, `'Factures en retard'`, `'Factures ouvertes'`.
   - `cashSummary()` : renvoie `'Entrées caisse'`, `'Sorties caisse'`, `'Solde caisse actuel'`.
   - `stockSummary()` : renvoie `'Produits actifs'`, `'Produits physiques actifs'`, `'Alertes stock'`, `'Ruptures'`, `'Stocks faibles'`.
   - `operationsSummary()` : renvoie `'Commandes clients'`, `'Commandes fournisseurs'`, `'Réceptions validées'`, `'Devis récents'`.
2. **`resources/views/livewire/dashboard-summary.blade.php`** :
   - Teste strictement `$title === 'Alertes stock'` pour formater différemment la valeur.
   - Lit directement `$sales['CA net HT']`, `$purchases['Achats facturés TTC']`, `$operations[$label]`.
3. **`resources/views/components/kpi-card.blade.php`** :
   - Associe la couleur d'accent via `$accents[$title]` où `$accents` est indexé par `'CA net HT'`, `'Encaissements'`, `'Créances clients'`, `'Dépenses TTC'`, `'Alertes stock'`, `'Solde caisse'`.
   - Solution : laisser `$title` en français et ne faire la traduction qu'à l'affichage : `<p class="erp-kpi-label">{{ __($title) }}</p>`.
4. **`tests/Feature/DashboardReportingTest.php`** :
   - Assertions directes : `$flows['CA net HT'] === '0.00'`, `$current['Créances clients'] === '120.00'`.
   - Ces tests restent 100% verts car les structures de données ne sont pas altérées.

---

## 5. Audit RTL (CSS & Classes Tailwind Physiques)

### 5.1. Classes Tailwind Physiques Détectées dans les Vues
- `text-right` : **203 occurrences** → Conversion en `text-end`
- `text-left` : **81 occurrences** → Conversion en `text-start`
- `ml-*` / `mr-*` : **12 occurrences** (`ml-2`, `mr-4`, `mr-3`, etc.) → Conversion en `ms-*` / `me-*`
- `pl-*` / `pr-*` : **6 occurrences** (`pl-5`, `pr-14`, `pr-4`) → Conversion en `ps-*` / `pe-*`
- `left-*` / `right-*` : **5 occurrences** (`left-20`, `left-16`, `right-0`, `-right-1`) → Conversion en `start-*` / `end-*` (notamment `-end-1` sur la cloche de notifications)
- `border-l` : **2 occurrences** → Conversion en `border-s`
- `uppercase` : **124 occurrences** → Désactiver en RTL via CSS (`[dir="rtl"] .uppercase { text-transform: none !important; }`)
- `tracking-*` : **23 occurrences** (`tracking-wide`, `tracking-wider`, `tracking-widest`, `tracking-tight`) → Réinitialiser en RTL (`[dir="rtl"] [class*="tracking-"] { letter-spacing: 0 !important; }`) pour préserver les ligatures de l'alphabet arabe.

### 5.2. Propriétés Physiques dans `resources/css/app.css`
Toutes les propriétés physiques suivantes ont été repérées avec leur numéro de ligne :
1. **Ligne 22** : `.erp-skip-link { left: 16px; }` → `inset-inline-start: 16px;`
2. **Ligne 24** : `.erp-sidebar { inset: 0 auto 0 0; transform: translateX(-100%); }` → `inset-block: 0; inset-inline-start: 0; transform: translateX(-100%);` et règle `[dir="rtl"] .erp-sidebar { transform: translateX(100%); }`
3. **Ligne 28** : `.erp-sidebar-link { padding: 9px 12px 9px 18px; }` → `padding-block: 9px; padding-inline: 18px 12px;`
4. **Ligne 30** : `.erp-sidebar-link.is-active { box-shadow: inset 3px 0 0 #19BFEF; }` → `[dir="rtl"] .erp-sidebar-link.is-active { box-shadow: inset -3px 0 0 #19BFEF; }`
5. **Ligne 38** : `.erp-group-toggle { margin-left: 8px; }` → `margin-inline-start: 8px;`
6. **Ligne 69** : `.erp-main th { text-align: left; }` → `text-align: start;`
7. **Ligne 99** : `.erp-kpi::before { left: 0; }` → `inset-inline-start: 0;`
8. **Ligne 107** : `.erp-notification-menu { right: 0; }` → `inset-inline-end: 0;`
9. **Ligne 109** : `.erp-notification-row.is-unread { border-left: 3px solid var(--erp-primary); }` → `border-inline-start: 3px solid var(--erp-primary);`
10. **Ligne 110** : `.erp-timeline { margin-left: 10px; border-left: 2px solid var(--erp-border); padding-left: 28px; }` → `margin-inline-start: 10px; border-inline-start: 2px solid var(--erp-border); padding-inline-start: 28px;`
11. **Ligne 112** : `.erp-timeline-card::before { left: -36px; }` → `inset-inline-start: -36px;`
12. **Ligne 116** : `@media (min-width: 1024px) { .erp-shell { padding-left: 256px; } }` → `padding-inline-start: 256px;`
13. **Ligne 118** : `.erp-topbar { padding-left: 32px; padding-right: 32px; }` → `padding-inline: 32px;`
14. **Lignes 32 & 69** : `letter-spacing: .04em` et `letter-spacing: .06em` sous summary et th → neutraliser sous `[dir="rtl"]`.

### 5.3. Flèches et Symboles Directionnels
- Flèches « → » repérées dans :
  - `resources/views/livewire/notification-bell.blade.php` (l. 39) : `Voir toutes les notifications →`
  - `resources/views/livewire/notifications-manager.blade.php` (l. 52) : `Consulter →`
  - Solution : envelopper l'icône de flèche dans un span avec `rtl:-scale-x-100` ou `rtl:rotate-180`.

### 5.4. Isolation Bidirectionnelle (Bidi)
- Application de la classe utilitaire `.erp-ltr { direction: ltr; unicode-bidi: isolate; }` ou de balises `<bdi>` sur les données non traduisibles :
  - Numéros de documents (`DEV-2026-00001`, `FAC-...`, `BL-...`)
  - Montants avec devise (`1 250,00 DH`, `100,00 EUR`)
  - Dates (`08/10/2026`)
  - Identifiants fiscaux (`ICE`, `IF`, `RC`)
  - Emails, téléphones et URL

---

## 6. Périmètre Hors Champ (Services & Commandes de Sauvegarde)

Conformément au mandat, les fichiers suivants contiennent des messages techniques destinés à la console ou aux logs système. Ils sont documentés ici mais restent hors périmètre de traduction :
- `app/Console/Commands/BackupCreateCommand.php`
- `app/Console/Commands/BackupListCommand.php`
- `app/Console/Commands/BackupRestoreCommand.php`
- `app/Console/Commands/BackupVerifyCommand.php`
- `app/Services/Backup/BackupArchiveService.php`
- `app/Services/Backup/BackupEncryptionService.php`
- `app/Services/Backup/BackupManager.php`
- `app/Services/Backup/BackupManifestService.php`
- `app/Services/Backup/BackupRetentionService.php`
- `app/Services/Backup/BackupVerificationService.php`
- `app/Services/Backup/BackupWorkspace.php`
- `app/Services/Backup/DatabaseBackupService.php`
- `app/Services/Backup/DatabaseRestoreService.php`
- `app/Services/Backup/FileRestoreService.php`
- `app/Services/Backup/MySqlRestoreGuard.php`
- `app/Services/Backup/RestoreManager.php`
- `app/Services/Backup/RestoreTargetGuard.php`

---

## 7. Décisions Validées & Points Intégrés au Plan

### 7.1. Décisions arrêtées
1. **Sort de `resources/views/welcome.blade.php` (Option A)** :
   - `/` redirige vers `route('login')` (ou vers `dashboard` si l'utilisateur est déjà connecté).
   - `welcome.blade.php` est conservé sans suppression.
   - `tests/Feature/ExampleTest.php` est adapté pour vérifier la redirection vers la connexion.
2. **Stratégie PDF (Option A)** :
   - Aucun paquet supplémentaire n'est installé.
   - Les PDF sont émis en anglais si l'interface est en anglais, et en français si l'interface est en français ou en arabe (configuré dans les 4 contrôleurs PDF juste avant le rendu).
   - Remplacement de `<html lang="fr">` par la langue réellement utilisée pour le PDF (`en` ou `fr`).
3. **Audit trail & changement de langue** :
   - Exclusion confirmée de `'locale'` dans `RecordsOperations::updated` via `unset($changes['updated_at'], $changes['remember_token'], $changes['locale']);`.

### 7.2. Points spécifiques intégrés au plan
- **Sécurité & Changement de mot de passe** : Ajout de la route `locale.update` à la liste blanche dans `app/Http/Middleware/EnsurePasswordHasBeenChanged.php` aux côtés de `'password.change.required'` et `'*.livewire.update'`.
- **Rôles utilisateurs** : Traduction des libellés de rôles dans `resources/views/livewire/admin/users-manager.blade.php` (lignes 324 et 414), en plus de `roles-permissions-manager`.
- **Typographie RTL** : Neutralisation sous `[dir="rtl"]` des 4 règles `letter-spacing` dans `resources/css/app.css` (lignes 32, 53, 69 et 101) pour garantir la bonne liaison des glyphes arabes.
- **Messages de validation** : Fichiers `lang/{fr,en,ar}/validation.php` écrits à la main avec toutes les traductions de messages et la section `attributes` complète.
- **Pagination Tailwind** : Vue `resources/views/vendor/pagination/tailwind.blade.php` créée avec traduction de « Showing … to … of … results » et flèches inversées en RTL.
- **Exceptions métier non enveloppées** : Envelopper dans `__()` les exceptions en français de `app/Models/StockInventory.php`, `StockInventoryLine.php`, `StockMovement.php`, `OperationHistory.php`, `app/Services/CompanyManagementService.php` et `ProductManagementService.php`.
- **Clarification des métriques de contrôle** : Le critère d'acceptation final est l'absence totale de texte visible non traduit à l'écran, sans se baser sur les fragments de code Blade bruts.

---

## 8. Statut
- Phase 0 validée. Prêt pour exécution directe de la Phase 1.
