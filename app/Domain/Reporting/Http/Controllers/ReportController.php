<?php

namespace App\Domain\Reporting\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\CsvWriter;
use App\Domain\Reporting\ReportingQueries;
use App\Domain\Reporting\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Back-office figures: home page, finance summary for a period, and CSV exports (Excel compatible).
 */
class ReportController
{
    public function __construct(private readonly ReportingQueries $queries) {}

    /**
     * Figures of the back-office home page: members, total collected, items awaiting validation, YEM in circulation.
     */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->queries->overview()]);
    }

    /**
     * Finance summary of a period (default: the last 30 days): KPIs, revenue by source and by payment method, top
     * members. Amounts are what the cooperative receives, transaction fees excluded. Shares are in basis points.
     */
    public function summary(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return response()->json(['data' => $this->queries->summary($from, $to)]);
    }

    /**
     * Printable version of the summary (HTML laid out for printing: "Print > Save as PDF" in the browser), with the
     * same period filters: month=YYYY-MM for the standard monthly report, or from/to.
     */
    public function printable(Request $request, AuditLogger $auditLogger): Response
    {
        [$from, $to] = $this->period($request);
        $summary = $this->queries->summary($from, $to);

        $auditLogger->record('reports.printed', metadata: ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()]);

        return response()->view('reports.summary', [
            'summary' => $summary,
            'from' => $from,
            'lastDay' => $to->subDay(),
            'generatedAt' => now(),
        ])->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'");
    }

    /**
     * Download an export as CSV (semicolon separated, UTF-8 with BOM, opens directly in Excel). Each export is
     * recorded in the audit log, as it carries personal data.
     *
     * Types: members, payments (confirmed payments of the period), subscriptions (active packs), merchant_payments
     * (payments to merchants created during the period, whatever their status).
     */
    public function export(Request $request, string $type, AuditLogger $auditLogger): StreamedResponse
    {
        abort_unless(in_array($type, ['members', 'payments', 'subscriptions', 'merchant_payments'], true), 404);
        [$from, $to] = $this->period($request);

        $rows = match ($type) {
            'members' => $this->queries->membersRows(),
            'payments' => $this->queries->paymentsRows($from, $to),
            'subscriptions' => $this->queries->subscriptionsRows(),
            'merchant_payments' => $this->queries->merchantPaymentsRows($from, $to),
        };

        $auditLogger->record('reports.exported', metadata: array_filter([
            'type' => $type,
            'from' => in_array($type, ['payments', 'merchant_payments'], true) ? $from->toIso8601ZuluString() : null,
            'to' => in_array($type, ['payments', 'merchant_payments'], true) ? $to->toIso8601ZuluString() : null,
        ]));

        $filename = sprintf('cect-%s-%s.csv', $type, now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');

            if ($output !== false) {
                CsvWriter::write($output, $rows);
                fclose($output);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        return ReportPeriod::fromRequest($request);
    }
}
