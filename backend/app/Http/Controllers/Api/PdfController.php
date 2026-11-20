<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\ReportPdfService;
use Illuminate\Http\Request;

class PdfController extends Controller
{
    public function __construct(private ReportPdfService $pdf)
    {
    }

    /**
     * Return HTML report (browser print / later DomPDF).
     */
    public function show(string $id)
    {
        $report = Report::findOrFail($id);
        $html = $this->pdf->render($report);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
