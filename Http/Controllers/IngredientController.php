<?php

namespace App\Http\Controllers;

use App\Ingredient;
use App\IngredientCategory;
use App\Item;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IngredientController extends Controller
{
    /**
     * Display a listing of the ingredients.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $ingredients = Ingredient::with(['ingredientCategory'])->get();
        return view('pages.ingredients.index', compact('ingredients'));
    }

    /**
     * Show the form for creating a new ingredient.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $ingredientCategories = IngredientCategory::all();
        return view('pages.ingredients.create', compact('ingredientCategories'));
    }

    /**
     * Store a newly created ingredient in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'ingredient_category_id' => 'required|exists:ingredient_categories,id',
                'sku' => [
                    'required',
                    Rule::unique('ingredients')->where(function ($query) use ($request) {
                        return $query->where('organization_id', org_id());
                    }),
                ],
//            'costing_method' => 'required|string',
//            'factor' => 'required|numeric',
            'barcode' => 'nullable|string',
//            'storage_unit' => 'required|string',
//            'ingredient_unit' => 'required|string',
//            'cost_of_one_unit' => 'nullable|numeric',
            ]);
            $organizationId = org_id();
            $validated['organization_id'] = $organizationId;

            Ingredient::create($validated);
            if ($request->ajax()) {
                return response()->json(['success' => true]);
            } else {
                return redirect()->route('ingredients.index')->with('success', 'Ingredient created successfully');
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            if ($request->ajax()) {
                // If it's an AJAX request, return the validation errors in JSON format
                return response()->json(['success' => false, 'errors' => $e->validator->errors()]);
            } else {
                // If it's a normal request, redirect back with validation errors
                return redirect()->back()->withErrors($e->validator)->withInput();
            }
        }
    }

    /**
     * Display the specified ingredient.
     *
     * @param  \App\Ingredient  $ingredient
     * @return \Illuminate\Http\Response
     */
    public function show(Ingredient $ingredient)
    {
        $ingredient->load('ingredientStocks', 'ingredientCategory');
        return view('pages.ingredients.show', compact('ingredient'));
    }

    /**
     * Show the form for editing the specified ingredient.
     *
     * @param  \App\Ingredient  $ingredient
     * @return \Illuminate\Http\Response
     */
    public function edit(Ingredient $ingredient)
    {
        $ingredientCategories = IngredientCategory::all();
        return view('pages.ingredients.edit', compact('ingredient', 'ingredientCategories'));
    }

    /**
     * Update the specified ingredient in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Ingredient  $ingredient
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Ingredient $ingredient)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
             'ingredient_category_id' => 'required|exists:ingredient_categories,id',
            'sku' => [
                'required',
                Rule::unique('ingredients')->where(function ($query) use ($request) {
                    return $query->where('organization_id', org_id());
                })->ignore($ingredient->id),
            ],
//            'costing_method' => 'required|string',
//            'factor' => 'required|numeric',
            'barcode' => 'nullable|string',
//            'storage_unit' => 'required|string',
//            'ingredient_unit' => 'required|string',
//            'cost_of_one_unit' => 'nullable|numeric',
        ]);

        $ingredient->update($validated);

        return redirect()->route('ingredients.index')->with('success', 'Ingredient updated successfully');
    }

    /**
     * Remove the specified ingredient from storage.
     *
     * @param  \App\Ingredient  $ingredient
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $ingredient = Ingredient::findOrFail($id);

            // Check if there are related ingredients before deleting
            if ($ingredient->ingredientStocksDetails()->count() > 0) {
                // Optionally, you can throw a custom exception or handle the error
                return redirect()->route('ingredients.index')->with('error', 'Cannot delete ingredient because it is used in inventory.');
            }

            $ingredient->delete();

            return redirect()->route('ingredients.index')->with('success', 'Ingredient deleted successfully.');

        } catch (\Exception $e) {
            // Handle general exceptions and return an error response
            return response()->json(['error' => 'An unexpected error occurred. Please try again later.'], 500);
        }
//        $ingredient->delete();
//        return redirect()->route('ingredients.index')->with('success', 'Ingredient deleted successfully');
    }
    public function listItems(){
        $organizationId = org_id();
        $items = Ingredient::where('organization_id', $organizationId)
            ->orderBy('id', 'asc')
            ->get();

        $lastItem = $items->last();

        $content = '';
        foreach($items as $item){
            $selected = ($item->id === $lastItem->id) ? 'selected' : ''; // Check if it's the last item
            $content .= '<option value="'.$item->id.'" '.$selected.'>'. $item->name.'</option>';
        }
        $content .= '<option value="add-new"  data-target-btn="add-ing">Add new ingredient</option>';

        return response()->json([
            'options' => $content,
        ]);
    }
}
