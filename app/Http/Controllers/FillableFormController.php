<?php

namespace App\Http\Controllers;

use App\Support\FillableForms;

class FillableFormController extends Controller
{
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
