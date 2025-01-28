<?php
namespace App\Http\Controllers;

use App\IngredientCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientCategoryController extends Controller
{
    public function index()
    {
        $categories = IngredientCategory::where('organization_id', org_id())->get();
        return view('pages.ingredient_categories.index', compact('categories'));
    }

    public function create()
    {
        return view('pages.ingredient_categories.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                Rule::unique('ingredient_categories')->where(function ($query) use ($request) {
                    return $query->where('organization_id', org_id());
                }),
            ],
        ]);
        $organizationId = org_id();
        $validated['organization_id'] = $organizationId;
        IngredientCategory::create($validated);

        return redirect()->route('ingredient_categories.index')->with('success', 'Ingredient Category created successfully');
    }

    public function show(IngredientCategory $ingredientCategory)
    {
        return view('pages.ingredient_categories.show', compact('ingredientCategory'));
    }

    public function edit(IngredientCategory $ingredientCategory)
    {
        return view('pages.ingredient_categories.edit', compact('ingredientCategory'));
    }

    public function update(Request $request, IngredientCategory $ingredientCategory)
    {
        $validated = $request->validate([
//            'name' => 'required|string|max:255|unique:ingredient_categories,name,' . $ingredientCategory->id,
            'name' => [
                'required',
                Rule::unique('ingredient_categories')->where(function ($query) use ($request) {
                    return $query->where('organization_id', org_id());
                })->ignore($ingredientCategory->id),
            ],
        ]);

        $ingredientCategory->update($validated);

        return redirect()->route('ingredient_categories.index')->with('success', 'Ingredient Category updated successfully');
    }

    public function destroy($id)
    {
        try {
            $category = IngredientCategory::findOrFail($id);

            // Check if there are related ingredients before deleting
            if ($category->ingredients()->count() > 0) {
                // Optionally, you can throw a custom exception or handle the error
                return redirect()->route('ingredient_categories.index')->with('error', 'Cannot delete category because it has associated ingredients.');
            }

            $category->delete();

            return redirect()->route('ingredient_categories.index')->with('success', 'Ingredient Category deleted successfully.');

        } catch (\Exception $e) {
            // Handle general exceptions and return an error response
            return response()->json(['error' => 'An unexpected error occurred. Please try again later.'], 500);
        }
    }
}
