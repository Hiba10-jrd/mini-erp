<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('payment_type', 30)
                ->default('other')
                ->after('name');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->json('details')
                ->nullable()
                ->after('reference');
        });

        DB::table('payment_methods')
            ->whereIn('name', ['Espèces', 'Especes'])
            ->update(['payment_type' => 'cash']);

        DB::table('payment_methods')
            ->whereIn('name', ['Chèque', 'Cheque'])
            ->update(['payment_type' => 'cheque']);

        DB::table('payment_methods')
            ->whereIn('name', ['Virement', 'Virement bancaire'])
            ->update(['payment_type' => 'bank_transfer']);

        DB::table('payment_methods')
            ->whereIn('name', ['Carte', 'Carte bancaire'])
            ->update(['payment_type' => 'card']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('details');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });
    }
};