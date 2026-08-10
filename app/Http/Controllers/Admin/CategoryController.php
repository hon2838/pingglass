<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::ordered()
            ->withCount('targets')
            ->get();

        return Inertia::render('Admin/Categories/Index', [
            'categories' => $categories,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Categories/Create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:categories,slug',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $category = Category::create($data);
        AuditLog::log('category.created', $category, $data);

        return redirect()->route('admin.categories.index')
            ->with('success', 'Category created.');
    }

    public function edit(Category $category)
    {
        return Inertia::render('Admin/Categories/Edit', [
            'category' => $category,
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string|max:1000',
            'is_public' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $category->update($data);
        AuditLog::log('category.updated', $category, $data);

        return redirect()->route('admin.categories.index')
            ->with('success', 'Category updated.');
    }

    public function destroy(Category $category)
    {
        if ($category->targets()->count() > 0) {
            return back()->with('error', 'Cannot delete category with targets. Remove targets first.');
        }

        AuditLog::log('category.deleted', $category);
        $category->delete();

        return redirect()->route('admin.categories.index')
            ->with('success', 'Category deleted.');
    }

    public function toggle(Category $category, Request $request)
    {
        $field = $request->input('field');
        if (!in_array($field, ['is_public', 'is_enabled'])) {
            return back()->with('error', 'Invalid field.');
        }

        $category->update([$field => !$category->$field]);
        AuditLog::log("category.toggled", $category, [$field => $category->$field]);

        return back()->with('success', 'Category updated.');
    }
}
