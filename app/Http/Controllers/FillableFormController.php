<?php

namespace App\Http\Controllers;

use App\Support\FillableForms;

class FillableFormController extends Controller
{
    public function index()
    {
        $forms = FillableForms::all();

        return view('forms.index', [
            'forms' => $forms,
            'formsByCategory' => $forms->groupBy(fn (array $form) => (string) ($form['category'] ?? 'Forms')),
        ]);
    }

    public function show(string $form)
    {
        $entry = FillableForms::find($form);
        abort_unless($entry !== null, 404);

        return view('forms.show', [
            'form' => $entry,
            'available' => FillableForms::filePath($entry) !== null,
        ]);
    }
}
