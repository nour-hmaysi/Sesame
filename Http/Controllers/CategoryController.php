<?php
namespace App\Http\Controllers;

use App\Category;
use App\Product;
 use Illuminate\Http\Request;

class CategoryController extends Controller
{

    public function index()
    {
        // Fetch categories with products for the current organization
        $categories = Category::with(['products' => function ($query) {
            $query->where('organization_id', org_id());
        }])
            ->where('organization_id', org_id())
            ->get();

        return view('pages.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('pages.categories.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);
        $organizationId = org_id();

        $validated['organization_id'] = $organizationId;

        Category::create($validated);

        return redirect()->route('categories.index')->with('success', 'Category created successfully');
    }

    public function show(Category $category)
    {
        $category->load('products');
        return view('pages.categories.show', compact('category'));
    }

    public function edit(Category $category)
    {
        return view('pages.categories.edit', compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => 'nullable|string',
        ]);


        $category->update($validated);

        return redirect()->route('categories.index')->with('success', 'Category updated successfully');
    }

    public function destroy(Category $category)
    {
        // Check if the category has products before deleting
        if ($category->products()->count()) {
            return redirect()->route('categories.index')->with('error', 'Category cannot be deleted because it has products');
        }

        $category->delete();

        return redirect()->route('categories.index')->with('success', 'Category deleted successfully');
    }
}

