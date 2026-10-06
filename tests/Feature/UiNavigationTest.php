<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\Support\CustomerReminderFixtures;
use Tests\TestCase;

class UiNavigationTest extends TestCase
{
    use CustomerReminderFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_stock_user_sees_only_authorized_navigation_groups(): void
    {
        $this->actingAs($this->userWithPermissions(['stock.view']));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Stocks et dépôts')->assertSee('Inventaires')
            ->assertDontSee(route('admin.users.index'), false)
            ->assertDontSee(route('sales.invoices.index'), false)
            ->assertDontSee(route('finance.expenses.index'), false)
            ->assertSee('aria-controls="erp-sidebar"', false)
            ->assertSee('Aller au contenu');
    }

    public function test_finance_user_keeps_supplier_payments_without_purchase_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['payments.view']));
        $this->get(route('dashboard'))->assertOk()
            ->assertSee(route('purchases.payments.index'), false)
            ->assertSee(route('finance.receivables.index'), false)
            ->assertDontSee(route('purchases.orders.index'), false);
    }

    public function test_invoice_detail_has_readable_breadcrumbs_and_active_link(): void
    {
        $this->actingAs($this->userWithPermissions(['sales.create', 'sales.update', 'invoices.create', 'invoices.validate', 'invoices.view']));
        $invoice = $this->invoice();
        $this->get(route('sales.invoices.show', $invoice))->assertOk()
            ->assertSee('Fil d’Ariane')->assertSee($invoice->number)
            ->assertSee('Factures clients')->assertSee('aria-current="page"', false);
    }

    public function test_audit_kpis_and_reset_preserve_the_existing_query_filters(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'super-admin')->first());
        $this->actingAs($admin);
        Volt::test('admin.audit-manager')->set('action', 'created')
            ->assertViewHas('stats', fn ($stats) => $stats->total > 0 && $stats->updated == 0)
            ->call('resetFilters')->assertSet('action', '')->assertSet('source', 'operations');
    }

    public function test_navigation_destinations_render_fresh_active_links_and_open_groups(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('slug', 'super-admin')->first());
        $this->actingAs($admin);

        foreach (['admin.audit.index', 'dashboard', 'admin.customers.index', 'sales.orders.index', 'sales.invoices.index', 'finance.receivables.index', 'finance.expenses.index', 'finance.cash.index', 'admin.stock.index', 'reports.index', 'admin.audit.index'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
            $xpath = new \DOMXPath($dom);
            $this->assertSame(0, $xpath->query('//body/text()[normalize-space(.) != ""]')->length, 'Aucun attribut Alpine orphelin dans le body : '.$route);
            $shell = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " erp-ui-shell ")]')->item(0);
            $this->assertSame('{ sidebarOpen: false, desktop: window.innerWidth >= 1024 }', $shell->getAttribute('x-data'));
            $backdrop = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " erp-drawer-backdrop ")]')->item(0);
            $this->assertSame('sidebarOpen && !desktop', $backdrop->getAttribute('x-show'));
            $this->assertStringContainsString('display: none; pointer-events: none;', $backdrop->getAttribute('style'));
            $this->assertFalse($backdrop->hasAttribute('x-transition.opacity'));
            $active = $xpath->query('//aside[@id="erp-sidebar"]//a[@aria-current="page"]');
            $this->assertCount(1, $active, $route);
            $this->assertSame(route($route), $active->item(0)->getAttribute('href'));
            $this->assertSame(0, $xpath->query('//aside[@id="erp-sidebar"]//a[@*[name()="wire:navigate"]]')->length);
            $this->assertSame(0, $xpath->query('//aside[@id="erp-sidebar"]/ancestor::*[@*[name()="wire:id"]]')->length);
            $this->assertSame(0, $xpath->query('//aside[@id="erp-sidebar"]/ancestor::form')->length);
            $this->assertSame(0, $xpath->query('//aside[@id="erp-sidebar"]//a/ancestor::button')->length);
            foreach ($xpath->query('//aside[@id="erp-sidebar"]//a') as $link) {
                foreach ($link->attributes as $attribute) {
                    $this->assertFalse(str_starts_with($attribute->name, 'wire:') || str_starts_with($attribute->name, '@') || str_starts_with($attribute->name, 'x-on:'), $attribute->name);
                }
            }
            if ($route !== 'dashboard') {
                $this->assertSame(1, $xpath->query('//aside[@id="erp-sidebar"]//details[@open]//a[@aria-current="page"]')->length);
            }
            $this->assertSame(1, $xpath->query('//main[@id="main-content"]')->length);
            $this->assertSame(0, $xpath->query('//body[@x-data]')->length);
        }
    }
}
