<?php
namespace App\Http\Controllers;

use App\Category;
use App\Product;
use App\Ingredient;
use App\ProductIngredient;
use Illuminate\Http\Request;
use App\ProductOptionalIngredient;
use App\ProductDiscount;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('Category')->get();
        return view('pages.products.index', compact('products'));
    }



//    public function create()
//    {
//        $categories = Category::all(); // Fetch categories for the dropdown
//        $ingredients = Ingredient::all(); // Fetch all ingredients for selection in the form
//        return view('pages.products.create', compact('categories', 'ingredients')); // Pass data to the view
//    }
    public function create()
    {
        $categories = Category::all(); // Fetch categories for the dropdown
        $ingredients = Ingredient::all(); // Fetch all ingredients for selection in the form
        $products = Product::all(); // Fetch all products for discounted product selection

        return view('pages.products.create', compact('categories', 'ingredients', 'products')); // Pass data to the view
    }

    public function edit($id)
    {
        $product = Product::with(['productIngredients', 'ProductDiscount'])->findOrFail($id);
        $categories = Category::all();
        $ingredients = Ingredient::all();
        $products = Product::all(); // Fetch all products for discounted product selection

        return view('pages.products.edit', compact('product', 'categories', 'ingredients', 'products'));
    }

    public function show(Product $product)
    {
        return view('pages.products.show', compact('product'));
    }

//
//    public function store(Request $request)
//    {
//        $validated = $request->validate([
//            'name' => 'required|string|max:255',
//            'sku' => 'required|string|unique:products,sku',
//            'category_id' => 'required|exists:categories,id',
//            'price' => 'required|numeric',
//            'ingredients' => 'required|array',
//            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
//            'ingredients.*.unit' => 'required|numeric',
//            'ingredients.*.type' => 'required|string|in:default,optional',
//            'ingredients.*.price' => 'nullable|numeric',
//        ]);
//
//        $product = Product::create([
//            'name' => $validated['name'],
//            'sku' => $validated['sku'],
//            'category_id' => $validated['category_id'],
//            'price' => $validated['price'],
//        ]);
//
//        $totalCost = 0;
//
//        foreach ($validated['ingredients'] as $ingredientData) {
//            $ingredient = Ingredient::find($ingredientData['ingredient_id']);
//            // Ensure 'cost' is defined and used correctly
//            $cost = $ingredient ? $ingredient->cost * $ingredientData['unit'] : 0;
//            $totalCost += $cost;
//
//            $isOptional = $ingredientData['type'] === 'optional';
//            $price = $isOptional ? $ingredientData['price'] : $cost;
//
//            ProductIngredient::create([
//                'product_id' => $product->id,
//                'ingredient_id' => $ingredientData['ingredient_id'],
//                'unit' => $ingredientData['unit'],
//                'is_optional' => $isOptional,
//                'price' => $price,
//            ]);
//        }
//
//        // Update product's total cost
//        $product->update(['total_cost' => $totalCost]);
//
//        return redirect()->route('products.index')->with('success', 'Product created successfully');
//    }
//
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'description'=> 'required|string|max:255',
            'main_ingredients' => 'required|array',
            'main_ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'main_ingredients.*.unit' => 'required|numeric',

            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', // Validation for image

        ], [
            'sku.unique' => 'The SKU must be unique. Please choose a different one.', // Custom message for unique validation
        ]);
        // Handle image upload
        if ($request->hasFile('image')) {
            $imageName = time() . '.' . $request->image->extension();  // Unique file name
            $request->image->move(public_path('assets/images/products'), $imageName);  // Save to custom path
        } else {
            $imageName = null;  // No image uploaded
        }
        $organizationId = org_id();


         $product = Product::create([
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'category_id' => $validated['category_id'],
            'price' => $validated['price'],
            'description'=> $validated['description'],
            'organization_id'=>$organizationId,
            'image' => $imageName, // Store the image path

        ]);

//        // Store main ingredients
        foreach ($validated['main_ingredients'] as $ingredientData) {
            ProductIngredient::create([
                'product_id' => $product->id,
                'ingredient_id' => $ingredientData['ingredient_id'],
                'unit' => $ingredientData['unit'],
                'is_optional' => false,
            ]);
        }
//
//        // Store optional ingredients
        if (!empty($validated['optional_ingredients'])) {
            foreach ($validated['optional_ingredients'] as $optionalIngredientData) {
                ProductOptionalIngredient::create([
                    'product_id' => $product->id,
                    'ingredient_id' => $optionalIngredientData['ingredient_id'],
                    'unit' => $optionalIngredientData['unit'],
                    'price' => $optionalIngredientData['price'],
                ]);
            }
        }
//
//        // Store discounts
        if (!empty($validated['discounts'])) {
            foreach ($validated['discounts'] as $discountData) {
                ProductDiscount::create([
                    'product_id' => $product->id,
                    'discounted_product_id' => $discountData['discounted_product_id'],
                    'new_price' => $discountData['new_price'],
                ]);
            }
        }

        return redirect()->route('products.index')->with('success', 'Product created successfully.');
    }

    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|unique:products,sku,' . $product->id,
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'description'=> 'required|string|max:255',
            'main_ingredients' => 'required|array',
            'main_ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'main_ingredients.*.unit' => 'required|numeric',
            'optional_ingredients' => 'nullable|array',
            'optional_ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
            'optional_ingredients.*.unit' => 'required|numeric',
            'optional_ingredients.*.price' => 'required|numeric',
            'discounts' => 'nullable|array',
            'discounts.*.discounted_product_id' => 'required|exists:products,id',
            'discounts.*.new_price' => 'required|numeric',
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', // Validation for image

        ]);
        if ($request->hasFile('image')) {
            // Delete old image if it exists
            if ($product->image) {
                $oldImagePath = public_path('assets/images/products/' . $product->image);
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }

            // Upload new image
            $imageName = time() . '.' . $request->image->extension();
            $request->image->move(public_path('assets/images/products'), $imageName);
        } else {
            $imageName = $product->image;  // Keep existing image
        }

        try {
            // Update product details
            $product->update([
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'category_id' => $validated['category_id'],
                'price' => $validated['price'],
                'description'=> $validated['description'],
                'image' => $imageName, // Save the image path to the database


            ]);

            // Handle main ingredients
            $product->productIngredients()->delete();
            foreach ($validated['main_ingredients'] as $ingredientData) {
                ProductIngredient::create([
                    'product_id' => $product->id,
                    'ingredient_id' => $ingredientData['ingredient_id'],
                    'unit' => $ingredientData['unit'],
                    'is_optional' => false,
                ]);
            }

            // Handle optional ingredients
            $product->ProductOptionalIngredients()->delete();
            if (!empty($validated['optional_ingredients'])) {
                foreach ($validated['optional_ingredients'] as $optionalIngredientData) {
                    ProductOptionalIngredient::create([
                        'product_id' => $product->id,
                        'ingredient_id' => $optionalIngredientData['ingredient_id'],
                        'unit' => $optionalIngredientData['unit'],
                        'price' => $optionalIngredientData['price'],
                    ]);
                }
            }

            // Handle discounts
            $product->ProductDiscount()->delete();
            if (!empty($validated['discounts'])) {
                foreach ($validated['discounts'] as $discountData) {
                    ProductDiscount::create([
                        'product_id' => $product->id,
                        'discounted_product_id' => $discountData['discounted_product_id'],
                        'new_price' => $discountData['new_price'],
                    ]);
                }
            }

            return redirect()->route('products.index')->with('success', 'Product updated successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'An error occurred: ' . $e->getMessage()]);
        }
                //  return redirect()->route('products.index')->with('success', 'Product updated successfully.');

    }



//    public function update(Request $request, Product $product)
//    {
//        $validated = $request->validate([
//            'name' => 'required|string|max:255',
//            'sku' => 'required|string|unique:products,sku,' . $product->id,
//            'category_id' => 'required|exists:categories,id',
//            'price' => 'required|numeric',
//            'ingredients' => 'required|array',
//            'ingredients.*.ingredient_id' => 'required|exists:ingredients,id',
//            'ingredients.*.unit' => 'required|numeric',
//            'ingredients.*.type' => 'required|string|in:default,optional',
//            'ingredients.*.price' => 'nullable|numeric',
//        ]);
//
//        // Update the product's basic details
//        $product->update([
//            'name' => $validated['name'],
//            'sku' => $validated['sku'],
//            'category_id' => $validated['category_id'],
//            'price' => $validated['price'],
//        ]);
//         // Remove existing ingredients
//        $product->productIngredients()->delete();
//
//        // Add new ingredients
//        $totalCost = 0;
//        foreach ($validated['ingredients'] as $ingredientData) {
//            $ingredient = Ingredient::find($ingredientData['ingredient_id']);
//            $cost = $ingredient ? $ingredient->cost * $ingredientData['unit'] : 0;
//            $totalCost += $cost;
//
//            $isOptional = $ingredientData['type'] === 'optional';
//            $price = $isOptional ? $ingredientData['price'] : $cost;
//
//            ProductIngredient::create([
//                'product_id' => $product->id,
//                'ingredient_id' => $ingredientData['ingredient_id'],
//                'unit' => $ingredientData['unit'],
//                'is_optional' => $isOptional,
//                'price' => $price,
//            ]);
//        }
//
//        // Update product's total cost
//        $product->update(['total_cost' => $totalCost]);
//
//        return redirect()->route('products.index')->with('success', 'Product updated successfully');
//    }



    public function destroy(Product $product)
    {
        $product->delete();
        return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
    }

}
