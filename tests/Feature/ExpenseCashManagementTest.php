<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashManagementService;
use App\Services\ExpenseCategoryManagementService;
use App\Services\ExpenseManagementService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ExpenseCashManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private PaymentMethod $cashMethod;

    private PaymentMethod $bankTransferMethod;

    private ExpenseCategoryManagementService $categoryService;

    private CashManagementService $cashService;

    private ExpenseManagementService $expenseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->cashMethod = PaymentMethod::query()->create([
            'name' => 'Espèces test',
            'payment_type' => PaymentMethod::TYPE_CASH,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->bankTransferMethod = PaymentMethod::query()->create([
            'name' => 'Virement test',
            'payment_type' => PaymentMethod::TYPE_BANK_TRANSFER,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $role = Role::query()->firstOrCreate(
            ['slug' => 'lot18-tester'],
            ['name' => 'LOT 18 Tester']
        );

        $permission = Permission::query()
            ->where('name', 'payments.create')
            ->firstOrFail();

        $role->permissions()->syncWithoutDetaching([
            $permission->id,
        ]);

        $this->user->roles()->syncWithoutDetaching([
            $role->id,
        ]);

        $this->actingAs($this->user);

        $this->categoryService = app(
            ExpenseCategoryManagementService::class
        );

        $this->cashService = app(
            CashManagementService::class
        );

        $this->expenseService = app(
            ExpenseManagementService::class
        );
    }

    public function test_expense_category_can_be_created(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Transport',
            'description' => 'Frais de déplacement',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'name' => 'Transport',
            'is_active' => true,
        ]);
    }

    public function test_cash_register_can_be_created(): void
    {
        $register = $this->cashService->createRegister([
            'code' => 'CAISSE-01',
            'name' => 'Caisse principale',
            'initial_balance' => '1000.00',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('cash_registers', [
            'id' => $register->id,
            'code' => 'CAISSE-01',
            'initial_balance' => '1000.00',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_manual_cash_entry_updates_balance(): void
    {
        $register = $this->cashService->createRegister([
            'code' => 'CAISSE-02',
            'name' => 'Caisse secondaire',
            'initial_balance' => '500.00',
        ]);

        $this->cashService->createManualTransaction(
            $register->id,
            [
                'transaction_date' => now()->toDateString(),
                'type' => CashTransaction::TYPE_ENTRY,
                'amount' => '250.00',
                'reference' => 'ENT-001',
                'description' => 'Entrée test',
            ]
        );

        $this->assertSame(
            '750.00',
            $this->cashService->currentBalance(
                $register->fresh()
            )
        );
    }

    public function test_manual_exit_cannot_exceed_cash_balance(): void
    {
        $register = $this->cashService->createRegister([
            'code' => 'CAISSE-03',
            'name' => 'Caisse test',
            'initial_balance' => '100.00',
        ]);

        $this->expectException(
            ValidationException::class
        );

        $this->cashService->createManualTransaction(
            $register->id,
            [
                'transaction_date' => now()->toDateString(),
                'type' => CashTransaction::TYPE_EXIT,
                'amount' => '150.00',
            ]
        );
    }

    public function test_cash_expense_creates_atomic_cash_exit(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Fournitures',
        ]);

        $register = $this->cashService->createRegister([
            'code' => 'CAISSE-04',
            'name' => 'Caisse dépenses',
            'initial_balance' => '1000.00',
        ]);

        $expense = $this->expenseService->create([
            'expense_category_id' => $category->id,
            'payment_method_id' => $this->cashMethod->id,
            'cash_register_id' => $register->id,
            'expense_date' => now()->toDateString(),
            'amount' => '300.00',
            'tax_amount' => '50.00',
            'reference' => 'EXP-001',
            'description' => 'Achat fournitures',
        ]);

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'amount' => '300.00',
            'tax_amount' => '50.00',
        ]);

        $this->assertDatabaseHas('cash_transactions', [
            'expense_id' => $expense->id,
            'cash_register_id' => $register->id,
            'type' => CashTransaction::TYPE_EXIT,
            'amount' => '300.00',
        ]);

        $this->assertSame(
            '700.00',
            $this->cashService->currentBalance(
                $register->fresh()
            )
        );
    }

    public function test_non_cash_expense_creates_no_cash_transaction(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Télécom',
        ]);

        $expense = $this->expenseService->create([
            'expense_category_id' => $category->id,
            'payment_method_id' => $this->bankTransferMethod->id,
            'expense_date' => now()->toDateString(),
            'amount' => '400.00',
            'tax_amount' => '40.00',
            'reference' => 'VIR-EXP-001',
        ]);

        $this->assertNull(
            $expense->cash_register_id
        );

        $this->assertDatabaseMissing(
            'cash_transactions',
            [
                'expense_id' => $expense->id,
            ]
        );
    }

    public function test_inactive_category_cannot_receive_new_expense(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Inactive',
            'is_active' => false,
        ]);

        $this->expectException(
            ValidationException::class
        );

        $this->expenseService->create([
            'expense_category_id' => $category->id,
            'payment_method_id' => $this->bankTransferMethod->id,
            'expense_date' => now()->toDateString(),
            'amount' => '100.00',
        ]);
    }

    public function test_tax_cannot_exceed_expense_amount(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Services',
        ]);

        $this->expectException(
            ValidationException::class
        );

        $this->expenseService->create([
            'expense_category_id' => $category->id,
            'payment_method_id' => $this->bankTransferMethod->id,
            'expense_date' => now()->toDateString(),
            'amount' => '100.00',
            'tax_amount' => '120.00',
        ]);
    }

    public function test_cash_expense_is_rolled_back_when_balance_is_insufficient(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Maintenance',
        ]);

        $register = $this->cashService->createRegister([
            'code' => 'CAISSE-05',
            'name' => 'Petite caisse',
            'initial_balance' => '100.00',
        ]);

        try {
            $this->expenseService->create([
                'expense_category_id' => $category->id,
                'payment_method_id' => $this->cashMethod->id,
                'cash_register_id' => $register->id,
                'expense_date' => now()->toDateString(),
                'amount' => '500.00',
                'tax_amount' => '0.00',
            ]);

            $this->fail(
                'La dépense aurait dû être refusée.'
            );
        } catch (ValidationException) {
            //
        }

        $this->assertDatabaseCount(
            'expenses',
            0
        );

        $this->assertDatabaseCount(
            'cash_transactions',
            0
        );
    }

    public function test_finance_routes_require_payment_permissions(): void
    {
        auth()->logout();

        $this->get(
            route('finance.expenses.index')
        )->assertRedirect(
            route('login')
        );

        $viewer = $this->createUserWithPermissions([
            'payments.view',
        ]);

        $this->actingAs($viewer);

        $this->get(
            route('finance.expenses.index')
        )->assertOk();

        $this->get(
            route('finance.cash.index')
        )->assertOk();
    }

    public function test_expense_form_requires_create_permission(): void
    {
        $viewer = $this->createUserWithPermissions([
            'payments.view',
        ]);

        $this->actingAs($viewer);

        $this->get(
            route('finance.expenses.create')
        )->assertForbidden();
    }

    public function test_expense_form_component_creates_expense(): void
    {
        $category = $this->categoryService->create([
            'name' => 'Internet',
        ]);

        Volt::test('admin.expense-form')
            ->set(
                'expenseCategoryId',
                (string) $category->id
            )
            ->set(
                'paymentMethodId',
                (string) $this->bankTransferMethod->id
            )
            ->set(
                'expenseDate',
                today()->toDateString()
            )
            ->set('amount', '240.00')
            ->set('taxAmount', '40.00')
            ->set('reference', 'EXP-UI-001')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('expenses', [
            'reference' => 'EXP-UI-001',
            'amount' => '240.00',
            'tax_amount' => '40.00',
        ]);
    }

    private function createUserWithPermissions(
        array $permissions
    ): User {
        $user = User::factory()->create([
            'must_change_password' => false,
        ]);

        $role = Role::query()->create([
            'name' => 'Test role '.str()->random(8),
            'slug' => 'test-role-'.str()->random(8),
        ]);

        $permissionIds = Permission::query()
            ->whereIn(
                'name',
                $permissions
            )
            ->pluck('id');

        $role->permissions()->sync(
            $permissionIds
        );

        $user->roles()->attach(
            $role
        );

        return $user;
    }

    public function test_amount_accepts_only_two_decimals(): void
    {
        $register = $this->cashService->createRegister([
            'code' => 'CAISSE-06',
            'name' => 'Caisse précision',
            'initial_balance' => '1000.00',
        ]);

        $this->expectException(
            ValidationException::class
        );

        $this->cashService->createManualTransaction(
            $register->id,
            [
                'transaction_date' => now()->toDateString(),
                'type' => CashTransaction::TYPE_ENTRY,
                'amount' => '12.345',
            ]
        );
    }
}
