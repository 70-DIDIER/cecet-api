<?php

namespace App\Domain\Backoffice\Http\Controllers;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reporting\ReportingQueries;
use App\Domain\Reporting\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The period report: indicators, revenue by source and payment method, top members, YEM activity and payouts.
 * The same figures as the API's summary; the printable version is saved as a PDF from the browser.
 */
class ReportsController
{
    public function __construct(private readonly ReportingQueries $queries) {}

    public function index(Request $request): View
    {
        [$from, $to] = ReportPeriod::fromRequest($request);

        return view('backoffice.reports.index', [
            'from' => $from,
            'lastDay' => $to->subDay(),
            'summary' => $this->queries->summary($from, $to),
            'month' => $request->query('month'),
        ]);
    }

    public function print(Request $request, AuditLogger $auditLogger): Response
    {
        [$from, $to] = ReportPeriod::fromRequest($request);

        $auditLogger->record('reports.printed', metadata: ['from' => $from->toIso8601ZuluString(), 'to' => $to->toIso8601ZuluString()]);

        return response()->view('reports.summary', [
            'summary' => $this->queries->summary($from, $to),
            'from' => $from,
            'lastDay' => $to->subDay(),
            'generatedAt' => now(),
        ]);
    }
}
