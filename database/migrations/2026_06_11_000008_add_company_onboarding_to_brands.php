<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados fiscais/cadastrais da empresa (preenchidos no cadastro via CNPJ/CEP)
 * e respostas de onboarding (segmento, objetivos) usadas no painel do admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('cnpj', 18)->nullable()->after('website');
            $table->string('legal_name')->nullable()->after('cnpj'); // razão social
            $table->string('phone', 30)->nullable()->after('legal_name');
            $table->string('company_size')->nullable()->after('phone'); // porte

            // Endereço
            $table->string('cep', 9)->nullable()->after('company_size');
            $table->string('address_street')->nullable()->after('cep');
            $table->string('address_number', 30)->nullable()->after('address_street');
            $table->string('address_complement')->nullable()->after('address_number');
            $table->string('address_neighborhood')->nullable()->after('address_complement');
            $table->string('address_city')->nullable()->after('address_neighborhood');
            $table->string('address_state', 2)->nullable()->after('address_city');

            // Onboarding / intenção de uso
            $table->json('goals')->nullable()->after('address_state'); // o que busca
            $table->text('objective')->nullable()->after('goals');     // o que pretende

            $table->index('cnpj');
            $table->index('company_size');
            $table->index('address_state');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropIndex(['cnpj']);
            $table->dropIndex(['company_size']);
            $table->dropIndex(['address_state']);
            $table->dropColumn([
                'cnpj', 'legal_name', 'phone', 'company_size',
                'cep', 'address_street', 'address_number', 'address_complement',
                'address_neighborhood', 'address_city', 'address_state',
                'goals', 'objective',
            ]);
        });
    }
};
