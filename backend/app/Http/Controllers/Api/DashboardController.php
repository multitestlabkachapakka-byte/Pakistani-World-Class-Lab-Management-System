<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Report;
use App\Models\ReportResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $today = Carbon::now('Asia/Karachi')->toDateString();
        $yesterday = Carbon::now('Asia/Karachi')->subDay()->toDateString();
        $weekStart = Carbon::now('Asia/Karachi')->startOfWeek()->toDateString();
        $monthStart = Carbon::now('Asia/Karachi')->startOfMonth()->toDateString();

        $totalPatients = Patient::count();
        $patientsToday = Patient::whereDate('created_at', $today)->count();
        $patientsWeek = Patient::whereDate('created_at', '>=', $weekStart)->count();
        $patientsMonth = Patient::whereDate('created_at', '>=', $monthStart)->count();

        $totalReports = Report::count();
        $reportsToday = Report::whereDate('created_at', $today)->count();
        $reportsYesterday = Report::whereDate('created_at', $yesterday)->count();
        $reportsWeek = Report::whereDate('created_at', '>=', $weekStart)->count();
        $reportsMonth = Report::whereDate('created_at', '>=', $monthStart)->count();

        $pending = Report::where('status', 'draft')->count();
        $completed = Report::where('status', 'final')->count();
        $abnormal = ReportResult::where('is_abnormal', true)
            ->whereHas('report', fn ($q) => $q->where('status', 'final'))
            ->distinct('report_id')
            ->count('report_id');

        // Department-wise tests today (from report_tests)
        $deptToday = DB::table('report_tests')
            ->join('reports', 'reports.id', '=', 'report_tests.report_id')
            ->whereDate('reports.created_at', $today)
            ->select('report_tests.department_snapshot as department', DB::raw('count(*) as total'))
            ->groupBy('report_tests.department_snapshot')
            ->orderByDesc('total')
            ->get();

        // Recent reports
        $recent = Report::with('patient:id,name,mr_number')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'report_number', 'patient_id', 'status', 'created_at', 'reported_at']);

        return response()->json([
            'data' => [
                'patients' => [
                    'total' => $totalPatients,
                    'today' => $patientsToday,
                    'week' => $patientsWeek,
                    'month' => $patientsMonth,
                ],
                'reports' => [
                    'total' => $totalReports,
                    'today' => $reportsToday,
                    'yesterday' => $reportsYesterday,
                    'week' => $reportsWeek,
                    'month' => $reportsMonth,
                ],
                'pending' => $pending,
                'completed' => $completed,
                'abnormal_reports' => $abnormal,
                'departments_today' => $deptToday,
                'recent_reports' => $recent,
            ],
            'meta' => [
                'date' => $today,
                'timezone' => 'Asia/Karachi',
            ],
        ]);
    }
}
