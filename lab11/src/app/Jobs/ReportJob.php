<?php
// app/Jobs/ReportJob.php — отчёт по приёмам в CSV-файл (очередь reports).
//
// Задачу ставит ReportApiController::create. payload: report_id — номер заявки; период и статус берутся из заявки.
// Файл: storage/reports/report-<номер>.csv (разделитель «;» и метка UTF-8, чтобы Excel показал кириллицу).
// На 500 000 приёмов отчёт строится несколько секунд, поэтому строки читаются курсором порциями по 5 000,
// а не все сразу в память. Повтор задачи перезаписывает тот же файл и ту же заявку: дубля не будет.

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Database;
use App\Models\ReportRequestRepository;

final class ReportJob
{
    private const STATUSES = ['scheduled' => 'запланирован', 'done' => 'проведён', 'cancelled' => 'отменён'];
    private const SPECIES = ['dog' => 'собака', 'cat' => 'кошка', 'bird' => 'птица', 'rodent' => 'грызун', 'other' => 'другое'];

    public function handle(array $payload, string $jobId, int $attempt): void
    {
        $id = (int) $payload['report_id'];
        $requests = new ReportRequestRepository();
        $request = $requests->find($id) ?? throw new \RuntimeException('Заявка на отчёт №' . $id . ' не найдена');
        $requests->markProcessing($id);

        $fileName = 'report-' . $id . '.csv';
        $path = BASE_PATH . '/storage/reports/' . $fileName;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);                // папки может не быть в копии без пустых каталогов
        }
        $pdo = Database::connection();
        try {
            Jobs::pause(config('queue')['report_seconds']);

            [$where, $params] = $this->filter($request);
            $pdo->beginTransaction();                         // курсор живёт только внутри транзакции
            $pdo->prepare("DECLARE report_cursor NO SCROLL CURSOR FOR
                SELECT a.id, to_char(a.scheduled_at, 'DD.MM.YYYY HH24:MI') AS date, a.status, a.title,
                       an.name AS animal, an.species, o.name AS owner, o.phone, d.name AS doctor,
                       COALESCE(s.services, '') AS services, COALESCE(s.amount, 0) AS amount
                FROM appointments a
                JOIN animals an ON an.id = a.animal_id
                JOIN owners o ON o.id = an.owner_id
                LEFT JOIN doctors d ON d.id = a.doctor_id
                LEFT JOIN (SELECT x.appointment_id,
                                  string_agg(sv.name || CASE WHEN x.quantity > 1 THEN ' ×' || x.quantity ELSE '' END, ', ' ORDER BY sv.name) AS services,
                                  SUM(x.quantity * x.price) AS amount
                           FROM appointment_services x JOIN services sv ON sv.id = x.service_id
                           GROUP BY x.appointment_id) s ON s.appointment_id = a.id
                $where
                ORDER BY a.scheduled_at, a.id")->execute($params);

            $file = fopen($path, 'w') ?: throw new \RuntimeException('Не удалось создать файл ' . $fileName);
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Номер', 'Дата', 'Статус', 'Причина обращения', 'Животное', 'Вид', 'Владелец', 'Телефон', 'Врач', 'Услуги', 'Сумма, ₽'], ';');
            $rows = 0;
            $revenue = 0.0;
            $fetch = $pdo->prepare('FETCH 5000 FROM report_cursor');
            do {
                $fetch->execute();
                $batch = $fetch->fetchAll();
                foreach ($batch as $r) {
                    $amount = (float) $r['amount'];
                    if ($r['status'] === 'done') {
                        $revenue += $amount;                  // выручка — только за проведённые приёмы
                    }
                    fputcsv($file, [
                        $r['id'], $r['date'], self::STATUSES[$r['status']] ?? $r['status'], $r['title'], $r['animal'],
                        self::SPECIES[$r['species']] ?? $r['species'], $r['owner'], $r['phone'], $r['doctor'] ?? '—',
                        $r['services'], number_format($amount, 2, ',', ''),
                    ], ';');
                }
                $rows += count($batch);
            } while ($batch !== []);
            fclose($file);
            $pdo->commit();
            $requests->markDone($id, $fileName, $rows, round($revenue, 2));
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($attempt < config('queue')['max_attempts']) {
                $requests->markRetry($id, 'попытка ' . $attempt . ' не удалась: ' . $e->getMessage());   // будет повтор
            } else {
                $requests->markFailed($id, $e->getMessage());                                          // попыток больше нет
            }
            throw $e;                                         // воркер должен узнать, что задача не выполнена
        }
    }

    // Условия отчёта из заявки: период и статус приёма
    private function filter(array $request): array
    {
        $conditions = [];
        $params = [];
        if ($request['date_from'] !== null) {
            $conditions[] = 'a.scheduled_at >= :date_from';
            $params['date_from'] = $request['date_from'];
        }
        if ($request['date_to'] !== null) {
            $conditions[] = "a.scheduled_at < :date_to::date + INTERVAL '1 day'";   // включительно по дату окончания
            $params['date_to'] = $request['date_to'];
        }
        if ($request['status_filter'] !== null) {
            $conditions[] = 'a.status = :status';
            $params['status'] = $request['status_filter'];
        }
        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }
}
