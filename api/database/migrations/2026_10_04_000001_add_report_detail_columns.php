<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_item_snapshots', function (Blueprint $table) {
            // Caminho da iteração (System.IterationPath), lido do formulário
            // aberto como o título e o tipo — cosmético, só para relatório.
            $table->string('iteration_path', 500)->nullable()->after('work_item_type');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            // Início e fim reais (UTC) da fatia, só para lançamentos de timer;
            // lançamento manual não tem horário (ficam nulos).
            $table->timestamp('started_at_utc')->nullable()->after('duration_seconds');
            $table->timestamp('ended_at_utc')->nullable()->after('started_at_utc');
        });

        // Timers já fechados sem divisão pela meia-noite: a fatia é a sessão
        // inteira. Com mais de uma fatia não dá para saber a fronteira
        // exata depois do fato; ficam sem horário.
        DB::table('time_entries')
            ->whereNotNull('timer_session_id')
            ->whereNull('started_at_utc')
            ->orderBy('id')
            ->chunkById(500, function ($entries) {
                foreach ($entries as $entry) {
                    $siblings = DB::table('time_entries')->where('timer_session_id', $entry->timer_session_id)->count();
                    $session = DB::table('timer_sessions')->where('id', $entry->timer_session_id)->first();

                    if ($siblings === 1 && $session && $session->ended_at_utc) {
                        DB::table('time_entries')->where('id', $entry->id)->update([
                            'started_at_utc' => $session->started_at_utc,
                            'ended_at_utc' => $session->ended_at_utc,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn(['started_at_utc', 'ended_at_utc']);
        });

        Schema::table('work_item_snapshots', function (Blueprint $table) {
            $table->dropColumn('iteration_path');
        });
    }
};
