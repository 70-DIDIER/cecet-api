<?php
/**
 * QA synthetic pressure test on PostgreSQL TEMP tables only.
 * Models 100k distinct accounts and 1m operations under purchase/reserve/consume/cashback math.
 * This is NOT 1m real HTTP transactions and never modifies any CECT business tables.
 *
 * Usage: php scripts/qa-synthetic-ledger-million.php
 */
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$conn=config('database.connections.pgsql');
$conn['database']='cectapi_test';
$conn['url']=null;
config(['database.connections.qa_synthetic'=>$conn]);
$db=Illuminate\Support\Facades\DB::connection('qa_synthetic');
if ($db->selectOne('select current_database() as name')->name !== 'cectapi_test') {
    throw new RuntimeException('QA safety gate: must be on cectapi_test, not cectapi_live');
}
$started=microtime(true);
$db->beginTransaction();
try {
    $db->statement(<<<'SQL'
CREATE TEMP TABLE qa_operations ON COMMIT DROP AS
SELECT gs AS operation_id,
       ((gs-1) % 100000)+1 AS member_id,
       ((gs-1) % 4)+1 AS family_id,
       100000::bigint AS amount_xof,
       ROUND(100000::numeric/650::numeric,6) AS base_yem,
       CASE WHEN ((gs-1)%4)+1 IN (2,3)
          THEN ROUND(ROUND(100000::numeric/650::numeric,6)*0.05,6)
          ELSE 0::numeric END AS cashback_yem,
       ROUND(ROUND(100000::numeric/650::numeric,6)*0.1,6) AS reserved_yem,
       ROUND(ROUND(100000::numeric/650::numeric,6)*0.3,6) AS consumed_yem
FROM generate_series(1,1000000) AS gs;
SQL);
    $db->statement('CREATE INDEX ON qa_operations (member_id, family_id)');
    $row=$db->selectOne(<<<'SQL'
SELECT count(*) AS operations,
       count(DISTINCT member_id) AS accounts,
       count(*) FILTER (WHERE family_id IN (2,3) AND cashback_yem>0) AS cashback_operations,
       count(*) FILTER (WHERE family_id IN (1,4) AND cashback_yem<>0) AS forbidden_cashback,
       count(*) FILTER (WHERE base_yem+cashback_yem-reserved_yem-consumed_yem<0) AS negative_balances,
       COALESCE(sum(amount_xof),0)::text AS aggregate_xof,
       COALESCE(sum(base_yem),0)::text AS aggregate_base_yem,
       COALESCE(sum(cashback_yem),0)::text AS aggregate_cashback_yem
FROM qa_operations;
SQL);
    $check=(int)$row->operations===1000000
        && (int)$row->accounts===100000
        && (int)$row->cashback_operations===500000
        && (int)$row->forbidden_cashback===0
        && (int)$row->negative_balances===0;
    if (!$check) throw new RuntimeException('Synthetic invariant failed: '.json_encode($row));
    echo json_encode([
        'dataset'=>'temporary PostgreSQL tables',
        'accounts'=>(int)$row->accounts,
        'operations'=>(int)$row->operations,
        'cashback_operations'=>(int)$row->cashback_operations,
        'forbidden_cashback'=>(int)$row->forbidden_cashback,
        'negative_balances'=>(int)$row->negative_balances,
        'aggregate_xof'=>$row->aggregate_xof,
        'aggregate_base_yem'=>$row->aggregate_base_yem,
        'aggregate_cashback_yem'=>$row->aggregate_cashback_yem,
        'elapsed_seconds'=>round(microtime(true)-$started,3),
    ], JSON_THROW_ON_ERROR)."\n";
} finally {
    $db->rollBack(); // clean even when assertions fail
}
