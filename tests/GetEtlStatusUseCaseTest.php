<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/vendor/autoload.php';

use App\Application\Etl\GetEtlStatusUseCase;
use App\Domain\EtlRun;
use App\Domain\Repository\EtlRunLogRepositoryInterface;

class TestEtlRunLogRepository implements EtlRunLogRepositoryInterface
{
    /** @var EtlRun[] */
    private array $runs;

    public function __construct(array $runs)
    {
        $this->runs = $runs;
    }

    public function startRun(string $jobName): EtlRun
    {
        throw new RuntimeException('Not implemented');
    }

    public function finishRun(EtlRun $run, string $status, int $rowsAffected, ?string $message = null): EtlRun
    {
        throw new RuntimeException('Not implemented');
    }

    /** @return EtlRun[] */
    public function findRecentByJob(string $jobName, int $limit = 50): array
    {
        $found = [];
        foreach ($this->runs as $run) {
            if ($run->getJobName() === $jobName) {
                $found[] = $run;
            }
        }

        return array_slice($found, 0, $limit);
    }
}

$runs = [
    new EtlRun(1, 'INGEST_FINNHUB', '2024-01-01 00:00:00', '2024-01-01 00:05:00', 'OK', 100, 'done'),
    new EtlRun(2, 'CALC_INDICATORS', '2024-01-02 00:00:00', '2024-01-02 00:10:00', 'OK', 10, 'ok'),
];
$repo = new TestEtlRunLogRepository($runs);
$useCase = new GetEtlStatusUseCase($repo);
$status = $useCase->execute();

assert(count($status) === 5, 'Debe retornar cinco jobs');
assert($status[0]['job'] === 'INGEST_FINNHUB');
assert($status[0]['status'] === 'OK');
assert($status[0]['rows_affected'] === 100);
assert($status[0]['message'] === 'done');
assert($status[1]['job'] === 'INGEST_RAVA');
assert($status[1]['status'] === 'UNKNOWN');

echo "GetEtlStatusUseCase test passed.\n";
