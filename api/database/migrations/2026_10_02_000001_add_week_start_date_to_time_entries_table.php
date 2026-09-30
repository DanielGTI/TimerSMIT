<?php

use App\Support\WeekCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Segunda-feira da semana do lançamento, gravada junto com local_date
        // (que nunca muda depois de criado). Permite ligar o lançamento ao
        // envio da semana (estado aberta/enviada/...) com um JOIN simples, igual
        // em PostgreSQL e SQLite. Preenchida pelo model ao criar; nula só em
        // linhas antigas até o backfill abaixo.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->date('week_start_date')->nullable()->after('local_date');

            $table->index(['tenant_id', 'local_date']);
            $table->index(['tenant_id', 'member_id', 'week_start_date']);
        });

        $this->backfill();
    }

    public function backfill(): void
    {
        DB::table('time_entries')->whereNull('week_start_date')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('time_entries')
                    ->where('id', $row->id)
                    ->update(['week_start_date' => WeekCalendar::startOf(substr((string) $row->local_date, 0, 10))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'local_date']);
            $table->dropIndex(['tenant_id', 'member_id', 'week_start_date']);
            $table->dropColumn('week_start_date');
        });
    }
};
