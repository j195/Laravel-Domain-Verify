<?php

namespace App\Http\Controllers;

use App\Models\CheckBatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $stats = [
            'batches' => CheckBatch::query()->where('user_id', $request->user()->id)->count(),
            'domains' => CheckBatch::query()->where('user_id', $request->user()->id)->sum('total'),
            'completed' => CheckBatch::query()->where('user_id', $request->user()->id)->sum('completed'),
        ];

        $recent = CheckBatch::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(8)
            ->get(['id', 'type', 'source', 'status', 'total', 'completed', 'failed', 'created_at']);

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recent' => $recent,
        ]);
    }
}
