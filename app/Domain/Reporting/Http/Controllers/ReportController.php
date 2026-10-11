<?php

namespace App\Domain\Reporting\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\CsvWriter;
use App\Domain\Reporting\PdfSummaryWriter;
use App\Domain\Reporting\ReportingQueries;
use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
     * Native PDF export of the finance summary for the same period filters as the dashboard.
     */
    public function pdf(Request $request, AuditLogger $auditLogger): Response
    {
        [$from, $to] = $this->period($request);
        $summary = $this->queries->summary($from, $to);
        $rows = $this->summaryRows($summary);

        $auditLogger->record('reports.pdf_exported', metadata: [
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
        ]);

        $title = sprintf('Rapport CECT du %s au %s', $from->format('d/m/Y'), $to->subDay()->format('d/m/Y'));
        $content = PdfSummaryWriter::make($title, $rows);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cect-rapport-'.$from->format('Ymd').'-'.$to->subDay()->format('Ymd').'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Real XLSX workbook export of the finance summary.
     */
    public function xlsx(Request $request, AuditLogger $auditLogger): BinaryFileResponse
    {
        [$from, $to] = $this->period($request);
        $summary = $this->queries->summary($from, $to);
        $rows = $this->summaryRows($summary);
        $path = tempnam(sys_get_temp_dir(), 'cect-xlsx-');

        abort_unless(is_string($path), 500);

        XlsxWriter::write($path, $rows);

        $auditLogger->record('reports.xlsx_exported', metadata: [
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
        ]);

        $filename = sprintf('cect-rapport-%s-%s.xlsx', $from->format('Ymd'), $to->subDay()->format('Ymd'));

        return response()->download(
            $path,
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
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
        abort_unless(in_array($type, ['members', 'payments', 'subscriptions', 'merchant_payments', 'exchanges', 'payouts', 'audit'], true), 404);
        [$from, $to] = $this->period($request);

        $rows = match ($type) {
            'members' => $this->queries->membersRows(),
            'payments' => $this->queries->paymentsRows($from, $to),
            'subscriptions' => $this->queries->subscriptionsRows(),
            'merchant_payments' => $this->queries->merchantPaymentsRows($from, $to),
            'exchanges' => $this->queries->exchangesRows($from, $to),
            'payouts' => $this->queries->payoutsRows($from, $to),
            'audit' => $this->queries->auditRows($from, $to),
        };

        $auditLogger->record('reports.exported', metadata: array_filter([
            'type' => $type,
            'from' => in_array($type, ['payments', 'merchant_payments', 'exchanges', 'payouts', 'audit'], true) ? $from->toIso8601ZuluString() : null,
            'to' => in_array($type, ['payments', 'merchant_payments', 'exchanges', 'payouts', 'audit'], true) ? $to->toIso8601ZuluString() : null,
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
     * Flatten a report into a two-column table suitable for PDF/XLSX exports.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{0:string,1:mixed}>
     */
    private function summaryRows(array $summary): array
    {
        $rows = [];

        $walk = function (mixed $value, string $prefix) use (&$walk, &$rows): void {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $index => $item) {
                        $walk($item, trim($prefix.' #'.($index + 1)));
                    }

                    return;
                }

                foreach ($value as $key => $item) {
                    $label = trim($prefix.' / '.str_replace('_', ' ', (string) $key), ' /');
                    $walk($item, $label);
                }

                return;
            }

            $rows[] = [$prefix, $value];
        };

        $walk($summary, '');

        return $rows;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request): array
    {
        return ReportPeriod::fromRequest($request);
    }
}
