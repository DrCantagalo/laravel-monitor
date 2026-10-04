<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * laravel-monitor 258 (v0.53.0, breaking): a classificação bot/human +
     * tags + note deixa de pertencer ao IP (`monitor_ip_labels`) e passa a
     * pertencer ao `Monitor` (visitante) — uma linha 1:1 por `Monitor`, FK
     * `cascadeOnDelete` (mesmo padrão de `monitor_page_hits`/
     * `monitor_visit_ips`). Decidido com o usuário em conversa
     * (2026-09-30): o IP não é o visitante (CGNAT, IP reatribuído, proxy
     * residencial) — a classificação é uma conclusão tirada dos DADOS do
     * monitor (UA, hits, paths), então sem o monitor não deve sobrar
     * rótulo, nem `source=manual` (que antes sobrevivia indefinidamente a
     * um IP nunca mais visto). O rótulo por IP usado por
     * `getVisitorsByIp`/`getBlockedIps`/`getIpMonitors` agora é DERIVADO na
     * hora, nunca gravado — ver `MonitorController::derivedLabelsForIps()`.
     *
     * Migration de dados: cada linha de `monitor_ip_labels` (por `ip`) é
     * copiada pra todos os `Monitor` daquele IP (via `monitor_visit_ips`),
     * mantendo `kind`/`tags`/`note`/`source`/`classified_at`. Um `Monitor`
     * pode herdar rótulos conflitantes de mais de um IP (ex: dois IPs
     * diferentes já viram o mesmo dispositivo, cada um com seu próprio
     * `monitor_ip_labels`) — resolvido assim: `source=manual` sempre vence
     * sobre qualquer outra origem; entre dois candidatos da MESMA
     * "categoria" de proteção (ambos manual, ou nenhum manual), o mais
     * recente por `classified_at` vence. Uma linha de `monitor_ip_labels`
     * cujo IP não tem nenhum `Monitor` associado (nunca apareceu em
     * `monitor_visit_ips`) é descartada — não há visitante pra herdar o
     * rótulo.
     *
     * `monitor_ip_labels` é histórico e tipicamente pequeno (é uma lista
     * curada de IPs classificados manualmente/pela IA, não uma tabela de
     * tracking por request), então o merge inteiro é resolvido em memória
     * PHP numa única passada — mesmo raciocínio de tamanho que já
     * permitia `MonitorIpLabel::whereNotNull('kind')->pluck('ip')` sem
     * paginação em `buildVisitorsResult()`.
     */
    public function up(): void
    {
        Schema::create('monitor_labels', function (Blueprint $table) {
            $table->id();
            // O `unique` de monitor_id é criado em
            // 2026_10_04_000000_add_unique_monitor_id_to_monitor_labels_table
            // (o `->unique()` que estava encadeado aqui, depois de
            // `constrained()`, não criava índice nenhum).
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            // null = indefinido, mesmo raciocínio de monitor_ip_labels:
            // 'bot'/'human' validados na aplicação, não via enum() do banco.
            $table->string('kind')->nullable();
            $table->json('tags')->nullable();
            $table->text('note')->nullable();
            // 'manual' (default) | 'ai'.
            $table->string('source')->default('manual');
            $table->dateTime('classified_at')->nullable();
            $table->timestamps();
        });

        if (Schema::hasTable('monitor_ip_labels')) {
            $candidates = [];

            DB::table('monitor_ip_labels')->orderBy('id')->get()->each(function ($label) use (&$candidates) {
                $monitorIds = DB::table('monitor_visit_ips')->where('ip', $label->ip)->pluck('monitor_id');

                foreach ($monitorIds as $monitorId) {
                    $candidate = [
                        'kind' => $label->kind,
                        'tags' => $label->tags,
                        'note' => $label->note,
                        'source' => $label->source,
                        'classified_at' => $label->classified_at,
                    ];

                    $existing = $candidates[$monitorId] ?? null;

                    if ($existing === null) {
                        $candidates[$monitorId] = $candidate;

                        continue;
                    }

                    // source=manual sempre vence sobre qualquer outra
                    // origem, nos dois sentidos (nunca é substituído por
                    // um candidato não-manual, e sempre substitui um
                    // candidato não-manual já escolhido).
                    if ($existing['source'] === 'manual' && $candidate['source'] !== 'manual') {
                        continue;
                    }

                    if ($candidate['source'] === 'manual' && $existing['source'] !== 'manual') {
                        $candidates[$monitorId] = $candidate;

                        continue;
                    }

                    // Mesma categoria de proteção (ambos manual, ou
                    // nenhum): o mais recente por classified_at vence.
                    // Um candidato sem classified_at nunca desloca um já
                    // escolhido.
                    if ($candidate['classified_at'] !== null
                        && ($existing['classified_at'] === null || $candidate['classified_at'] > $existing['classified_at'])) {
                        $candidates[$monitorId] = $candidate;
                    }
                }
            });

            $now = now();
            $rows = [];

            foreach ($candidates as $monitorId => $candidate) {
                $rows[] = [
                    'monitor_id' => $monitorId,
                    'kind' => $candidate['kind'],
                    'tags' => $candidate['tags'],
                    'note' => $candidate['note'],
                    'source' => $candidate['source'],
                    'classified_at' => $candidate['classified_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('monitor_labels')->insert($chunk);
            }

            Schema::dropIfExists('monitor_ip_labels');
        }
    }

    /**
     * Recria `monitor_ip_labels` VAZIA (não restaura os dados já migrados
     * pra `monitor_labels`) — mesma convenção de down() irreversível já
     * usada por outras migrations deste pacote quando o dado de origem já
     * não existe mais depois do up() (ver histórico do pacote).
     */
    public function down(): void
    {
        Schema::dropIfExists('monitor_labels');

        Schema::create('monitor_ip_labels', function (Blueprint $table) {
            $table->id();
            $table->string('ip')->unique();
            $table->string('kind')->nullable();
            $table->json('tags')->nullable();
            $table->text('note')->nullable();
            $table->string('source')->default('manual');
            $table->dateTime('classified_at')->nullable();
            $table->timestamps();
        });
    }
};
