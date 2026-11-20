<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TestDefinition;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /**
     * Search tests by name, short name, code or any alias.
     */
    public function search(Request $request)
    {
        $q = $request->string('q')->trim()->toString();
        $department = $request->get('department');
        $limit = min((int) $request->get('limit', 30), 100);

        $query = TestDefinition::query()
            ->where('is_active', true)
            ->with(['parameters' => fn ($q) => $q->orderBy('sort_order')]);

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('short_name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhereJsonContains('aliases', $q);
            });
        }

        if ($department) {
            $query->where('department', $department);
        }

        $tests = $query->orderBy('department')->orderBy('sort_order')->orderBy('name')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $tests,
            'meta' => ['count' => $tests->count()],
        ]);
    }

    public function show(string $id)
    {
        $test = TestDefinition::with(['parameters.referenceRanges'])
            ->findOrFail($id);

        return response()->json(['data' => $test]);
    }

    public function departments()
    {
        $deps = TestDefinition::where('is_active', true)
            ->select('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        return response()->json(['data' => $deps]);
    }
}
