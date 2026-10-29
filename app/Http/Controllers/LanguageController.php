<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, SetLocale::LOCALES), 404);
        $request->session()->put('locale', $locale);

        // Go back to the page the student was on, but never to another site.
        $previous = url()->previous();
        $back = str_starts_with($previous, $request->getSchemeAndHttpHost()) ? $previous : url('/');

        return redirect()->to($back)->withCookie(cookie('locale', $locale, 60 * 24 * 365));
    }
}
